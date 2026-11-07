<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountingSetting;
use App\Models\Bill;
use App\Models\BillInstallment;
use App\Models\FinancialTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    public function index(): JsonResponse
    {
        $this->checkAuth();
        $bills = Bill::with('installments')
            ->orderByRaw("CASE WHEN is_active = 0 THEN 1 ELSE 0 END, due_date")
            ->get()
            ->map(fn (Bill $b) => $this->format($b));
        return response()->json(['data' => $bills]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkAuth();
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string',
            'amount'            => 'required|numeric|min:0.01',
            'frequency'         => 'required|in:one_time,daily,weekly,bi_weekly,monthly,quarterly,semi_annual,annual',
            'due_date'          => 'required|date',
            'category'          => 'nullable|string|max:100',
            'is_installment'    => 'boolean',
            'installment_count' => 'required_if:is_installment,true|nullable|integer|min:2|max:360',
        ]);

        $bill = Bill::create([...$data, 'user_id' => auth()->id()]);

        if ($bill->is_installment) {
            $this->generateInstallments($bill);
            $bill->load('installments');
        }

        return response()->json(['data' => $this->format($bill)], 201);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        $this->checkAuth();
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'amount'      => 'sometimes|numeric|min:0.01',
            'frequency'   => 'sometimes|in:one_time,daily,weekly,bi_weekly,monthly,quarterly,semi_annual,annual',
            'due_date'    => 'sometimes|date',
            'category'    => 'nullable|string|max:100',
            'is_active'   => 'boolean',
        ]);

        $bill->update($data);
        return response()->json(['data' => $this->format($bill->fresh()->load('installments'))]);
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $this->checkAuth();
        $bill->delete(); // cascades to installments
        return response()->json(null, 204);
    }

    public function pay(Request $request, Bill $bill): JsonResponse
    {
        $this->checkAuth();

        if ($bill->is_installment) {
            return response()->json(['message' => 'Use the installment pay endpoint for payment-plan bills.'], 422);
        }

        if (!$bill->is_active) {
            return response()->json(['message' => 'This bill is no longer active.'], 422);
        }

        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'notes'  => 'nullable|string',
        ]);

        $paidAmount = isset($data['amount']) ? (float) $data['amount'] : (float) $bill->amount;

        DB::transaction(function () use ($bill, $paidAmount, $data) {
            FinancialTransaction::create([
                'type'          => 'expense',
                'amount'        => $paidAmount,
                'description'   => 'Bill: ' . $bill->name,
                'notes'         => $data['notes'] ?? sprintf(
                    '%s · Due %s · %s',
                    ucfirst(str_replace('_', '-', $bill->frequency)),
                    $bill->due_date->format('M d, Y'),
                    $bill->category ?? ''
                ),
                'user_id'       => auth()->id(),
                'transacted_at' => now(),
            ]);

            $next = $bill->nextDueDate();
            if ($next === null) {
                $bill->update(['is_active' => false, 'last_paid_at' => now()]);
            } else {
                $bill->update(['due_date' => $next->toDateString(), 'last_paid_at' => now()]);
            }
        });

        return response()->json(['data' => $this->format($bill->fresh()->load('installments'))]);
    }

    public function payInstallment(Request $request, Bill $bill, BillInstallment $installment): JsonResponse
    {
        $this->checkAuth();

        if ($installment->bill_id !== $bill->id) abort(404);

        if ($installment->paid_at) {
            return response()->json(['message' => 'This installment is already paid.'], 422);
        }

        $data = $request->validate(['notes' => 'nullable|string']);

        DB::transaction(function () use ($bill, $installment, $data) {
            $ft = FinancialTransaction::create([
                'type'          => 'expense',
                'amount'        => (float) $installment->amount,
                'description'   => "Bill: {$bill->name} — Installment {$installment->installment_number}/{$bill->installment_count}",
                'notes'         => $data['notes'] ?? sprintf(
                    'Due %s · %s',
                    $installment->due_date->format('M d, Y'),
                    $bill->category ?? ''
                ),
                'user_id'       => auth()->id(),
                'transacted_at' => now(),
            ]);

            $installment->update([
                'paid_at'                  => now(),
                'financial_transaction_id' => $ft->id,
            ]);

            $allPaid = $bill->installments()->whereNull('paid_at')->doesntExist();
            $bill->update([
                'last_paid_at' => now(),
                'is_active'    => !$allPaid,
            ]);
        });

        return response()->json(['data' => $this->format($bill->fresh()->load('installments'))]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->checkAuth();
        $today = Carbon::today();
        [$periodStart, $periodEnd] = AccountingSetting::periodContaining($today);
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : $periodStart;
        $endDate   = ($request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : $periodEnd)->copy()->endOfDay();

        $bills    = Bill::with('installments')->where('is_active', true)->get();
        $totalDue = 0.0;
        $overdue  = 0.0;
        $count    = 0;

        foreach ($bills as $bill) {
            if ($bill->is_installment) {
                foreach ($bill->installments->whereNull('paid_at') as $inst) {
                    $due = Carbon::parse($inst->due_date);
                    if ($due->between($startDate, $endDate)) {
                        $totalDue += (float) $inst->amount;
                        if ($due->lt($today)) $overdue += (float) $inst->amount;
                        $count++;
                    }
                }
            } else {
                $current = Carbon::parse($bill->due_date);
                // Advance until we reach the start of the date range
                while ($current->lt($startDate)) {
                    $next = $this->advance($current, $bill->frequency);
                    if ($next === null) { $current = null; break; }
                    $current = $next;
                }
                if ($current === null) continue;
                // Collect all occurrences within [startDate, endDate]
                while ($current !== null && $current->lte($endDate)) {
                    $totalDue += (float) $bill->amount;
                    if ($current->lt($today)) $overdue += (float) $bill->amount;
                    $count++;
                    $current = $this->advance($current, $bill->frequency);
                }
            }
        }

        return response()->json([
            'total_due' => round($totalDue, 2),
            'overdue'   => round($overdue, 2),
            'upcoming'  => round($totalDue - $overdue, 2),
            'count'     => $count,
            'period'    => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
        ]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $this->checkAuth();
        $months = min(max((int) $request->input('months', 3), 1), 12);
        $endDate = Carbon::today()->addMonths($months)->endOfMonth();

        $bills = Bill::with('installments')->where('is_active', true)->get();
        $entries = [];

        foreach ($bills as $bill) {
            if ($bill->is_installment) {
                foreach ($bill->installments->whereNull('paid_at') as $inst) {
                    $due = Carbon::parse($inst->due_date);
                    if ($due->lte($endDate)) {
                        $entries[] = [
                            'bill_id'            => $bill->id,
                            'installment_id'     => $inst->id,
                            'name'               => $bill->name,
                            'label'              => "#{$inst->installment_number}/{$bill->installment_count}",
                            'category'           => $bill->category,
                            'amount'             => (float) $inst->amount,
                            'due_date'           => $inst->due_date->toDateString(),
                            'frequency'          => $bill->frequency,
                            'is_installment'     => true,
                            'status'             => $inst->status(),
                        ];
                    }
                }
            } else {
                $current = Carbon::parse($bill->due_date);
                while ($current->lte($endDate)) {
                    $entries[] = [
                        'bill_id'        => $bill->id,
                        'installment_id' => null,
                        'name'           => $bill->name,
                        'label'          => null,
                        'category'       => $bill->category,
                        'amount'         => (float) $bill->amount,
                        'due_date'       => $current->toDateString(),
                        'frequency'      => $bill->frequency,
                        'is_installment' => false,
                        'status'         => ($current->isPast() && !$current->isToday()) ? 'overdue'
                            : ($current->isToday() ? 'due_today' : 'upcoming'),
                    ];
                    $next = $this->advance($current, $bill->frequency);
                    if ($next === null) break;
                    $current = $next;
                }
            }
        }

        usort($entries, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));

        $byMonth = [];
        foreach ($entries as $entry) {
            $byMonth[substr($entry['due_date'], 0, 7)][] = $entry;
        }

        return response()->json([
            'entries'        => $entries,
            'by_month'       => $byMonth,
            'total_forecast' => round(array_sum(array_column($entries, 'amount')), 2),
            'months'         => $months,
        ]);
    }

    private function generateInstallments(Bill $bill): void
    {
        $total      = (float) $bill->amount;
        $count      = (int) $bill->installment_count;
        $perInstall = round($total / $count, 2);
        $current    = Carbon::parse($bill->due_date);

        for ($i = 1; $i <= $count; $i++) {
            // Last installment absorbs any rounding difference
            $amt = ($i === $count) ? round($total - ($perInstall * ($count - 1)), 2) : $perInstall;
            BillInstallment::create([
                'bill_id'            => $bill->id,
                'installment_number' => $i,
                'amount'             => $amt,
                'due_date'           => $current->toDateString(),
            ]);
            if ($i < $count) {
                $next = $this->advance($current, $bill->frequency);
                if ($next === null) break;
                $current = $next;
            }
        }
    }

    private function advance(Carbon $date, string $frequency): ?Carbon
    {
        return match ($frequency) {
            'one_time'    => null,
            'daily'       => $date->copy()->addDay(),
            'weekly'      => $date->copy()->addWeek(),
            'bi_weekly'   => $date->copy()->addWeeks(2),
            'monthly'     => $date->copy()->addMonth(),
            'quarterly'   => $date->copy()->addMonths(3),
            'semi_annual' => $date->copy()->addMonths(6),
            'annual'      => $date->copy()->addYear(),
            default       => null,
        };
    }

    private function format(Bill $b): array
    {
        $installments = $b->is_installment
            ? ($b->relationLoaded('installments') ? $b->installments : $b->installments()->get())
                ->map(fn ($i) => [
                    'id'                 => $i->id,
                    'installment_number' => $i->installment_number,
                    'amount'             => (float) $i->amount,
                    'due_date'           => $i->due_date->toDateString(),
                    'paid_at'            => $i->paid_at?->toDateTimeString(),
                    'status'             => $i->status(),
                ])->values()
            : collect([]);

        $paidCount = $b->is_installment ? $installments->whereNotNull('paid_at')->count() : null;

        return [
            'id'                => $b->id,
            'name'              => $b->name,
            'description'       => $b->description,
            'amount'            => (float) $b->amount,
            'frequency'         => $b->frequency,
            'due_date'          => $b->due_date?->toDateString(),
            'category'          => $b->category,
            'is_active'         => (bool) $b->is_active,
            'is_installment'    => (bool) $b->is_installment,
            'installment_count' => $b->installment_count,
            'installments_paid' => $paidCount,
            'last_paid_at'      => $b->last_paid_at?->toDateTimeString(),
            'status'            => $b->status(),
            'installments'      => $installments,
        ];
    }

    private function checkAuth(): void
    {
        if (!auth()->user()?->hasAnyRole('admin', 'auditor')) abort(403);
    }
}
