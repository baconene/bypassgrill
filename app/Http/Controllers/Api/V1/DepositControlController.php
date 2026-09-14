<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DepositControl;
use App\Models\User;
use App\Services\DepositSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositControlController extends Controller
{
    public function index()
    {
        return response()->json([
            'active' => DepositControl::with('user:id,name')->where('active_slot', 1)->first(),
            'history' => DepositControl::with('user:id,name')->whereNotNull('submitted_at')->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request, DepositSnapshot $snapshots)
    {
        return DB::transaction(function () use ($request, $snapshots) {
            // Serialize starts, including the empty-table case; unique active_slot is a backstop.
            User::orderBy('id')->lockForUpdate()->firstOrFail();
            abort_if(DepositControl::where('active_slot', 1)->exists(), 409, 'A shift is already active. Complete its counts first.');
            $at = now();

            return response()->json(DepositControl::create([
                'user_id' => $request->user()->id, 'active_slot' => 1,
                'opened_at' => $at, 'opening_snapshot' => $snapshots->capture($at),
            ]), 201);
        });
    }

    public function close(Request $request, DepositControl $depositControl, DepositSnapshot $snapshots)
    {
        return DB::transaction(function () use ($request, $depositControl, $snapshots) {
            $shift = DepositControl::lockForUpdate()->findOrFail($depositControl->id);
            $this->authorizeOwner($request, $shift);
            abort_if($shift->closed_at !== null, 409, 'Closing snapshot already captured.');
            $at = now();
            abort_if($at->lt($shift->opened_at), 422, 'Closing time cannot precede opening time.');
            $shift->update(['closed_at' => $at, 'closing_snapshot' => $snapshots->capture($at)]);

            return response()->json($shift);
        });
    }

    public function reconcile(Request $request, DepositControl $depositControl)
    {
        $data = $request->validate([
            'lockbox_amount' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'lockbox_tender_id' => 'required|integer',
            'tenders' => 'required|array|min:1',
            'tenders.*.id' => 'required|integer|distinct',
            'tenders.*.amount' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'notes' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $depositControl, $data) {
            $shift = DepositControl::lockForUpdate()->findOrFail($depositControl->id);
            $this->authorizeOwner($request, $shift);
            abort_if($shift->closed_at === null || $shift->submitted_at !== null, 409, 'Capture closing balances before submitting counts; submitted counts are final.');
            $expected = collect($shift->closing_snapshot['balance_by_tender'])->whereNotNull('id');
            $ids = $expected->pluck('id')->sort()->values()->all();
            $submitted = collect($data['tenders'])->keyBy('id');
            if ($submitted->keys()->sort()->values()->all() !== $ids || ! in_array((int) $data['lockbox_tender_id'], $ids, true)) {
                throw ValidationException::withMessages(['tenders' => 'Enter every tender from the closing snapshot and select a lockbox tender.']);
            }
            $lockbox = (int) round((float) $data['lockbox_amount'] * 100);
            $actualTotal = 0;
            $rows = [];
            foreach ($expected as $tender) {
                $outside = (int) round((float) $submitted[$tender['id']]['amount'] * 100);
                $actual = $outside + ($tender['id'] === (int) $data['lockbox_tender_id'] ? $lockbox : 0);
                $actualTotal += $actual;
                $rows[] = $tender + ['outside_lockbox' => $outside / 100, 'actual' => $actual / 100, 'variance' => ($actual - (int) round($tender['balance'] * 100)) / 100];
            }
            $opening = (int) round($shift->opening_snapshot['running_balance'] * 100);
            $closing = (int) round($shift->closing_snapshot['running_balance'] * 100);
            $shift->update([
                'active_slot' => null, 'submitted_at' => now(),
                'reconciliation' => [
                    'lockbox_amount' => $lockbox / 100, 'lockbox_tender_id' => (int) $data['lockbox_tender_id'],
                    'tenders' => $rows, 'actual_total' => $actualTotal / 100,
                    'system_shift_change' => ($closing - $opening) / 100,
                    'overall_variance' => ($actualTotal - $closing) / 100,
                    'notes' => $data['notes'] ?? null,
                ],
            ]);

            return response()->json($shift);
        });
    }

    private function authorizeOwner(Request $request, DepositControl $shift): void
    {
        abort_unless($shift->user_id === $request->user()->id, 403, 'Only the cashier who started this shift may complete it.');
    }
}
