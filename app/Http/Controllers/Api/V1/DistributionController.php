<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DistributionSnapshot;
use App\Services\Distribution\ProfitDistributionService;
use App\Support\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DistributionController extends Controller
{
    public function __construct(private ProfitDistributionService $service) {}

    /** Live preview — recomputed from existing sales/financial data. */
    public function preview(Request $request): JsonResponse
    {
        $this->adminOnly();
        [$basis, $start, $end, $cat, $prod, $sh] = $this->filters($request);

        return response()->json(
            $this->service->compute($basis, $start, $end, $cat, $prod, $sh)
        );
    }

    /** Persist a historical snapshot. */
    public function storeSnapshot(Request $request): JsonResponse
    {
        $this->adminOnly();
        [$basis, $start, $end, $cat, $prod, $sh] = $this->filters($request);

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
            DistributionSnapshot::with('creator:id,name')->orderByDesc('created_at')->limit(100)->get()
        );
    }

    public function showSnapshot(DistributionSnapshot $snapshot): JsonResponse
    {
        $this->adminOnly();
        return response()->json($snapshot->load(['details', 'creator:id,name']));
    }

    /** Monthly distribution trend. */
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
        $rows[] = ['Distribution Report', "$start to $end", 'Basis: ' . $basis];
        $rows[] = [];
        $rows[] = ['Metric', 'Amount'];
        $rows[] = [$r['base_label'], $r['base_amount']];
        $rows[] = ['Distributable', $r['distributable']];
        $rows[] = [];
        $rows[] = ['Recipient', 'Type', 'Percentage', 'Amount'];
        foreach ($r['members'] as $m) {
            $rows[] = [$m['name'], 'Member', $m['percentage'] . '%', $m['amount']];
        }
        $rows[] = ['Company Retained Earnings', 'Company', $r['company_percentage'] . '%', $r['company_amount']];

        if (!empty($r['incentive']['by_shareholder'])) {
            $rows[] = [];
            $rows[] = ['Incentive Pool', '', '', $r['incentive']['total']];
            foreach ($r['incentive']['by_shareholder'] as $s) {
                $rows[] = [$s['name'], 'Incentive', $s['sales_pct'] . '%', $s['incentive_amount']];
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
            'basis'          => 'nullable|in:sales,profit',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
            'category_id'    => 'nullable|integer',
            'product_id'     => 'nullable|integer',
            'shareholder_id' => 'nullable|integer',
        ]);

        return [
            $request->input('basis', 'sales'),
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
