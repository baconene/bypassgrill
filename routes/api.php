<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\HrisController;

Route::middleware('auth')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    Route::get('/payment-tenders', [\App\Http\Controllers\Api\V1\PaymentTenderController::class, 'index']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/category/{categoryId}', [ProductController::class, 'byCategory']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

        if (app()->environment('local')) {
            Route::get('/reports/heatmap', [ReportController::class, 'heatmap']);
        }

        Route::middleware('auth')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::post('/products/{product}', [ProductController::class, 'update']);
        Route::post('/products/{product}/calculate-cost', [ProductController::class, 'calculateCost']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::get('/orders/active', [OrderController::class, 'activeOrders']);
        Route::get('/orders/queue', [OrderController::class, 'queueOrders']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::apiResource('orders', OrderController::class);

        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/orders/{orderId}/payments', [PaymentController::class, 'orderPayments']);
        Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund']);

        Route::post('/inventory', [InventoryController::class, 'store']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
        Route::patch('/inventory/{ingredient}', [InventoryController::class, 'update']);
        Route::get('/inventory/{ingredient}/transactions', [InventoryController::class, 'transactions']);

        Route::get('/reports/daily-sales', [ReportController::class, 'dailySales']);
        Route::get('/reports/monthly-sales', [ReportController::class, 'monthlySales']);
        Route::get('/reports/product-sales', [ReportController::class, 'productSales']);
        Route::get('/reports/product-daily-sales', [ReportController::class, 'productDailySales']);
        Route::get('/reports/inventory-valuation', [ReportController::class, 'inventoryValuation']);
        Route::get('/reports/inventory-transactions', [ReportController::class, 'inventoryTransactions']);
        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('/reports/daily-chart', [ReportController::class, 'dailyChart']);
        Route::get('/reports/monthly-chart', [ReportController::class, 'monthlyChart']);
        Route::get('/reports/heatmap', [ReportController::class, 'heatmap']);
        Route::get('/reports/analytics', [ReportController::class, 'analytics']);
        Route::get('/reports/ft-breakdown', [ReportController::class, 'ftBreakdown']);

        Route::get('/payment-tenders/all', [\App\Http\Controllers\Api\V1\PaymentTenderController::class, 'all']);
        Route::post('/payment-tenders', [\App\Http\Controllers\Api\V1\PaymentTenderController::class, 'store']);
        Route::put('/payment-tenders/{paymentTender}', [\App\Http\Controllers\Api\V1\PaymentTenderController::class, 'update']);
        Route::delete('/payment-tenders/{paymentTender}', [\App\Http\Controllers\Api\V1\PaymentTenderController::class, 'destroy']);

        Route::get('/financial-transactions', [\App\Http\Controllers\Api\V1\FinancialTransactionController::class, 'index']);
        Route::get('/financial-transactions/summary', [\App\Http\Controllers\Api\V1\FinancialTransactionController::class, 'summary']);
        Route::post('/financial-transactions', [\App\Http\Controllers\Api\V1\FinancialTransactionController::class, 'store']);
        Route::patch('/financial-transactions/{financialTransaction}', [\App\Http\Controllers\Api\V1\FinancialTransactionController::class, 'update']);
        Route::delete('/financial-transactions/{financialTransaction}', [\App\Http\Controllers\Api\V1\FinancialTransactionController::class, 'destroy']);

        Route::get('/bills/summary', [\App\Http\Controllers\Api\V1\BillController::class, 'summary']);
        Route::get('/bills/forecast', [\App\Http\Controllers\Api\V1\BillController::class, 'forecast']);
        Route::get('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'index']);
        Route::post('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'store']);
        Route::put('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'update']);
        Route::delete('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'destroy']);
        Route::post('/bills/{bill}/pay', [\App\Http\Controllers\Api\V1\BillController::class, 'pay']);
        Route::post('/bills/{bill}/installments/{installment}/pay', [\App\Http\Controllers\Api\V1\BillController::class, 'payInstallment']);

        Route::get('/parcels', [\App\Http\Controllers\Api\V1\ParcelController::class, 'index']);
        Route::get('/parcels/{parcel}', [\App\Http\Controllers\Api\V1\ParcelController::class, 'show']);
        Route::post('/parcels', [\App\Http\Controllers\Api\V1\ParcelController::class, 'store']);
        Route::put('/parcels/{parcel}', [\App\Http\Controllers\Api\V1\ParcelController::class, 'update']);
        Route::delete('/parcels/{parcel}', [\App\Http\Controllers\Api\V1\ParcelController::class, 'destroy']);
        Route::post('/parcels/{parcel}/items', [\App\Http\Controllers\Api\V1\ParcelController::class, 'storeItem']);
        Route::put('/parcels/{parcel}/items/{item}', [\App\Http\Controllers\Api\V1\ParcelController::class, 'updateItem']);
        Route::delete('/parcels/{parcel}/items/{item}', [\App\Http\Controllers\Api\V1\ParcelController::class, 'destroyItem']);
        Route::patch('/parcels/{parcel}/items/{item}/toggle', [\App\Http\Controllers\Api\V1\ParcelController::class, 'toggleItem']);

        Route::get('/print-service/settings', [\App\Http\Controllers\Api\V1\PrintServiceController::class, 'getSettings']);
        Route::post('/print-service/settings', [\App\Http\Controllers\Api\V1\PrintServiceController::class, 'saveSettings']);
        Route::post('/print-service/test', [\App\Http\Controllers\Api\V1\PrintServiceController::class, 'testConnection']);
        Route::post('/print-service/clear-config-cache', function () {
            abort_unless(auth()->user()?->hasRole('admin'), 403);
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            return response()->json(['ok' => true, 'message' => 'Config and app cache cleared. Broadcasting driver is now: ' . config('broadcasting.default')]);
        });

        Route::get('/hris/employees', [HrisController::class, 'employees']);
        Route::post('/hris/employees', [HrisController::class, 'storeEmployee']);
        Route::put('/hris/employees/{employee}', [HrisController::class, 'updateEmployee']);
        Route::delete('/hris/employees/{employee}', [HrisController::class, 'destroyEmployee']);

        Route::get('/hris/payroll', [HrisController::class, 'payrollRecords']);
        Route::post('/hris/payroll', [HrisController::class, 'storePayroll']);
        Route::post('/hris/payroll/{payrollRecord}/pay', [HrisController::class, 'markPayrollPaid']);
        Route::delete('/hris/payroll/{payrollRecord}', [HrisController::class, 'destroyPayroll']);

        Route::get('/print-jobs', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'index']);
        Route::post('/print-jobs', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'store']);
        Route::post('/print-jobs/test-channels', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'testChannels']);
        Route::post('/print-jobs/test-notification', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'testNotification']);
        Route::post('/print-jobs/{printJob}/ack', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'acknowledge']);
        Route::post('/print-jobs/{printJob}/retry', [\App\Http\Controllers\Api\V1\PrintJobController::class, 'retry']);

        Route::get('/shareholders', [\App\Http\Controllers\Api\V1\ShareholderController::class, 'index']);
        Route::post('/shareholders', [\App\Http\Controllers\Api\V1\ShareholderController::class, 'store']);
        Route::put('/shareholders/{shareholder}', [\App\Http\Controllers\Api\V1\ShareholderController::class, 'update']);
        Route::delete('/shareholders/{shareholder}', [\App\Http\Controllers\Api\V1\ShareholderController::class, 'destroy']);

        Route::get('/royalty-rules', [\App\Http\Controllers\Api\V1\RoyaltyRuleController::class, 'index']);
        Route::post('/royalty-rules', [\App\Http\Controllers\Api\V1\RoyaltyRuleController::class, 'store']);
        Route::put('/royalty-rules/{royaltyRule}', [\App\Http\Controllers\Api\V1\RoyaltyRuleController::class, 'update']);
        Route::delete('/royalty-rules/{royaltyRule}', [\App\Http\Controllers\Api\V1\RoyaltyRuleController::class, 'destroy']);

        Route::get('/product-ownerships', [\App\Http\Controllers\Api\V1\ProductOwnershipController::class, 'index']);
        Route::put('/product-ownerships/{productId}', [\App\Http\Controllers\Api\V1\ProductOwnershipController::class, 'update']);
        Route::delete('/product-ownerships/{productId}', [\App\Http\Controllers\Api\V1\ProductOwnershipController::class, 'destroy']);

        Route::get('/incentive-rules', [\App\Http\Controllers\Api\V1\IncentiveRuleController::class, 'index']);
        Route::post('/incentive-rules', [\App\Http\Controllers\Api\V1\IncentiveRuleController::class, 'store']);
        Route::put('/incentive-rules/{incentiveRule}', [\App\Http\Controllers\Api\V1\IncentiveRuleController::class, 'update']);
        Route::delete('/incentive-rules/{incentiveRule}', [\App\Http\Controllers\Api\V1\IncentiveRuleController::class, 'destroy']);

        Route::get('/distribution/preview', [\App\Http\Controllers\Api\V1\DistributionController::class, 'preview']);
        Route::get('/distribution/trend', [\App\Http\Controllers\Api\V1\DistributionController::class, 'trend']);
        Route::get('/distribution/export', [\App\Http\Controllers\Api\V1\DistributionController::class, 'export']);
        Route::get('/distribution/royalty-analytics', [\App\Http\Controllers\Api\V1\DistributionController::class, 'royaltyAnalytics']);
        Route::get('/distribution/snapshots', [\App\Http\Controllers\Api\V1\DistributionController::class, 'snapshots']);
        Route::get('/distribution/snapshots/{snapshot}', [\App\Http\Controllers\Api\V1\DistributionController::class, 'showSnapshot']);
        Route::post('/distribution/snapshots', [\App\Http\Controllers\Api\V1\DistributionController::class, 'storeSnapshot']);
        Route::post('/distribution/snapshots/{snapshot}/payout', [\App\Http\Controllers\Api\V1\DistributionController::class, 'recordPayout']);

        Route::get('/tools/tables', [\App\Http\Controllers\Api\V1\ToolsController::class, 'tables']);
        Route::get('/tools/tables/{table}/columns', [\App\Http\Controllers\Api\V1\ToolsController::class, 'columns'])
            ->where('table', '[A-Za-z0-9_]+');
        Route::post('/tools/query', [\App\Http\Controllers\Api\V1\ToolsController::class, 'query']);
    });
});
