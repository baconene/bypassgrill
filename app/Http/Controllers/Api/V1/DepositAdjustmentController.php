<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DepositAdjustment;
use App\Models\DepositControl;
use App\Services\DepositAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositAdjustmentController extends Controller
{
    public function __construct(private DepositAdjustmentService $adjustments) {}

    public function store(Request $request, DepositControl $depositControl): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return response()->json($this->adjustments->request($depositControl, $request->user(), $data['reason']), 201);
    }

    public function approve(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        return response()->json($this->adjustments->approve($depositAdjustment, $request->user()));
    }

    public function reject(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return response()->json($this->adjustments->reject($depositAdjustment, $request->user(), $data['reason']));
    }

    public function reopen(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        return response()->json($this->adjustments->reopen($depositAdjustment, $request->user()));
    }

    public function keep(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        return response()->json($this->adjustments->keepRejected($depositAdjustment, $request->user()));
    }

    public function cancel(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        return response()->json($this->adjustments->cancel($depositAdjustment, $request->user()));
    }

    public function post(Request $request, DepositAdjustment $depositAdjustment): JsonResponse
    {
        $data = $request->validate(['payment_tender_id' => 'required|integer|exists:payment_tenders,id']);

        return response()->json($this->adjustments->post($depositAdjustment, $request->user(), (int) $data['payment_tender_id']));
    }
}
