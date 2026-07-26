<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RentalStall;
use App\Models\RentalTenant;
use App\Models\RentalSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RentalController extends Controller
{
    // ─── Stalls ──────────────────────────────────────────────────────────────

    public function stallsIndex(): JsonResponse
    {
        $stalls = RentalStall::orderBy('number')->get();
        return response()->json($stalls);
    }

    public function stallUpdate(RentalStall $stall): JsonResponse
    {
        $data = request()->validate([
            'label'       => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
        ]);
        $stall->update($data);
        return response()->json($stall->fresh());
    }

    // ─── Tenants ─────────────────────────────────────────────────────────────

    public function tenantsIndex(): JsonResponse
    {
        $tenants = RentalTenant::orderBy('name')->get();
        return response()->json($tenants);
    }

    public function tenantStore(): JsonResponse
    {
        $data = request()->validate([
            'name'           => 'required|string|max:150',
            'business_name'  => 'nullable|string|max:150',
            'contact_number' => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:150',
            'notes'          => 'nullable|string|max:500',
        ]);
        $tenant = RentalTenant::create($data);
        return response()->json($tenant, 201);
    }

    public function tenantUpdate(RentalTenant $tenant): JsonResponse
    {
        $data = request()->validate([
            'name'           => 'sometimes|string|max:150',
            'business_name'  => 'nullable|string|max:150',
            'contact_number' => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:150',
            'notes'          => 'nullable|string|max:500',
        ]);
        $tenant->update($data);
        return response()->json($tenant->fresh());
    }

    public function tenantDestroy(RentalTenant $tenant): JsonResponse
    {
        abort_if(
            $tenant->schedules()->whereIn('status', ['reserved', 'confirmed'])->exists(),
            422,
            'Cannot delete a tenant with active schedules.'
        );
        $tenant->delete();
        return response()->json(['ok' => true]);
    }

    // ─── Schedules ────────────────────────────────────────────────────────────

    /**
     * Daily view: stalls with their schedule for a given date.
     */
    public function schedulesDay(): JsonResponse
    {
        $date = request()->input('date', Carbon::today()->toDateString());

        $stalls = RentalStall::orderBy('number')
            ->with(['schedules' => function ($q) use ($date) {
                $q->whereIn('status', ['confirmed', 'reserved', 'maintenance'])
                  ->where('start_date', '<=', $date)
                  ->where('end_date', '>=', $date)
                  ->with('tenant');
            }])
            ->get()
            ->map(fn ($stall) => $this->formatStallWithSchedule($stall, $date));

        return response()->json($stalls);
    }

    /**
     * Calendar view: for a given month/year, return a day-keyed map of stall statuses.
     */
    public function schedulesCalendar(): JsonResponse
    {
        $year  = (int) request()->input('year', now()->year);
        $month = (int) request()->input('month', now()->month);

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $schedules = RentalSchedule::whereIn('status', ['confirmed', 'reserved', 'maintenance'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->with(['stall', 'tenant'])
            ->get();

        $days = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $dateStr = $cursor->toDateString();
            $daySched = $schedules->filter(
                fn ($s) => $s->start_date->lte($cursor) && $s->end_date->gte($cursor)
            );

            $days[$dateStr] = [
                'date'         => $dateStr,
                'total_stalls' => 5,
                'occupied'     => $daySched->where('status', 'confirmed')->count(),
                'reserved'     => $daySched->where('status', 'reserved')->count(),
                'maintenance'  => $daySched->where('status', 'maintenance')->count(),
                'available'    => max(0, 5 - $daySched->count()),
            ];
            $cursor->addDay();
        }

        return response()->json($days);
    }

    /**
     * Timeline view: schedules for a date range, grouped by stall.
     */
    public function schedulesTimeline(): JsonResponse
    {
        $dateFrom = request()->input('date_from', Carbon::today()->toDateString());
        $dateTo   = request()->input('date_to', Carbon::today()->addDays(13)->toDateString());

        $stalls = RentalStall::orderBy('number')
            ->with(['schedules' => function ($q) use ($dateFrom, $dateTo) {
                $q->whereIn('status', ['confirmed', 'reserved', 'maintenance', 'cancelled'])
                  ->where('start_date', '<=', $dateTo)
                  ->where('end_date', '>=', $dateFrom)
                  ->with('tenant')
                  ->orderBy('start_date');
            }])
            ->get()
            ->map(fn ($stall) => [
                'id'          => $stall->id,
                'number'      => $stall->number,
                'label'       => $stall->label,
                'is_active'   => $stall->is_active,
                'schedules'   => $stall->schedules->map(fn ($s) => $this->formatSchedule($s)),
            ]);

        return response()->json([
            'stalls'    => $stalls,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }

    public function scheduleStore(): JsonResponse
    {
        $data = request()->validate([
            'stall_id'    => 'required|exists:rental_stalls,id',
            'tenant_id'   => 'required|exists:rental_tenants,id',
            'rental_type' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'status'      => ['required', Rule::in(['reserved', 'confirmed', 'maintenance'])],
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'start_time'  => 'nullable|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i',
            'price'       => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);

        $this->checkOverlap($data['stall_id'], $data['start_date'], $data['end_date']);

        $schedule = RentalSchedule::create($data);
        return response()->json($this->formatSchedule($schedule->load(['stall', 'tenant'])), 201);
    }

    public function scheduleUpdate(RentalSchedule $schedule): JsonResponse
    {
        $data = request()->validate([
            'stall_id'    => 'sometimes|exists:rental_stalls,id',
            'tenant_id'   => 'sometimes|exists:rental_tenants,id',
            'rental_type' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'status'      => ['sometimes', Rule::in(['reserved', 'confirmed', 'cancelled', 'maintenance'])],
            'start_date'  => 'sometimes|date',
            'end_date'    => 'sometimes|date',
            'start_time'  => 'nullable|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i',
            'price'       => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);

        $stallId   = $data['stall_id']   ?? $schedule->stall_id;
        $startDate = $data['start_date'] ?? $schedule->start_date->toDateString();
        $endDate   = $data['end_date']   ?? $schedule->end_date->toDateString();

        $this->checkOverlap($stallId, $startDate, $endDate, $schedule->id);

        $schedule->update($data);
        return response()->json($this->formatSchedule($schedule->fresh()->load(['stall', 'tenant'])));
    }

    public function scheduleDestroy(RentalSchedule $schedule): JsonResponse
    {
        $schedule->delete();
        return response()->json(['ok' => true]);
    }

    // ─── Stats ────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $monthEnd   = Carbon::today()->endOfMonth()->toDateString();

        $todaySchedules = RentalSchedule::whereIn('status', ['confirmed', 'reserved', 'maintenance'])
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->get();

        $monthRevenue = RentalSchedule::where('status', 'confirmed')
            ->where('start_date', '<=', $monthEnd)
            ->where('end_date', '>=', $monthStart)
            ->sum('price');

        $totalTenants = RentalTenant::count();
        $activeStalls = RentalStall::where('is_active', true)->count();

        return response()->json([
            'today' => [
                'occupied'    => $todaySchedules->where('status', 'confirmed')->count(),
                'reserved'    => $todaySchedules->where('status', 'reserved')->count(),
                'maintenance' => $todaySchedules->where('status', 'maintenance')->count(),
                'available'   => max(0, $activeStalls - $todaySchedules->count()),
            ],
            'month_revenue' => (float) $monthRevenue,
            'total_tenants' => $totalTenants,
            'active_stalls' => $activeStalls,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function formatStallWithSchedule(RentalStall $stall, string $date): array
    {
        $schedule = $stall->schedules->first();

        if (!$stall->is_active) {
            $displayStatus = 'inactive';
        } elseif (!$schedule) {
            $displayStatus = 'available';
        } elseif ($schedule->status === 'confirmed') {
            $displayStatus = 'occupied';
        } elseif ($schedule->status === 'reserved') {
            $displayStatus = 'reserved';
        } elseif ($schedule->status === 'maintenance') {
            $displayStatus = 'maintenance';
        } else {
            $displayStatus = 'available';
        }

        return [
            'id'             => $stall->id,
            'number'         => $stall->number,
            'label'          => $stall->label,
            'description'    => $stall->description,
            'is_active'      => $stall->is_active,
            'display_status' => $displayStatus,
            'schedule'       => $schedule ? $this->formatSchedule($schedule) : null,
        ];
    }

    private function formatSchedule(RentalSchedule $s): array
    {
        return [
            'id'          => $s->id,
            'stall_id'    => $s->stall_id,
            'tenant_id'   => $s->tenant_id,
            'rental_type' => $s->rental_type,
            'status'      => $s->status,
            'start_date'  => $s->start_date?->toDateString(),
            'end_date'    => $s->end_date?->toDateString(),
            'start_time'  => $s->start_time,
            'end_time'    => $s->end_time,
            'price'       => (float) $s->price,
            'notes'       => $s->notes,
            'tenant'      => $s->relationLoaded('tenant') ? [
                'id'            => $s->tenant?->id,
                'name'          => $s->tenant?->name,
                'business_name' => $s->tenant?->business_name,
                'contact_number'=> $s->tenant?->contact_number,
                'email'         => $s->tenant?->email,
            ] : null,
            'stall'       => $s->relationLoaded('stall') ? [
                'id'     => $s->stall?->id,
                'number' => $s->stall?->number,
                'label'  => $s->stall?->label,
            ] : null,
        ];
    }

    private function checkOverlap(int $stallId, string $startDate, string $endDate, ?int $excludeId = null): void
    {
        $q = RentalSchedule::where('stall_id', $stallId)
            ->whereIn('status', ['reserved', 'confirmed', 'maintenance'])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeId) {
            $q->where('id', '!=', $excludeId);
        }

        abort_if($q->exists(), 422, 'This stall already has an active schedule overlapping the selected dates.');
    }
}
