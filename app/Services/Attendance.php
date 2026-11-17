<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Attendance
{
    public function record(int $employeeId, string $event, string $mode, string $source, CarbonImmutable $time, ?string $workDate = null): \stdClass
    {
        return DB::transaction(function () use ($employeeId, $event, $mode, $source, $time, $workDate) {
            $employee = DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            abort_unless($employee !== null, 404);
            $user = DB::table('users')->where('id', (int) ($employee->user_id))->first();
            abort_unless($user?->active, 422, 'Akun karyawan tidak aktif.');
            $schedule = DB::table('schedules')->where('employee_id', $employeeId)
                ->when($workDate, fn ($q) => $q->where('work_date', $workDate), fn ($q) => $q->where('scheduled_in', '<=', $time->addHours(6))->where('scheduled_out', '>=', $time->subHours(6)))
                ->orderBy('scheduled_in')->get();
            if ($schedule->count() !== 1) {
                throw ValidationException::withMessages(['attendance' => 'Jadwal tidak ditemukan atau ambigu. Tentukan tanggal kerja.']);
            }
            $schedule = $schedule->first();
            if ($time->lt(CarbonImmutable::parse($schedule->scheduled_in)->subHours(6)) || $time->gt(CarbonImmutable::parse($schedule->scheduled_out)->addHours(6))) {
                throw ValidationException::withMessages(['attendance' => 'Waktu di luar rentang jadwal.']);
            }
            if ($mode === 'wfo' && ! $employee->allow_wfo) {
                throw ValidationException::withMessages(['attendance' => 'Akun tidak memiliki akses WFO.']);
            }
            if ($mode === 'wfh' && ! $employee->allow_wfh && ! DB::table('requests')->where('employee_id', $employeeId)->where('type', 'wfh')->where('work_date', $schedule->work_date)->where('status', 'approved')->exists()) {
                throw ValidationException::withMessages(['attendance' => 'WFH memerlukan persetujuan supervisor.']);
            }
            $existing = DB::table('attendances')->where('employee_id', $employeeId)->where('work_date', $schedule->work_date)->first();
            if ($existing && ($existing->mode !== $mode || $existing->status !== 'present')) {
                throw ValidationException::withMessages(['attendance' => 'Mode/status berbeda dari catatan yang sudah ada. Ajukan koreksi.']);
            }
            if ($event === 'in' && $existing?->clock_in) {
                throw ValidationException::withMessages(['attendance' => 'Absen masuk sudah tercatat.']);
            }
            if ($event === 'out' && (! $existing?->clock_in || $existing->clock_out || $time->lte(CarbonImmutable::parse($existing->clock_in)))) {
                throw ValidationException::withMessages(['attendance' => 'Absen keluar tidak valid atau sudah tercatat.']);
            }
            $data = ['mode' => $mode, 'source' => $source, 'status' => 'present', 'updated_at' => now()];
            if ($event === 'in') {
                $data['clock_in'] = $time;
                $deadline = CarbonImmutable::parse($schedule->scheduled_in)->addMinutes($schedule->tolerance_minutes);
                $data['late_minutes'] = $time->gt($deadline) ? (int) ceil($deadline->diffInSeconds($time) / 60) : 0;
            } else {
                $data['clock_out'] = $time;
                $end = CarbonImmutable::parse($schedule->scheduled_out);
                $data['early_minutes'] = $time->lt($end) ? (int) ceil($time->diffInSeconds($end) / 60) : 0;
            }
            if ($existing) {
                DB::table('attendances')->where('id', $existing->id)->update($data);
            } else {
                DB::table('attendances')->insert([...$data, 'employee_id' => $employeeId, 'work_date' => $schedule->work_date, 'created_at' => now()]);
            }
            $result = DB::table('attendances')->where('employee_id', $employeeId)->where('work_date', $schedule->work_date)->first();
            Access::audit(auth()->id() === null ? null : (int) auth()->id(), 'attendance.'.$event, 'attendance:'.$result->id, $existing, $result);

            return $result;
        });
    }
}
