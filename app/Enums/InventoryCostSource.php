<?php

namespace App\Enums;

enum InventoryCostSource: string
{
    case INGREDIENT = 'ingredient';
    case UNTRACKED_INGREDIENT = 'untracked_ingredient';
    case PRODUCT_FALLBACK = 'product_fallback';
}
