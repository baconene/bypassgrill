<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialTransactionController extends Controller {
    public function index(Request $request): JsonResponse {
        $this->checkReports();
        $includeCogs = $request->boolean('include_cogs', true);

        $noCogs = fn ($q) => $q->where(fn ($inner) =>
            $inner->where('type', '!=', 'expense')
                  ->orWhere(fn ($q2) => $q2->where('type', 'expense')->where('description', 'not like', 'COGS:%'))
        );

        // Opening financial balance: sum of all visible financial tx BEFORE the filtered period.
        // This anchors the running balance correctly even when a date range is applied.
        $openingBalance = 0.0;
        if ($request->start_date) {
            $openingBalance = (float) (FinancialTransaction::where('type', '!=', 'order')
                ->when(! $includeCogs, $noAssetDeductions)
                ->whereDate('transacted_at', '<', $request->start_date)
                ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
                ->value('bal') ?? 0);
        }

        // Compute financial_balance for every visible tx in the period (chronologically, no type filter —
        // the balance reflects the full financial picture regardless of which type the user is filtering).
        $periodTx = FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeCogs, $noAssetDeductions)
            ->when($request->start_date, fn ($q) => $q->whereDate('transacted_at', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->whereDate('transacted_at', '<=', $request->end_date))
            ->orderBy('transacted_at')->orderBy('id')
            ->select(['id', 'type', 'amount'])
            ->get();

        $bal    = $openingBalance;
        $balMap = [];
        foreach ($periodTx as $tx) {
            $bal = round($bal + (in_array($tx->type, ['payment', 'income_adjustment'])
                ? (float) $tx->amount : -(float) $tx->amount), 2);
            $balMap[$tx->id] = $bal;
        }

        // Paginated display query — type filter applies here but not to the balance map above.
        $q = FinancialTransaction::with(['order', 'tender', 'user'])
            ->where('type', '!=', 'order')
            ->when(! $includeCogs, $noAssetDeductions)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id');

        if ($request->type)       $q->where('type', $request->type);
        if ($request->start_date) $q->whereDate('transacted_at', '>=', $request->start_date);
        if ($request->end_date)   $q->whereDate('transacted_at', '<=', $request->end_date);

        $paginated = $q->paginate(20);
        $paginated->getCollection()->transform(function ($tx) use ($balMap) {
            $tx->financial_balance = $balMap[$tx->id] ?? null;
            return $tx;
        });

        return response()->json($paginated);
    }

    public function summary(Request $request): JsonResponse {
        $this->checkReports();
        $start       = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->startOfDay();
        $end         = $request->end_date   ? Carbon::parse($request->end_date)->endOfDay()     : Carbon::today()->endOfDay();
        $includeCogs = $request->boolean('include_cogs', true);

        // Reusable scope to strip asset deduction rows when the toggle is off
        $noAssetDeductions = fn ($q) => $q->where('type', '!=', 'asset_deduction');

        $rows = FinancialTransaction::selectRaw('type, SUM(amount) as total, COUNT(*) as count')
            ->whereBetween('transacted_at', [$start, $end])
            ->where('type', '!=', 'order')
            ->when(! $includeCogs, $noAssetDeductions)
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $cogsInPeriod = FinancialTransaction::where('type', 'expense')
            ->where('description', 'like', 'COGS:%')
            ->whereBetween('transacted_at', [$start, $end])
            ->sum('amount');
        \Log::info('💾 COGS in period', ['total' => $cogsInPeriod, 'include_cogs' => $includeCogs]);

        // Payments by tender (payment credits only)
        $byTender = FinancialTransaction::where('type', 'payment')
            ->whereBetween('transacted_at', [$start, $end])
            ->with('tender')
            ->selectRaw('payment_tender_id, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('payment_tender_id')
            ->get()
            ->map(fn($r) => [
                'tender' => $r->tender?->name ?? 'Unknown',
                'total'  => (float) $r->total,
                'count'  => $r->count,
            ]);

        // Net per tender — excluding raw order debits; respects COGS toggle
        $netByTender = FinancialTransaction::whereBetween('transacted_at', [$start, $end])
            ->whereNotNull('payment_tender_id')
            ->where('type', '!=', 'order')
            ->when(! $includeCogs, $noAssetDeductions)
            ->with('tender')
            ->selectRaw("payment_tender_id,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type IN ('expense','payroll')           THEN amount ELSE 0 END) as total_out,
                COUNT(*) as cnt")
            ->groupBy('payment_tender_id')
            ->get()
            ->map(fn($r) => [
                'tender'    => $r->tender?->name ?? 'Unknown',
                'total_in'  => round((float) $r->total_in,  2),
                'total_out' => round((float) $r->total_out, 2),
                'net'       => round((float) $r->total_in - (float) $r->total_out, 2),
                'count'     => (int) $r->cnt,
            ])
            ->values();

        $incomeAdj = (float)($rows['income_adjustment']?->total ?? 0);
        $expenses  = (float)($rows['expense']?->total ?? 0);
        $payments  = (float)($rows['payment']?->total ?? 0);
        $payroll   = (float)($rows['payroll']?->total ?? 0);

        // Balance as of end date: cumulative sum of non-order transactions up to end date,
        // using the same logic as the financial_balance column in the transaction list.
        $balanceAsOfEnd = (float) (FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeCogs, $noAssetDeductions)
            ->whereDate('transacted_at', '<=', $end->toDateString())
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
            ->value('bal') ?? 0.0);

        return response()->json([
            'period'             => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'payments'           => ['total' => $payments,   'count' => $rows['payment']?->count  ?? 0],
            'expenses'           => ['total' => $expenses,   'count' => $rows['expense']?->count  ?? 0],
            'income_adjustments' => ['total' => $incomeAdj,  'count' => $rows['income_adjustment']?->count ?? 0],
            'payroll'            => ['total' => $payroll,    'count' => $rows['payroll']?->count  ?? 0],
            'net'                => $payments + $incomeAdj - $expenses - $payroll,
            'balance_as_of_end'  => $balanceAsOfEnd,
            'by_tender'          => $byTender,
            'net_by_tender'      => $netByTender,
            'include_cogs'       => $includeCogs,
        ]);
    }

    public function store(Request $request): JsonResponse {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) abort(403);
        \Log::info('💾 POST /financial-transactions received', ['transacted_at_raw' => $request->input('transacted_at')]);

        $data = $request->validate([
            'type'               => 'required|in:expense,income_adjustment,asset_deduction',
            'amount'             => 'required|numeric|min:0.01',
            'description'        => 'required|string|max:255',
            'notes'              => 'nullable|string',
            'transacted_at'      => 'nullable|date_format:Y-m-d\TH:i',
            'payment_tender_id'  => 'nullable|exists:payment_tenders,id',
        ]);

        \Log::info('💾 After validation', ['transacted_at' => $data['transacted_at'] ?? 'null', 'now' => now()->toDateTimeString()]);

        $tx = FinancialTransaction::create([
            'type'               => $data['type'],
            'amount'             => $data['amount'],
            'description'        => $data['description'],
            'notes'              => $data['notes'] ?? null,
            'user_id'            => auth()->id(),
            'transacted_at'      => $data['transacted_at'] ?? now(),
            'payment_tender_id'  => $data['payment_tender_id'] ?? null,
            // running_balance is computed automatically by FinancialTransaction::boot()
        ]);
        \Log::info('💾 Created transaction', ['id' => $tx->id, 'transacted_at' => $tx->transacted_at->toDateTimeString()]);
        return response()->json($tx, 201);
    }

    public function update(Request $request, FinancialTransaction $financialTransaction): JsonResponse {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) abort(403);

        if ($financialTransaction->type === 'order') {
            abort(422, 'Order records cannot be edited.');
        }

        $data = $request->validate([
            'amount'            => 'sometimes|numeric|min:0.01',
            'description'       => 'sometimes|string|max:255',
            'notes'             => 'nullable|string',
            'transacted_at'     => 'sometimes|date_format:Y-m-d\TH:i',
            'payment_tender_id' => 'nullable|exists:payment_tenders,id',
        ]);

        $oldAmount = (float) $financialTransaction->amount;
        $oldAt     = $financialTransaction->transacted_at->copy();

        $financialTransaction->fill($data)->saveQuietly();
        $financialTransaction->refresh();

        $newAmount = (float) $financialTransaction->amount;
        $newAt     = $financialTransaction->transacted_at;

        if (abs($newAmount - $oldAmount) > 0.001 || ! $oldAt->eq($newAt)) {
            // Recalculate running_balance from the earliest affected date forward
            $recalcFrom = $oldAt->lt($newAt) ? $oldAt : $newAt;

            $base = (float) (FinancialTransaction::where('transacted_at', '<', $recalcFrom)
                ->orderByDesc('transacted_at')->orderByDesc('id')
                ->value('running_balance') ?? 0.0);

            FinancialTransaction::where('transacted_at', '>=', $recalcFrom)
                ->orderBy('transacted_at')->orderBy('id')
                ->each(function (FinancialTransaction $tx) use (&$base) {
                    $base = round($base + match ($tx->type) {
                        'payment', 'income_adjustment' => (float) $tx->amount,
                        'expense', 'order', 'payroll'  => -(float) $tx->amount,
                        default                        => 0.0,
                    }, 2);
                    if ((float) $tx->running_balance !== $base) {
                        $tx->running_balance = $base;
                        $tx->saveQuietly();
                    }
                });
        }

        return response()->json($financialTransaction->fresh()->load(['tender', 'user']));
    }

    public function destroy(FinancialTransaction $financialTransaction): JsonResponse {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) abort(403);

        if (! in_array($financialTransaction->type, ['expense', 'income_adjustment'])) {
            abort(422, 'Only manually created entries can be deleted.');
        }

        $deletedAt  = $financialTransaction->transacted_at;
        $deletedId  = $financialTransaction->id;
        $signedAmt  = $financialTransaction->type === 'expense'
            ? (float) $financialTransaction->amount
            : -(float) $financialTransaction->amount;

        $financialTransaction->delete();

        // Recalculate running_balance for every transaction after the deleted one
        FinancialTransaction::where(function ($q) use ($deletedAt, $deletedId) {
                $q->where('transacted_at', '>', $deletedAt)
                  ->orWhere(fn ($q2) => $q2->where('transacted_at', $deletedAt)->where('id', '>', $deletedId));
            })
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->each(function (FinancialTransaction $tx) use ($signedAmt) {
                $tx->running_balance = round((float) $tx->running_balance + $signedAmt, 2);
                $tx->saveQuietly();
            });

        return response()->json(null, 204);
    }

    private function checkReports(): void {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo('view reports')) {
            abort(403);
        }
    }
}
