<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryCostKind;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryCostEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'ingredient_id' => 'nullable|integer|exists:ingredients,id',
            'kind' => ['nullable', Rule::enum(InventoryCostKind::class)],
            'page' => 'nullable|integer|min:1',
        ]);
        $query = InventoryCostEntry::whereBetween('recognized_at', [$data['start_date'].' 00:00:00', $data['end_date'].' 23:59:59'])
            ->when($data['ingredient_id'] ?? null, fn ($q, $id) => $q->where('ingredient_id', $id))
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind));
        $totals = (clone $query)->selectRaw('kind, SUM(total_cost) as amount')->groupBy('kind')->get()
            ->mapWithKeys(fn ($r) => [$r->kind->value => (float) $r->amount]);
        $bySource = (clone $query)->selectRaw('source, COUNT(*) as entries, SUM(CASE WHEN kind IN (\'consumption\', \'consumption_reversal\') THEN total_cost ELSE 0 END) as consumed_cost')
            ->groupBy('source')->get()->map(fn ($r) => ['source' => $r->source->value, 'entries' => (int) $r->entries, 'consumed_cost' => (float) $r->consumed_cost]);

        return response()->json([
            'period' => ['start' => $data['start_date'], 'end' => $data['end_date']],
            'purchases' => round(($totals['purchase'] ?? 0) + ($totals['purchase_reversal'] ?? 0), 2),
            'consumption' => round(($totals['consumption'] ?? 0) + ($totals['consumption_reversal'] ?? 0), 2),
            'losses' => round(($totals['waste'] ?? 0) + ($totals['count_loss'] ?? 0) + ($totals['count_gain'] ?? 0), 2),
            'sources' => $bySource,
            'entries' => (clone $query)->orderByDesc('recognized_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'ingredients' => Ingredient::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
        ]);
    }
}
