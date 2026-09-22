<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DistributionSnapshot;
use App\Models\FinancialTransaction;
use App\Services\Distribution\ProfitDistributionService;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DistributionController extends Controller
{
    public function __construct(private ProfitDistributionService $service) {}

    public function preview(Request $request): JsonResponse
    {
        $this->adminOnly();
        [$basis, $start, $end, $cat, $prod, $sh] = $this->filters($request);

        return response()->json(
            $this->service->compute($basis, $start, $end, $cat, $prod, $sh)
        );
    }

    public function storeSnapshot(Request $request): JsonResponse
    {
        $this->adminOnly();
        [$basis, $start, $end, $cat, $prod, $sh] = $this->filters($request);

        abort_if($cat || $prod || $sh, 422, 'Clear filters before saving a payout snapshot. Filtered results are estimates only.');
        ProfitDistributionService::bumpCacheVersion();
        $result = $this->service->compute($basis, $start, $end, $cat, $prod, $sh);
        $snap = $this->service->snapshot($result, [
            'category_id' => $cat, 'product_id' => $prod, 'shareholder_id' => $sh,
        ]);

        AuditLogger::record('distribution.snapshot', $snap, null, [
            'period' => "$start..$end", 'basis' => $basis, 'distributable' => $result['distributable'],
        ], "Generated distribution snapshot for $start..$end");

        return response()->json($snap, 201);
    }

    public function snapshots(): JsonResponse
    {
        $this->adminOnly();

        return response()->json(
            DistributionSnapshot::with(['creator:id,name', 'payer:id,name'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
        );
    }

    public function showSnapshot(DistributionSnapshot $snapshot): JsonResponse
    {
        $this->adminOnly();

        return response()->json(
            $snapshot->load(['details.shareholder', 'creator:id,name', 'payer:id,name', 'payoutTransactions.tender'])
        );
    }

    public function recordPayout(Request $request, DistributionSnapshot $snapshot): JsonResponse
    {
        $this->adminOnly();

        if ($snapshot->isPaid()) {
            return response()->json(['message' => 'This snapshot has already been paid out.'], 422);
        }

        $request->validate([
            'tender_id' => 'required|exists:payment_tenders,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $tenderId = (int) $request->input('tender_id');
        $notes = $request->input('notes');
        $now = now();

        \DB::transaction(function () use ($snapshot, $tenderId, $notes, $now) {
            // Lock overlapping periods too: another saved copy must not pay the same earnings twice.
            $overlapping = DistributionSnapshot::where('period_start', '<=', $snapshot->period_end)
                ->where('period_end', '>=', $snapshot->period_start)
                ->orderBy('id')->lockForUpdate()->get();
            $snapshot = $overlapping->firstWhere('id', $snapshot->id);
            abort_unless($snapshot, 404);
            abort_if($overlapping->contains(fn ($other) => $other->id !== $snapshot->id && $other->isPaid()), 422, 'An overlapping period has already been paid. Choose a period that has not been settled.');
            abort_if($snapshot->isPaid() || $snapshot->payoutTransactions()->exists(), 422, 'This snapshot has already been paid out.');
            $snapshot->load('details');
            $memberTotal = $snapshot->details->where('recipient_type', 'shareholder')->sum('amount');
            abort_if(round($memberTotal * 100) !== round((float) $snapshot->members_amount * 100), 422, 'Snapshot details do not balance. Create a new snapshot.');

            foreach ($snapshot->details as $detail) {
                if ($detail->recipient_type !== 'shareholder' || $detail->amount <= 0) {
                    continue;
                }

                FinancialTransaction::create([
                    'type' => 'payout_share',
                    'amount' => $detail->amount,
                    'description' => 'Profit Distribution Payout — '.$detail->recipient_name
                        .' ('.$snapshot->period_start->toDateString().' to '.$snapshot->period_end->toDateString().')',
                    'payment_tender_id' => $tenderId,
                    'distribution_snapshot_id' => $snapshot->id,
                    'shareholder_id' => $detail->shareholder_id,
                    'user_id' => auth()->id(),
                    'notes' => $notes,
                    'transacted_at' => $now,
                ]);
            }

            $snapshot->update([
                'paid_at' => $now,
                'paid_by' => auth()->id(),
            ]);
        }, 3);

        AuditLogger::record('distribution.payout', $snapshot, null, [
            'snapshot_id' => $snapshot->id,
            'tender_id' => $tenderId,
            'amount' => $snapshot->members_amount,
        ], 'Recorded payout for distribution snapshot #'.$snapshot->id);

        return response()->json($snapshot->fresh(['details', 'payer:id,name', 'payoutTransactions.tender']), 200);
    }

    public function trend(Request $request): JsonResponse
    {
        $this->adminOnly();
        [$basis, $start, $end] = $this->filters($request);

        return response()->json($this->service->trend($basis, $start, $end));
    }

    /** CSV export of the current preview. */
    public function export(Request $request): StreamedResponse
    {
        $this->adminOnly();
        [$basis, $start, $end, $cat, $prod, $sh] = $this->filters($request);
        $r = $this->service->compute($basis, $start, $end, $cat, $prod, $sh);

        $rows = [];
        $rows[] = ['Distribution Report', "$start to $end", 'Basis: '.$basis];
        $rows[] = [];
        $rows[] = ['Metric', 'Amount'];
        $rows[] = [$r['base_label'], $r['base_amount']];
        $rows[] = ['Distributable', $r['distributable']];
        $rows[] = [];
        $rows[] = ['Recipient', 'Type', 'Percentage', 'Amount'];
        foreach ($r['members'] as $m) {
            $rows[] = [$m['name'], 'Member', $m['percentage'].'%', $m['amount']];
        }
        $rows[] = ['Company Retained Earnings', 'Company', $r['company_percentage'].'%', $r['company_amount']];

        if (! empty($r['incentive']['by_shareholder'])) {
            $rows[] = [];
            $rows[] = ['Incentive Pool', '', '', $r['incentive']['total']];
            foreach ($r['incentive']['by_shareholder'] as $s) {
                $rows[] = [$s['name'], 'Incentive', '', $s['incentive_amount']];
            }
        }

        $filename = "distribution-$start-to-$end.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        $request->validate([
            'basis' => 'nullable|in:sales,profit',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'category_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'shareholder_id' => 'nullable|integer',
        ]);

        return [
            $request->input('basis', 'profit'),
            $request->input('start_date', Carbon::now('Asia/Manila')->startOfMonth()->toDateString()),
            $request->input('end_date', Carbon::now('Asia/Manila')->toDateString()),
            $request->input('category_id') ? (int) $request->input('category_id') : null,
            $request->input('product_id') ? (int) $request->input('product_id') : null,
            $request->input('shareholder_id') ? (int) $request->input('shareholder_id') : null,
        ];
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) {
            abort(403, 'Admin only');
        }
    }
}
