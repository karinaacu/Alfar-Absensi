<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Attendance
{
    /** @return array{record: \stdClass, event: string} */
    public function automaticRecord(int $employeeId, string $source, CarbonImmutable $time): array
    {
        return DB::transaction(function () use ($employeeId, $source, $time) {
            $employee = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($employee !== null, 422, 'Profil karyawan tidak aktif.');
            $schedules = DB::table('schedules')->where('employee_id', $employeeId)->where('scheduled_in', '<=', $time->addHours(6))->orderByDesc('scheduled_in')->get();
            $candidates = [];
            foreach ($schedules as $schedule) {
                $start = CarbonImmutable::parse($schedule->scheduled_in);
                $end = CarbonImmutable::parse($schedule->scheduled_out);
                if ($time->lt($start->subMinutes($schedule->checkin_early_minutes))) {
                    continue;
                }
                $existing = DB::table('attendances')->where('employee_id', $employeeId)->where('work_date', $schedule->work_date)->first();
                if (! $existing?->clock_in && $time->toDateString() > $end->toDateString()) {
                    continue;
                }
                $threshold = $start->addMinutes(min($schedule->checkout_after_minutes, (int) $start->diffInMinutes($end)));
                $event = $existing?->clock_in ? 'out' : 'in';
                $candidates[] = ['schedule' => $schedule, 'existing' => $existing, 'threshold' => $threshold, 'event' => $event, 'distance' => abs($time->diffInSeconds($start))];
            }
            usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);
            if (! $candidates) {
                throw ValidationException::withMessages(['attendance' => 'Belum berada dalam waktu absensi. Periksa jadwal kerja Anda.']);
            }
            if (count($candidates) > 1 && $candidates[0]['distance'] === $candidates[1]['distance']) {
                throw ValidationException::withMessages(['attendance' => 'Jadwal bertumpuk. Hubungi pengelola untuk memperbaiki jadwal.']);
            }
            $chosen = $candidates[0];
            $existing = $chosen['existing'];
            $event = $chosen['event'];
            if ($existing?->clock_out) {
                throw ValidationException::withMessages(['attendance' => 'Absensi masuk dan pulang sudah tercatat.']);
            }
            if ($event === 'out' && ($time->lt($chosen['threshold']) || $time->lt(CarbonImmutable::parse($existing->clock_in)->addMinute()))) {
                throw ValidationException::withMessages(['attendance' => 'Absen masuk sudah tercatat. Absen pulang tersedia mulai '.$chosen['threshold']->format('H:i').'.']);
            }

            return ['record' => $this->record($employeeId, $event, 'wfo', $source, $time, $chosen['schedule']->work_date), 'event' => $event];
        });
    }

    public function record(int $employeeId, string $event, string $mode, string $source, CarbonImmutable $time, ?string $workDate = null): \stdClass
    {
        return DB::transaction(function () use ($employeeId, $event, $mode, $source, $time, $workDate) {
            $employee = DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            abort_unless($employee !== null && $employee->deleted_at === null, 404);
            $user = DB::table('users')->where('id', (int) ($employee->user_id))->first();
            abort_unless($user?->active, 422, 'Akun karyawan tidak aktif.');
            $schedule = DB::table('schedules')->where('employee_id', $employeeId)
                ->when($workDate, fn ($q) => $q->where('work_date', $workDate), fn ($q) => $q->where('scheduled_in', '<=', $time->addHours(6))->where('scheduled_out', '>=', $time->subHours(6)))
                ->orderBy('scheduled_in')->get();
            if ($schedule->count() !== 1) {
                throw ValidationException::withMessages(['attendance' => 'Jadwal tidak ditemukan atau ambigu. Tentukan tanggal kerja.']);
            }
            $schedule = $schedule->first();
            if ($time->lt(CarbonImmutable::parse($schedule->scheduled_in)->subHours(6))) {
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
