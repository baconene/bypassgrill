<?php

namespace App\Enums;

enum InventoryCostKind: string
{
    case PURCHASE = 'purchase';
    case PURCHASE_REVERSAL = 'purchase_reversal';
    case CONSUMPTION = 'consumption';
    case CONSUMPTION_REVERSAL = 'consumption_reversal';
    case WASTE = 'waste';
    case COUNT_LOSS = 'count_loss';
    case COUNT_GAIN = 'count_gain';
}
