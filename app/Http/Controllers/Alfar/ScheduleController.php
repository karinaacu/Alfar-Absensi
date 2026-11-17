<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function bulk(Request $r, Access $access): RedirectResponse
    {
        abort_unless(in_array('all', $access->grants($r->user(), 'organization.manage'), true), 403);
        $data = $r->validate([
            'employee_ids' => 'required|array|min:1|max:2000', 'employee_ids.*' => 'required|integer|distinct',
            'shift_id' => 'required|integer|exists:shifts,id', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'required|integer|between:1,7|distinct',
            'existing' => 'required|in:skip,replace',
        ], ['employee_ids.required' => 'Pilih minimal satu karyawan.', 'weekdays.required' => 'Pilih minimal satu hari kerja.', 'ends_on.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.'], ['employee_ids' => 'karyawan', 'shift_id' => 'shift', 'starts_on' => 'tanggal mulai', 'ends_on' => 'tanggal akhir', 'weekdays' => 'hari kerja']);
        $start = CarbonImmutable::parse($data['starts_on']);
        $end = CarbonImmutable::parse($data['ends_on']);
        if ($start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['ends_on' => 'Rentang jadwal maksimal 366 hari.']);
        }
        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $data['weekdays'])) {
                $days[] = $day->toDateString();
            }
        }
        if (! $days) {
            throw ValidationException::withMessages(['weekdays' => 'Tidak ada tanggal yang sesuai dengan hari kerja pilihan.']);
        }
        if (count($days) * count($data['employee_ids']) > 50000) {
            throw ValidationException::withMessages(['ends_on' => 'Maksimal 50.000 jadwal per penyimpanan. Perpendek rentang atau kurangi jumlah karyawan.']);
        }
        $result = DB::transaction(function () use ($r, $data, $days) {
            $employees = DB::table('employees')->whereIn('id', $data['employee_ids'])->orderBy('id')->lockForUpdate()->get();
            $active = DB::table('users')->whereIn('id', $employees->pluck('user_id'))->where('active', true)->count();
            if ($employees->count() !== count($data['employee_ids']) || $active !== $employees->count()) {
                throw ValidationException::withMessages(['employee_ids' => 'Ada karyawan tidak aktif atau tidak ditemukan. Perbarui pilihan karyawan.']);
            }
            $shift = DB::table('shifts')->where('id', $data['shift_id'])->lockForUpdate()->first();
            abort_unless($shift !== null, 422, 'Shift tidak ditemukan.');
            $protected = [];
            foreach (DB::table('attendances')->whereIn('employee_id', $data['employee_ids'])->whereBetween('work_date', [$data['starts_on'], $data['ends_on']])->select('employee_id', 'work_date')->get() as $row) {
                $protected[$row->employee_id.'|'.$row->work_date] = true;
            }
            $existing = [];
            foreach (DB::table('schedules')->whereIn('employee_id', $data['employee_ids'])->whereBetween('work_date', [$data['starts_on'], $data['ends_on']])->get() as $row) {
                $existing[$row->employee_id.'|'.$row->work_date] = $row;
            }
            $created = 0;
            $updated = 0;
            $locked = 0;
            $skipped = 0;
            $rows = [];
            $before = [];
            $affected = [];
            $stamp = now();
            /** @param list<array<string, mixed>> $batch */
            $flush = static function (array &$batch): void {
                if (count($batch) === 0) {
                    return;
                }
                DB::table('schedules')->upsert($batch, ['employee_id', 'work_date'], ['shift_id', 'scheduled_in', 'scheduled_out', 'tolerance_minutes', 'updated_at']);
                $batch = [];
            };
            foreach ($employees as $employee) {
                foreach ($days as $date) {
                    $key = $employee->id.'|'.$date;
                    if (isset($protected[$key])) {
                        $locked++;

                        continue;
                    }
                    $old = $existing[$key] ?? null;
                    if ($old && $data['existing'] === 'skip') {
                        $skipped++;

                        continue;
                    }
                    $in = CarbonImmutable::parse($date.' '.$shift->starts_at);
                    $out = CarbonImmutable::parse($date.' '.$shift->ends_at);
                    if ($out->lte($in)) {
                        $out = $out->addDay();
                    }
                    if ($old && (int) $old->shift_id === (int) $shift->id && $old->scheduled_in === $in->format('Y-m-d H:i:s') && $old->scheduled_out === $out->format('Y-m-d H:i:s') && (int) $old->tolerance_minutes === (int) $shift->tolerance_minutes) {
                        $skipped++;

                        continue;
                    }
                    if ($old) {
                        $updated++;
                        $before[] = (array) $old;
                    } else {
                        $created++;
                    }
                    $affected[$employee->id] = $employee->id;
                    $rows[] = ['employee_id' => $employee->id, 'work_date' => $date, 'shift_id' => $shift->id, 'scheduled_in' => $in->format('Y-m-d H:i:s'), 'scheduled_out' => $out->format('Y-m-d H:i:s'), 'tolerance_minutes' => $shift->tolerance_minutes, 'created_at' => $stamp, 'updated_at' => $stamp];
                    if (count($rows) >= 500) {
                        $flush($rows);
                    }
                }
            }
            $flush($rows);
            if ($created + $updated > 0) {
                Access::audit($r->user()->id, 'schedules.bulk', 'shift:'.$shift->id, $before, ['employee_ids' => array_values($affected), 'shift_id' => $shift->id, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'weekdays' => $data['weekdays'], 'existing' => $data['existing'], 'created' => $created, 'updated' => $updated, 'locked' => $locked, 'skipped' => $skipped]);
            }

            return compact('created', 'updated', 'locked', 'skipped');
        });

        return back()->with('success', "Jadwal tersimpan: {$result['created']} dibuat, {$result['updated']} diperbarui, {$result['skipped']} jadwal lama dipertahankan, dan {$result['locked']} jadwal berabsensi dilindungi.");
    }
}
