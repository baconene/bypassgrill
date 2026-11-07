<?php

namespace App\Http\Controllers;

use App\Models\AccountingSetting;
use Inertia\Inertia;
use Inertia\Response;

class FinancialPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('FinancialPage', [
            'accountingStartDay' => AccountingSetting::startDay(),
        ]);
    }
}
