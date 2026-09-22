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
        abort_if(PayrollRecord::where('employee_id', $employee->id)->exists(), 422, 'This employee has payroll history. Set them inactive instead.');
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

    public function payrollReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);
        // Aggregate the entire cash ledger, not the latest 100 payroll records.
        // Unlinked/manual payroll remains visible so the total matches Financial.
        $query = DB::table('financial_transactions as ft')
            ->leftJoin('payroll_records as pr', 'pr.id', '=', 'ft.payroll_record_id')
            ->leftJoin('employees as e', 'e.id', '=', 'pr.employee_id')
            ->where('ft.type', 'payroll')
            ->whereBetween('ft.transacted_at', [$data['start_date'].' 00:00:00', $data['end_date'].' 23:59:59'])
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('e.id', $id));

        // Gross, deductions and days come from the linked payroll record; unassigned entries have none.
        $employees = (clone $query)->selectRaw('e.id as employee_id, e.name, e.position, COUNT(*) as payments, SUM(ft.amount) as amount, SUM(pr.gross_pay) as gross, SUM(pr.deductions) as deductions, SUM(pr.days_worked) as days_worked')
            ->groupBy('e.id', 'e.name', 'e.position')->orderByDesc('amount')->get()
            ->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'name' => $row->name ?? 'Unassigned payroll',
                'position' => $row->position,
                'payments' => (int) $row->payments,
                'days_worked' => round((float) $row->days_worked, 1),
                'gross' => round((float) $row->gross, 2),
                'deductions' => round((float) $row->deductions, 2),
                'amount' => round((float) $row->amount, 2),
            ]);
        $total = round((float) $employees->sum('amount'), 2);
        $daily = (clone $query)->selectRaw('DATE(ft.transacted_at) as date, SUM(ft.amount) as amount')
            ->groupByRaw('DATE(ft.transacted_at)')->orderBy('date')->get()
            ->map(fn ($row) => ['date' => $row->date, 'amount' => round((float) $row->amount, 2)]);

        return response()->json([
            'period' => ['start' => $data['start_date'], 'end' => $data['end_date']],
            'total' => $total,
            'gross_total' => round((float) $employees->sum('gross'), 2),
            'deductions_total' => round((float) $employees->sum('deductions'), 2),
            'payment_count' => (int) $employees->sum('payments'),
            'employee_count' => $employees->whereNotNull('employee_id')->count(),
            'unassigned' => round((float) $employees->whereNull('employee_id')->sum('amount'), 2),
            'employees' => $employees,
            'daily' => $daily,
        ]);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'  => 'required|exists:employees,id',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'days_worked'  => 'required|numeric|min:0',
            'gross_pay'    => 'required|numeric|min:0',
            'deductions'   => 'nullable|numeric|min:0|lte:gross_pay',
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
            $payrollRecord = PayrollRecord::whereKey($payrollRecord->id)->lockForUpdate()->firstOrFail();
            abort_if($payrollRecord->status === 'paid' || $payrollRecord->financial_transaction_id, 422, 'Already marked as paid.');
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
