<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Performance
{
    /** @return array<string, mixed> */
    public function report(int $employeeId, int $periodId): array
    {
        $employee = DB::table('employees')->join('positions', 'positions.id', '=', 'employees.position_id')->select('employees.*', DB::raw('COALESCE(employees.department_id, positions.department_id) as department_id'))->where('employees.id', $employeeId)->first();
        abort_unless($employee !== null, 404);
        $period = DB::table('periods')->where('id', (int) ($periodId))->first();
        abort_unless($period !== null, 404);
        $shifts = DB::table('schedules')->where('employee_id', $employeeId)->whereBetween('work_date', [$period->starts_on, $period->ends_on])->pluck('shift_id')->unique()->all();
        $targets = DB::table('targets')->where('period_id', $periodId)->where(function ($q) use ($employeeId, $employee, $shifts) {
            $q->where('employee_id', $employeeId)->orWhere(function ($q) use ($employee, $shifts) {
                $q->whereNull('employee_id')->where('department_id', $employee->department_id)->where(fn ($q) => $q->whereNull('shift_id')->orWhereIn('shift_id', $shifts));
            });
        })->get()->map(function ($t) {
            $definition = json_decode($t->definition, true);
            $s = DB::table('submissions')->where('target_id', $t->id)->first();
            $score = null;
            if ($s && $s->status === 'validated') {
                $score = $definition['direction'] === 'higher' ? (float) $s->actual / (float) $t->target * 100 : ((float) $s->actual == 0 ? (float) $definition['score_cap'] : (float) $t->target / (float) $s->actual * 100);
                $score = round(min((float) $definition['score_cap'], $score), 2);
            }

            return [...(array) $t, 'definition' => $definition, 'submission' => $s, 'score' => $score, 'complete' => $score !== null];
        });
        $individual = $targets->whereNotNull('employee_id');
        $complete = $individual->count() > 0 && $individual->every(fn ($t) => $t['complete']);
        $weight = $individual->sum('weight');
        $score = $complete && abs($weight - 100) < 0.01 ? round($individual->sum(fn ($t) => $t['score'] * $t['weight']) / 100, 2) : null;
        $schedules = DB::table('schedules')->where('employee_id', $employeeId)->whereBetween('work_date', [$period->starts_on, $period->ends_on])->get();
        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->whereBetween('work_date', [$period->starts_on, $period->ends_on])->get();
        $scheduledDates = $schedules->pluck('work_date')->all();
        $scheduledAttendance = $attendance->whereIn('work_date', $scheduledDates);
        $pastDates = $schedules->where('work_date', '<', now()->toDateString())->pluck('work_date')->all();

        return ['targets' => $targets->values()->all(), 'score' => $score, 'weight' => $weight, 'complete' => $complete && abs($weight - 100) < 0.01,
            'attendance' => ['scheduled' => $schedules->count(), 'present' => $scheduledAttendance->where('status', 'present')->count(), 'leave' => $scheduledAttendance->where('status', 'leave')->count(), 'sick' => $scheduledAttendance->where('status', 'sick')->count(), 'permission' => $scheduledAttendance->where('status', 'permission')->count(), 'late' => $scheduledAttendance->where('late_minutes', '>', 0)->count(), 'early' => $scheduledAttendance->where('early_minutes', '>', 0)->count(), 'unexcused' => count(array_diff($pastDates, $scheduledAttendance->pluck('work_date')->all())), 'incomplete' => $scheduledAttendance->where('status', 'present')->whereNull('clock_out')->count()],
            'shift_ids' => array_values($shifts), 'period' => $period];
    }
}
