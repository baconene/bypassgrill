<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DepositControl;
use App\Models\User;
use App\Services\DepositReconciliation;
use App\Services\DepositSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0|max:9999999999.99',
        ]);

        return DB::transaction(function () use ($request, $snapshots, $data) {
            // Serialize starts, including the empty-table case; unique active_slot is a backstop.
            User::orderBy('id')->lockForUpdate()->firstOrFail();
            abort_if(DepositControl::where('active_slot', 1)->exists(), 409, 'A shift is already active. Complete its counts first.');
            $at = now();
            $snapshot = array_merge($snapshots->capture($at), ['opening_cash' => (float) $data['opening_cash']]);

            return response()->json(DepositControl::create([
                'user_id' => $request->user()->id, 'active_slot' => 1,
                'opened_at' => $at, 'opening_snapshot' => $snapshot,
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

    public function reconcile(Request $request, DepositControl $depositControl, DepositReconciliation $reconciliation)
    {
        $data = $request->validate([
            'drawer_cash' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'shift_gcash' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'lockbox_total' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2|gte:drawer_cash',
            'total_gcash' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'notes' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $depositControl, $data, $reconciliation) {
            $shift = DepositControl::lockForUpdate()->findOrFail($depositControl->id);
            $this->authorizeOwner($request, $shift);
            abort_if($shift->closed_at === null || $shift->submitted_at !== null, 409, 'Capture closing balances before submitting counts; submitted counts are final.');
            $shift->update([
                'active_slot' => null, 'submitted_at' => now(),
                'reconciliation' => $reconciliation->calculate($shift->opening_snapshot, $shift->closing_snapshot, $data),
            ]);

            return response()->json($shift);
        });
    }

    private function authorizeOwner(Request $request, DepositControl $shift): void
    {
        abort_unless($shift->user_id === $request->user()->id, 403, 'Only the cashier who started this shift may complete it.');
    }
}
