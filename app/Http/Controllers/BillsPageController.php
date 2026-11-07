<?php

namespace App\Http\Controllers;

use App\Models\AccountingSetting;
use Inertia\Inertia;
use Inertia\Response;

class BillsPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('BillsPage', [
            'accountingStartDay' => AccountingSetting::startDay(),
        ]);
    }
}
