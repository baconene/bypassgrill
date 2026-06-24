<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HrisSetting;
use App\Models\PayrollRecord;
use App\Models\FinancialTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrisController extends Controller
{
    // ── Employees ──────────────────────────────────────────────────────────────

    public function employees(): JsonResponse
    {
        $employees = Employee::orderBy('name')
            ->get()
            ->map(fn ($e) => $this->formatEmployee($e));

        return response()->json(['data' => $employees]);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'position'        => 'nullable|string|max:255',
            'employment_type' => 'nullable|in:full_time,part_time,contractual',
            'salary_type'     => 'nullable|in:monthly,daily,hourly',
            'base_rate'       => 'nullable|numeric|min:0',
            'is_active'       => 'nullable|boolean',
            'hired_at'        => 'nullable|date',
            'notes'           => 'nullable|string',
        ]);

        $employee = Employee::create($data);

        return response()->json(['data' => $this->formatEmployee($employee)], 201);
    }

    public function updateEmployee(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'position'        => 'nullable|string|max:255',
            'employment_type' => 'nullable|in:full_time,part_time,contractual',
            'salary_type'     => 'nullable|in:monthly,daily,hourly',
            'base_rate'       => 'nullable|numeric|min:0',
            'is_active'       => 'nullable|boolean',
            'hired_at'        => 'nullable|date',
            'notes'           => 'nullable|string',
        ]);

        $employee->update($data);

        return response()->json(['data' => $this->formatEmployee($employee->fresh())]);
    }

    public function destroyEmployee(Employee $employee): JsonResponse
    {
        $employee->delete();
        return response()->json(null, 204);
    }

    // ── Payroll Records ────────────────────────────────────────────────────────

    public function payrollRecords(Request $request): JsonResponse
    {
        $query = PayrollRecord::with('employee')
            ->orderByDesc('period_end')
            ->orderByDesc('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $records = $query->limit(100)->get()->map(fn ($r) => $this->formatPayrollRecord($r));

        return response()->json(['data' => $records]);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'  => 'required|exists:employees,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'days_worked'  => 'required|numeric|min:0',
            'gross_pay'    => 'required|numeric|min:0',
            'deductions'   => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        $data['deductions'] = $data['deductions'] ?? 0;
        $data['net_pay'] = $data['gross_pay'] - $data['deductions'];
        $data['status'] = 'pending';

        $record = PayrollRecord::create($data);
        $record->load('employee');

        return response()->json(['data' => $this->formatPayrollRecord($record)], 201);
    }

    public function markPayrollPaid(Request $request, PayrollRecord $payrollRecord): JsonResponse
    {
        if ($payrollRecord->status === 'paid') {
            return response()->json(['message' => 'Already marked as paid.'], 422);
        }

        DB::transaction(function () use ($payrollRecord) {
            $payrollRecord->update(['status' => 'approved']);

            $hrisSetting = HrisSetting::getSetting();

            $ft = FinancialTransaction::create([
                'type'              => 'payroll',
                'amount'            => (float) $payrollRecord->net_pay,
                'description'       => 'Payroll: ' . $payrollRecord->employee->name,
                'notes'             => sprintf(
                    '%s – %s | %s days worked | Gross: ₱%s | Deductions: ₱%s',
                    $payrollRecord->period_start->format('M d'),
                    $payrollRecord->period_end->format('M d, Y'),
                    number_format((float) $payrollRecord->days_worked, 1),
                    number_format((float) $payrollRecord->gross_pay, 2),
                    number_format((float) $payrollRecord->deductions, 2)
                ),
                'payroll_record_id' => $payrollRecord->id,
                'payment_tender_id' => $hrisSetting->payroll_tender_id,
                'user_id'           => auth()->id(),
                'transacted_at'     => now(),
            ]);

            $payrollRecord->update([
                'status'                 => 'paid',
                'paid_at'                => now(),
                'financial_transaction_id' => $ft->id,
            ]);
        });

        $payrollRecord->load('employee');
        return response()->json(['data' => $this->formatPayrollRecord($payrollRecord->fresh())]);
    }

    public function destroyPayroll(PayrollRecord $payrollRecord): JsonResponse
    {
        if ($payrollRecord->status === 'paid') {
            return response()->json(['message' => 'Cannot delete a paid payroll record.'], 422);
        }
        $payrollRecord->delete();
        return response()->json(null, 204);
    }

    // ── Formatting helpers ─────────────────────────────────────────────────────

    private function formatEmployee(Employee $e): array
    {
        return [
            'id'              => $e->id,
            'name'            => $e->name,
            'position'        => $e->position,
            'employment_type' => $e->employment_type,
            'salary_type'     => $e->salary_type,
            'base_rate'       => (float) $e->base_rate,
            'is_active'       => (bool) $e->is_active,
            'hired_at'        => $e->hired_at?->toDateString(),
            'notes'           => $e->notes,
        ];
    }

    private function formatPayrollRecord(PayrollRecord $r): array
    {
        return [
            'id'                       => $r->id,
            'employee_id'              => $r->employee_id,
            'employee_name'            => $r->employee?->name,
            'employee_position'        => $r->employee?->position,
            'period_start'             => $r->period_start?->toDateString(),
            'period_end'               => $r->period_end?->toDateString(),
            'days_worked'              => (float) $r->days_worked,
            'gross_pay'                => (float) $r->gross_pay,
            'deductions'               => (float) $r->deductions,
            'net_pay'                  => (float) $r->net_pay,
            'status'                   => $r->status,
            'notes'                    => $r->notes,
            'paid_at'                  => $r->paid_at?->toDateTimeString(),
            'financial_transaction_id' => $r->financial_transaction_id,
        ];
    }
}
