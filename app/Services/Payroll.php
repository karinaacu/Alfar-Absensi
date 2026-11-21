<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Payroll
{
    /** @return array<string,mixed> */
    public function calculate(\stdClass $run, \stdClass $item): array
    {
        $employee = DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->join('positions', 'positions.id', '=', 'employees.position_id')->leftJoin('departments', 'departments.id', '=', 'employees.department_id')->where('employees.id', $item->employee_id)->select('users.name', 'positions.name as position', 'departments.name as department')->first();
        $salary = DB::table('salary_profiles')->where('employee_id', $item->employee_id)->first();
        $late = (int) DB::table('attendances')->where('employee_id', $item->employee_id)->whereBetween('work_date', [$run->starts_on, $run->ends_on])->sum('late_minutes');
        $overtime = (int) DB::table('overtime_requests')->where('employee_id', $item->employee_id)->whereBetween('work_date', [$run->starts_on, $run->ends_on])->where('status', 'approved')->sum('minutes');
        $pending = (int) DB::table('overtime_requests')->where('employee_id', $item->employee_id)->whereBetween('work_date', [$run->starts_on, $run->ends_on])->where('status', 'pending')->count();
        $components = [...json_decode($salary->components ?? '[]', true), ...json_decode($item->adjustments, true)];
        $earnings = 0;
        $deductions = 0;
        foreach ($components as $c) {
            if ($c['type'] === 'earning') {
                $earnings += (int) $c['amount'];
            } else {
                $deductions += (int) $c['amount'];
            }
        }
        $lateDeduction = max(0, $late - $run->free_late_minutes) * (int) ($run->late_rate ?? 0);
        if ($run->late_cap !== null) {
            $lateDeduction = min($lateDeduction, (int) $run->late_cap);
        }
        $overtimePay = intdiv($overtime * (int) ($run->overtime_rate ?? 0) + 30, 60);
        $base = (int) ($salary->base_salary ?? 0);
        $gross = $base + $earnings + $overtimePay;
        $deductions += $lateDeduction;

        return ['name' => $employee->name, 'position' => $employee->position, 'department' => $employee->department ?? '—', 'base_salary' => $base, 'components' => $components, 'late_minutes' => $late, 'charged_late_minutes' => max(0, $late - $run->free_late_minutes), 'late_deduction' => $lateDeduction, 'overtime_minutes' => $overtime, 'overtime_pay' => $overtimePay, 'pending_overtime' => $pending, 'gross' => $gross, 'deductions' => $deductions, 'net' => $gross - $deductions, 'zone' => $run->safe_minutes === null || $run->warning_minutes === null ? 'unconfigured' : ($late <= $run->safe_minutes ? 'safe' : ($late <= $run->warning_minutes ? 'warning' : 'red')), 'salary_configured' => $salary !== null, 'policy' => ['safe_minutes' => $run->safe_minutes, 'warning_minutes' => $run->warning_minutes, 'free_late_minutes' => $run->free_late_minutes, 'late_rate' => $run->late_rate, 'late_cap' => $run->late_cap, 'overtime_rate' => $run->overtime_rate], 'period' => ['name' => $run->name, 'starts_on' => $run->starts_on, 'ends_on' => $run->ends_on, 'pays_on' => $run->pays_on]];
    }

    /** @param array<string,mixed> $snapshot */
    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function recalculate(\stdClass $run): void
    {
        foreach (DB::table('payroll_items')->where('payroll_run_id', $run->id)->orderBy('employee_id')->get() as $item) {
            DB::table('employees')->where('id', $item->employee_id)->lockForUpdate()->first();
            $snapshot = $this->calculate($run, $item);
            DB::table('payroll_items')->where('id', $item->id)->update(['snapshot' => json_encode($snapshot), 'source_hash' => $this->fingerprint($snapshot), 'updated_at' => now()]);
        }
    }

    public function publish(\stdClass $run, int $userId): void
    {
        if ($run->ends_on > now()->toDateString()) {
            throw ValidationException::withMessages(['payroll' => 'Periode penggajian belum selesai. Periksa tanggal akhir periode.']);
        }
        if ($run->safe_minutes === null || $run->warning_minutes === null || $run->late_rate === null || $run->overtime_rate === null) {
            throw ValidationException::withMessages(['payroll' => 'Isi batas zona, tarif potongan, dan tarif lembur terlebih dahulu. Isi 0 jika tidak diberlakukan.']);
        }
        foreach (DB::table('payroll_items')->where('payroll_run_id', $run->id)->orderBy('employee_id')->get() as $item) {
            DB::table('employees')->where('id', $item->employee_id)->lockForUpdate()->first();
            $snapshot = $this->calculate($run, $item);
            if (DB::table('schedules')->where('employee_id', $item->employee_id)->whereBetween('work_date', [$run->starts_on, $run->ends_on])->where('scheduled_out', '>', now())->exists()) {
                throw ValidationException::withMessages(['payroll' => 'Masih ada shift yang belum selesai untuk '.$snapshot['name'].'.']);
            }
            if (! $snapshot['salary_configured'] || $snapshot['net'] < 0 || $snapshot['pending_overtime'] > 0) {
                throw ValidationException::withMessages(['payroll' => 'Periksa '.$snapshot['name'].': gaji harus diisi, gaji bersih tidak boleh negatif, dan semua lembur harus ditinjau.']);
            }
            if ($item->source_hash !== $this->fingerprint($snapshot)) {
                throw ValidationException::withMessages(['payroll' => 'Sumber perhitungan berubah. Hitung ulang dan periksa draf sebelum menerbitkan slip.']);
            }
        }
        DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'published', 'published_by' => $userId, 'published_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('payroll_items')->where('payroll_run_id', $run->id)->get() as $item) {
            Access::audit($userId, 'payroll.publish', 'payroll:'.$run->id, null, ['employee_id' => $item->employee_id, 'period' => $run->name]);
        }
    }
}
