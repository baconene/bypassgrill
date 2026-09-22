<?php

namespace App\Services\Distribution;

use App\Models\Shareholder;
use Illuminate\Validation\ValidationException;

/**
 * Allocates a distributable amount to active shareholders by ownership %,
 * and computes the company retained-earnings remainder.
 */
class ShareDistributionService
{
    /**
     * @return array{
     *   members: array<int, array{shareholder_id:int, name:string, percentage:float, amount:float}>,
     *   members_total: float,
     *   members_percentage: float,
     *   company_amount: float,
     *   company_percentage: float
     * }
     */
    public function allocate(float $distributable, ?int $onlyShareholderId = null): array
    {
        $shareholders = Shareholder::active()
            ->orderByDesc('ownership_percentage')->orderBy('id')
            ->get();

        $allMembersPct = (float) $shareholders->sum('ownership_percentage');
        if ($allMembersPct > 100 || $shareholders->contains(fn ($s) => $s->ownership_percentage < 0)) {
            throw ValidationException::withMessages(['ownership' => 'Active ownership must be between 0% and 100% in total.']);
        }
        $companyPct = round(100 - $allMembersPct, 2);
        $weights = $shareholders->pluck('ownership_percentage', 'id')->map(fn ($p) => (float) $p)->all();
        $weights['company'] = $companyPct;
        $amounts = MoneyAllocation::split($distributable, $weights);
        $members = [];
        $membersTotal = 0.0;
        $pctTotal = 0.0;

        foreach ($shareholders as $s) {
            if ($onlyShareholderId && $s->id !== $onlyShareholderId) {
                continue;
            }
            $pct = (float) $s->ownership_percentage;
            $amount = $amounts[$s->id];
            $membersTotal = round($membersTotal + $amount, 2);
            $pctTotal += $pct;
            $members[] = [
                'shareholder_id' => $s->id,
                'name' => $s->name,
                'percentage' => $pct,
                'amount' => $amount,
            ];
        }

        $companyAmount = $amounts['company'];

        return [
            'members' => $members,
            'members_total' => $membersTotal,
            'members_percentage' => round($pctTotal, 2),
            'company_amount' => $companyAmount,
            'company_percentage' => $companyPct,
        ];
    }
}
