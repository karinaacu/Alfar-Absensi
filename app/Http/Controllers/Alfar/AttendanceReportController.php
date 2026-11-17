<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceReportController extends Controller
{
    public function index(Request $r, Access $a): JsonResponse
    {
        $a->authorize($r->user(), 'attendance.read');
        $data = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'search' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1']);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365, 422, 'Rentang maksimal 366 hari.');
        $query = DB::table('schedules')->join('employees', 'employees.id', '=', 'schedules.employee_id')->join('users', 'users.id', '=', 'employees.user_id')->join('positions', 'positions.id', '=', 'employees.position_id')->leftJoin('departments', 'departments.id', '=', DB::raw('COALESCE(employees.department_id, positions.department_id)'))->leftJoin('attendances', fn ($join) => $join->on('attendances.employee_id', '=', 'schedules.employee_id')->on('attendances.work_date', '=', 'schedules.work_date'))->whereIn('employees.id', $a->ids($r->user(), 'attendance.read'))->whereBetween('schedules.work_date', [$data['from'], $data['to']]);
        if (! empty($data['search'])) {
            $query->where(function ($q) use ($data) {
                foreach (['users.name', 'positions.name', 'departments.name'] as $column) {
                    $q->orWhere($column, 'like', '%'.$data['search'].'%');
                }
            });
        }
        $query->select('schedules.work_date', 'users.name', 'positions.name as position', 'departments.name as department', 'attendances.clock_in', 'attendances.clock_out', 'attendances.late_minutes', 'attendances.early_minutes')->selectRaw("COALESCE(attendances.status, CASE WHEN schedules.scheduled_out < ? THEN 'unexcused' ELSE 'scheduled' END) as status", [now()->toDateTimeString()]);

        return response()->json($query->orderByDesc('schedules.work_date')->orderBy('users.name')->orderBy('employees.id')->paginate(50));
    }
}
