<?php

namespace App\Services;

use App\Models\DepositControl;
use App\Models\FinancialTransaction;
use App\Models\InventoryTransaction;
use App\Models\ShiftChecklistMark;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The opening and closing routine, read from what the shift actually recorded
 * rather than from ticks alone. Opening and closing the deposit control are
 * proven by the shift itself. A stock check or a pre-opening expense can
 * legitimately have nothing to record, so those two can also be ticked by hand.
 */
class ShiftChecklist
{
    public const MANUAL_STEPS = ['stock', 'expenses'];

    public function for(User $user, ?CarbonInterface $date = null): array
    {
        $date = $date ? Carbon::parse($date) : Carbon::today();
        $day = $date->toDateString();

        $shift = DepositControl::whereDate('opened_at', $day)->latest('id')->first();
        $marks = ShiftChecklistMark::where('user_id', $user->id)
            ->whereDate('business_date', $day)
            ->pluck('step')
            ->all();

        // Recorded activity proves these two; the hand tick is only offered when
        // there is none, so nobody is invited to "undo" a movement that exists.
        $stockMovements = InventoryTransaction::whereDate('created_at', $day)->count();
        $expenses = $this->expenseCount($day);

        $steps = [
            [
                'key' => 'open_deposit',
                'title' => 'Open the deposit control',
                'hint' => 'Count the cash already in the drawer and start the shift.',
                'href' => '/deposit-control',
                'phase' => 'before',
                'done' => (bool) $shift,
                'detail' => $shift
                    ? 'Opened by '.($shift->user?->name ?? 'a cashier').' at '.$shift->opened_at?->format('g:i A')
                    : 'No shift opened today.',
                'manual' => false,
                'marked' => false,
            ],
            [
                'key' => 'stock',
                'title' => 'Check the food stock and update the numbers',
                'hint' => 'Count what is on hand and correct the quantities in Inventory.',
                'href' => '/inventory',
                'phase' => 'before',
                'done' => $stockMovements > 0 || in_array('stock', $marks, true),
                'detail' => $this->stockDetail($stockMovements, $marks),
                'manual' => $stockMovements === 0,
                'marked' => in_array('stock', $marks, true),
            ],
            [
                'key' => 'expenses',
                'title' => 'Record the pre-opening expenses',
                'hint' => 'Market runs, ice, gas and anything else paid before selling.',
                'href' => '/financial',
                'phase' => 'before',
                'done' => $expenses > 0 || in_array('expenses', $marks, true),
                'detail' => $this->expenseDetail($expenses, $marks),
                'manual' => $expenses === 0,
                'marked' => in_array('expenses', $marks, true),
            ],
            [
                'key' => 'close_deposit',
                'title' => 'Close the deposit control',
                'hint' => 'Capture the closing balances, then count and submit the cash and GCash.',
                'href' => '/deposit-control',
                'phase' => 'after',
                'done' => (bool) ($shift?->submitted_at),
                'detail' => $this->closeDetail($shift),
                'manual' => false,
                'marked' => false,
            ],
        ];

        $done = count(array_filter($steps, fn ($step) => $step['done']));

        return [
            'business_date' => $day,
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'next' => collect($steps)->firstWhere('done', false)['title'] ?? null,
        ];
    }

    public function toggle(User $user, string $step, ?CarbonInterface $date = null): void
    {
        $day = ($date ? Carbon::parse($date) : Carbon::today())->toDateString();
        $existing = ShiftChecklistMark::where('user_id', $user->id)
            ->whereDate('business_date', $day)
            ->where('step', $step)
            ->first();

        $existing
            ? $existing->delete()
            : ShiftChecklistMark::create(['user_id' => $user->id, 'business_date' => $day, 'step' => $step]);
    }

    private function stockDetail(int $count, array $marks): string
    {
        if ($count > 0) {
            return $count.' stock movement'.($count === 1 ? '' : 's').' recorded today.';
        }

        return in_array('stock', $marks, true)
            ? 'Marked as checked; nothing needed updating.'
            : 'No stock movement recorded today.';
    }

    private function expenseDetail(int $count, array $marks): string
    {
        if ($count > 0) {
            return $count.' expense'.($count === 1 ? '' : 's').' recorded today.';
        }

        return in_array('expenses', $marks, true)
            ? 'Marked as done; nothing was spent before opening.'
            : 'No expense recorded today.';
    }

    private function expenseCount(string $day): int
    {
        return FinancialTransaction::where('type', 'expense')->whereDate('transacted_at', $day)->count();
    }

    private function closeDetail(?DepositControl $shift): string
    {
        if (! $shift) {
            return 'Starts once a shift is open.';
        }

        if ($shift->submitted_at) {
            return 'Closed and counted at '.$shift->submitted_at->format('g:i A').'.';
        }

        return $shift->closed_at
            ? 'Closing balances captured. Enter the actual counts to finish.'
            : 'Shift still open.';
    }
}
