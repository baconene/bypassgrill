<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialTransactionController extends Controller {
    public function index(Request $request): JsonResponse {
        $this->checkReports();
        $includeAssetDeductions = $request->boolean('include_asset_deductions', true);

        $noAssetDeductions = fn ($q) => $q->where('type', '!=', 'asset_deduction');

        $openingBalance = 0.0;
        if ($request->start_date) {
            $openingBalance = (float) (FinancialTransaction::where('type', '!=', 'order')
                ->when(! $includeAssetDeductions, $noAssetDeductions)
                ->whereDate('transacted_at', '<', $request->start_date)
                ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
                ->value('bal') ?? 0);
        }

        $periodTx = FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
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

        $q = FinancialTransaction::with(['order', 'tender', 'user'])
            ->where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id');

        if ($request->type)               $q->where('type', $request->type);
        if ($request->start_date)         $q->whereDate('transacted_at', '>=', $request->start_date);
        if ($request->end_date)           $q->whereDate('transacted_at', '<=', $request->end_date);
        if ($request->payment_tender_id)  $q->where('payment_tender_id', $request->payment_tender_id);

        $paginated = $q->paginate(20);
        $paginated->getCollection()->transform(function ($tx) use ($balMap) {
            $tx->financial_balance = $balMap[$tx->id] ?? null;
            return $tx;
        });

        return response()->json($paginated);
    }

    public function summary(Request $request): JsonResponse {
        $this->checkReports();
        $start                  = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->startOfDay();
        $end                    = $request->end_date   ? Carbon::parse($request->end_date)->endOfDay()     : Carbon::today()->endOfDay();
        $includeAssetDeductions = $request->boolean('include_asset_deductions', true);

        $noAssetDeductions = fn ($q) => $q->where('type', '!=', 'asset_deduction');

        $rows = FinancialTransaction::selectRaw('type, SUM(amount) as total, COUNT(*) as count')
            ->whereBetween('transacted_at', [$start, $end])
            ->where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->groupBy('type')
            ->get()
            ->keyBy('type');

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

        $netByTender = FinancialTransaction::whereBetween('transacted_at', [$start, $end])
            ->where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->with('tender')
            ->selectRaw("payment_tender_id,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type IN ('expense','payroll','asset_deduction','payout_share') THEN amount ELSE 0 END) as total_out,
                COUNT(*) as cnt")
            ->groupBy('payment_tender_id')
            ->get()
            ->map(fn($r) => [
                'tender'    => $r->payment_tender_id ? ($r->tender?->name ?? 'Unknown') : 'Untagged',
                'total_in'  => round((float) $r->total_in,  2),
                'total_out' => round((float) $r->total_out, 2),
                'net'       => round((float) $r->total_in - (float) $r->total_out, 2),
                'count'     => (int) $r->cnt,
            ])
            ->sortByDesc('net')
            ->values();

        $incomeAdj       = (float)($rows['income_adjustment']?->total ?? 0);
        $expenses        = (float)($rows['expense']?->total ?? 0);
        $payments        = (float)($rows['payment']?->total ?? 0);
        $payroll         = (float)($rows['payroll']?->total ?? 0);
        $assetDeductions = (float)($rows['asset_deduction']?->total ?? 0);
        $payoutShare     = (float)($rows['payout_share']?->total ?? 0);

        $balanceAsOfEnd = (float) (FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->whereDate('transacted_at', '<=', $end->toDateString())
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
            ->value('bal') ?? 0.0);

        // Balance brought forward: everything before the period, so opening + net = closing.
        $openingBalance = (float) (FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->whereDate('transacted_at', '<', $start->toDateString())
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
            ->value('bal') ?? 0.0);

        // Running balance per tender as of end date (cumulative, not period-only).
        $balByTender = FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, $noAssetDeductions)
            ->whereDate('transacted_at', '<=', $end->toDateString())
            ->with('tender')
            ->selectRaw("payment_tender_id,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as balance,
                COUNT(*) as cnt")
            ->groupBy('payment_tender_id')
            ->get()
            ->map(fn ($r) => [
                'tender'  => $r->payment_tender_id ? ($r->tender?->name ?? 'Unknown') : 'Untagged',
                'balance' => round((float) $r->balance, 2),
                'count'   => (int) $r->cnt,
            ])
            ->sortByDesc('balance')
            ->values();

        return response()->json([
            'period'                  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'payments'                => ['total' => $payments,         'count' => (int) ($rows['payment']?->count          ?? 0)],
            'expenses'                => ['total' => $expenses,         'count' => (int) ($rows['expense']?->count          ?? 0)],
            'income_adjustments'      => ['total' => $incomeAdj,        'count' => (int) ($rows['income_adjustment']?->count ?? 0)],
            'payroll'                 => ['total' => $payroll,          'count' => (int) ($rows['payroll']?->count          ?? 0)],
            'asset_deductions'        => ['total' => $assetDeductions,  'count' => (int) ($rows['asset_deduction']?->count  ?? 0)],
            'net'                     => $payments + $incomeAdj - $expenses - $payroll - $assetDeductions - $payoutShare,
            'payout_shares'           => ['total' => $payoutShare, 'count' => (int) ($rows['payout_share']?->count ?? 0)],
            'opening_balance'         => $openingBalance,
            'balance_as_of_end'       => $balanceAsOfEnd,
            'balance_by_tender'       => $balByTender,
            'by_tender'               => $byTender,
            'net_by_tender'           => $netByTender,
            'include_asset_deductions' => $includeAssetDeductions,
        ]);
    }

    /**
     * The selected period and the ones before it, back to back, with a balance carried forward:
     * each period's opening is the previous period's closing. A whole or month-to-date calendar
     * month steps by calendar months; any other range steps by its own length in days.
     */
    public function periods(Request $request): JsonResponse {
        $this->checkReports();
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'count'      => 'nullable|integer|min:2|max:12',
        ]);
        $includeAssetDeductions = $request->boolean('include_asset_deductions', true);
        $count = (int) ($data['count'] ?? 6);
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end   = Carbon::parse($data['end_date'])->startOfDay();

        $monthly = $start->day === 1 && $start->isSameMonth($end)
            && ($end->isSameDay($start->copy()->endOfMonth()) || $end->isToday());
        $days = (int) abs($start->diffInDays($end)) + 1;

        $ranges = [];
        for ($i = 0; $i < $count; $i++) {
            if ($monthly) {
                $s = $start->copy()->subMonthsNoOverflow($i);
                $e = $i === 0 ? $end->copy() : $s->copy()->endOfMonth()->startOfDay();
            } else {
                $e = $end->copy()->subDays($days * $i);
                $s = $e->copy()->subDays($days - 1);
            }
            $ranges[] = [$s, $e];
        }
        $ranges = array_reverse($ranges);

        $base = fn () => FinancialTransaction::where('type', '!=', 'order')
            ->when(! $includeAssetDeductions, fn ($q) => $q->where('type', '!=', 'asset_deduction'));

        $balance = round((float) ($base()
            ->whereDate('transacted_at', '<', $ranges[0][0]->toDateString())
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
            ->value('bal') ?? 0), 2);

        $rows = [];
        foreach ($ranges as $index => [$s, $e]) {
            $totals = $base()
                ->whereDate('transacted_at', '>=', $s->toDateString())
                ->whereDate('transacted_at', '<=', $e->toDateString())
                ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END), 0) as money_in,
                    COALESCE(SUM(CASE WHEN type IN ('payment','income_adjustment') THEN 0 ELSE amount END), 0) as money_out,
                    COUNT(*) as cnt")
                ->first();
            $in  = round((float) $totals->money_in, 2);
            $out = round((float) $totals->money_out, 2);
            $net = round($in - $out, 2);

            $rows[] = [
                'start'      => $s->toDateString(),
                'end'        => $e->toDateString(),
                'opening'    => $balance,
                'money_in'   => $in,
                'money_out'  => $out,
                'net'        => $net,
                'closing'    => round($balance + $net, 2),
                'count'      => (int) $totals->cnt,
                'is_current' => $index === count($ranges) - 1,
            ];
            $balance = round($balance + $net, 2);
        }

        return response()->json([
            'granularity' => $monthly ? 'month' : ($days === 1 ? 'day' : ($days === 7 ? 'week' : 'period')),
            'period_days' => $days,
            'rows'        => $rows,
        ]);
    }

    public function daily(Request $request): JsonResponse {
        $this->checkReports();
        $days  = min((int) $request->get('days', 30), 90);
        $end   = Carbon::today()->endOfDay();
        $start = Carbon::today()->subDays($days - 1)->startOfDay();

        // All-time running balance up to the day before this window
        $openingBalance = (float) (FinancialTransaction::where('type', '!=', 'order')
            ->whereDate('transacted_at', '<', $start->toDateString())
            ->selectRaw("SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as bal")
            ->value('bal') ?? 0);

        $rows = FinancialTransaction::where('type', '!=', 'order')
            ->whereBetween('transacted_at', [$start, $end])
            ->selectRaw("DATE(transacted_at) as date,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type IN ('expense','payroll','asset_deduction','payout_share') THEN amount ELSE 0 END) as expense,
                SUM(CASE WHEN type IN ('payment','income_adjustment') THEN amount ELSE -amount END) as net_change")
            ->groupByRaw('DATE(transacted_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result  = [];
        $balance = $openingBalance;
        for ($i = $days - 1; $i >= 0; $i--) {
            $date    = Carbon::today()->subDays($i)->toDateString();
            $row     = $rows->get($date);
            if ($row) {
                $balance = round($balance + (float) $row->net_change, 2);
            }
            $result[] = [
                'date'    => $date,
                'income'  => $row ? round((float) $row->income,  2) : 0.0,
                'expense' => $row ? round((float) $row->expense, 2) : 0.0,
                'balance' => $balance,
            ];
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse {
        if (! auth()->user()?->hasAnyRole('admin', 'auditor')) abort(403);
        \Log::info('💾 POST /financial-transactions received', ['transacted_at_raw' => $request->input('transacted_at')]);

        $data = $request->validate([
            'type'               => 'required|in:expense,income_adjustment,asset_deduction',
            'amount'             => 'required|numeric|min:0.01',
            'description'        => 'required|string|max:255',
            'notes'              => 'nullable|string',
            'transacted_at'      => 'nullable|date_format:Y-m-d\\TH:i',
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
            'type'              => 'sometimes|in:expense,income_adjustment,asset_deduction,payroll,payout_share',
            'amount'            => 'sometimes|numeric|min:0.01',
            'description'       => 'sometimes|string|max:255',
            'notes'             => 'nullable|string',
            'transacted_at'     => 'sometimes|date_format:Y-m-d\\TH:i',
            'payment_tender_id' => 'nullable|exists:payment_tenders,id',
        ]);

        $financialTransaction->fill($data)->save();

        return response()->json($financialTransaction->fresh()->load(['tender', 'user']));
    }

    public function destroy(FinancialTransaction $financialTransaction, OrderService $orderService): JsonResponse {
        $user = auth()->user();

        if (! $user?->hasAnyRole('admin', 'auditor')) abort(403);

        if (! $user?->hasRole('admin') && ! in_array($financialTransaction->type, ['expense', 'income_adjustment'])) {
            abort(422, 'Only manually created entries can be deleted.');
        }

        // Order and payment entries belong to an order: remove the order with all its entries
        $order = in_array($financialTransaction->type, ['order', 'payment']) ? $financialTransaction->order : null;

        if ($order) {
            $orderService->deleteOrder($order);
        } else {
            $financialTransaction->delete();
        }

        return response()->json(null, 204);
    }

    private function checkReports(): void {
        if (! auth()->user()?->hasAnyRole('admin') && ! auth()->user()?->hasPermissionTo('view reports')) {
            abort(403);
        }
    }
}
