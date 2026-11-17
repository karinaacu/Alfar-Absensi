<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ActivityNotifications
{
    public const RETAIN = 200;

    public static function prune(int $userId): void
    {
        $cutoff = DB::table('employee_notifications')->where('user_id', $userId)->orderByDesc('id')->skip(self::RETAIN - 1)->limit(1)->value('id');
        if ($cutoff !== null) {
            DB::table('employee_notifications')->where('user_id', $userId)->where('id', '<', $cutoff)->delete();
        }
    }

    public static function fromAudit(int $auditId, ?int $actor, string $action, string $subject, mixed $before, mixed $after): void
    {
        // These technical logs describe the same attendance already notified by attendance.in/out.
        if (in_array($action, ['qr.scan', 'fingerprint.receive'])) {
            return;
        }
        $data = (array) ($after ?? []);
        $previous = (array) ($before ?? []);
        if (in_array($action, ['attendance.in', 'attendance.out']) && ($data['source'] ?? '') === 'correction') {
            return;
        }
        $recipients = [];
        $title = match ($action) {
            'attendance.in' => 'Absen masuk berhasil', 'attendance.out' => 'Absen pulang berhasil',
            'attendance.correct' => 'Koreksi absensi disetujui', 'attendance.import' => 'Impor absensi berhasil',
            'request.create' => 'Pengajuan berhasil dikirim', 'request.decide' => 'Pengajuan telah ditinjau',
            'kpi.submit' => 'Realisasi kinerja berhasil dikirim', 'kpi.validate' => 'Realisasi kinerja telah ditinjau',
            'kpi.correct' => 'Realisasi kinerja telah dikoreksi', 'review.publish' => 'Laporan kinerja diterbitkan',
            'review.draft' => 'Draft penilaian berhasil disimpan', 'review.respond' => 'Tanggapan penilaian berhasil disimpan',
            'accounts.save' => 'Akun karyawan berhasil diperbarui', 'role-users.save' => 'Peran akun berhasil diperbarui',
            'roles.save' => 'Peran dan hak akses berhasil disimpan', 'employees.save' => 'Profil karyawan berhasil diperbarui',
            'departments.save' => 'Divisi berhasil disimpan', 'positions.save' => 'Jabatan berhasil disimpan',
            'shifts.save' => 'Shift kerja berhasil disimpan', 'schedules.save', 'schedules.bulk' => 'Jadwal kerja berhasil ditetapkan',
            'periods.save','period-status.save' => 'Periode penilaian berhasil diperbarui',
            'metrics.save' => 'Indikator kinerja berhasil disimpan', 'targets.save' => 'Target kinerja berhasil disimpan',
            'devices.save' => 'Perangkat absensi berhasil disimpan', 'device-mappings.save' => 'Identitas perangkat berhasil dihubungkan',
            'schedules.delete' => 'Jadwal kerja dihapus', 'accounts.delete' => 'Akun karyawan dihapus', 'positions.delete' => 'Jabatan dihapus', 'employees.delete' => 'Data karyawan dihapus',
            'password.change' => 'Password berhasil diganti', default => 'Aktivitas berhasil disimpan',
        };
        $body = $title.'.';
        if ($action === 'schedules.bulk') {
            $recipients = [$actor, ...DB::table('employees')->whereIn('id', $data['employee_ids'] ?? [])->pluck('user_id')->all()];
            $body = 'Jadwal kerja untuk rentang '.($data['starts_on'] ?? '').' hingga '.($data['ends_on'] ?? '').' telah diperbarui. Periksa jadwal kerja Anda.';
        } elseif (in_array($action, ['attendance.in', 'attendance.out'])) {
            $employee = (int) ($data['employee_id'] ?? 0);
            $recipients[] = DB::table('employees')->where('id', $employee)->value('user_id');
            $source = match ($data['source'] ?? '') {
                'fingerprint' => 'sidik jari','laptop_camera' => 'kamera laptop','usb_scanner' => 'scanner QR','phone_camera' => 'kamera HP','excel' => 'impor Excel','web' => 'aplikasi WFH',default => 'aplikasi'
            };
            $at = $action === 'attendance.in' ? ($data['clock_in'] ?? '') : ($data['clock_out'] ?? '');
            $body = 'Absensi '.strtoupper($data['mode'] ?? 'wfo').' tanggal '.($data['work_date'] ?? '').' pukul '.substr((string) $at, 11, 8).' tercatat melalui '.$source.'.';
        } elseif (str_starts_with($action, 'request.')) {
            $employee = (int) ($data['employee_id'] ?? $previous['employee_id'] ?? 0);
            if (! $employee && preg_match('/^request:(\d+)/', $subject, $m)) {
                $employee = (int) DB::table('requests')->where('id', (int) $m[1])->value('employee_id');
            }
            $recipients[] = DB::table('employees')->where('id', $employee)->value('user_id');
            if ($action === 'request.create') {
                $supervisor = DB::table('employees')->where('id', $employee)->value('supervisor_id');
                $recipients[] = DB::table('employees')->where('id', $supervisor)->value('user_id');
            }
            $status = match ($data['status'] ?? 'pending') {
                'approved' => 'disetujui','rejected' => 'ditolak',default => 'menunggu persetujuan'
            };
            $body = $action === 'request.decide' ? 'Pengajuan Anda '.$status.'. '.($data['decision_note'] ?? '') : 'Pengajuan tanggal '.($data['work_date'] ?? '').' berhasil dicatat dan menunggu persetujuan.';
        } elseif ($action === 'review.publish' && preg_match('/employee:(\d+)/', $subject, $m)) {
            $recipients[] = DB::table('employees')->where('id', (int) $m[1])->value('user_id');
            $body = 'Laporan kinerja Anda sudah diterbitkan. Buka laporan untuk melihat hasil penilaian dan tindak lanjut.';
        } elseif ($action === 'review.respond' && preg_match('/review:(\d+)/', $subject, $m)) {
            $recipients[] = $actor;
            $recipients[] = DB::table('reviews')->where('id', (int) $m[1])->value('reviewer_id');
        } elseif (str_starts_with($action, 'kpi.')) {
            $targetId = 0;
            if (preg_match('/target:(\d+)/', $subject, $m)) {
                $targetId = (int) $m[1];
            } elseif (preg_match('/submission:(\d+)/', $subject, $m)) {
                $targetId = (int) DB::table('submissions')->where('id', (int) $m[1])->value('target_id');
            }
            $target = DB::table('targets')->where('id', $targetId)->first();
            if ($target?->employee_id) {
                $recipients[] = DB::table('employees')->where('id', $target->employee_id)->value('user_id');
            } elseif ($target) {
                $period = DB::table('periods')->where('id', $target->period_id)->first();
                $team = DB::table('employees')->join('positions', 'positions.id', '=', 'employees.position_id')->whereRaw('COALESCE(employees.department_id, positions.department_id) = ?', [$target->department_id])->whereNull('employees.deleted_at');
                if ($target->shift_id && $period) {
                    $team->whereIn('employees.id', DB::table('schedules')->select('employee_id')->where('shift_id', $target->shift_id)->whereBetween('work_date', [$period->starts_on, $period->ends_on]));
                }
                $recipients = [...$recipients, ...$team->pluck('employees.user_id')->all()];
            }
            if (($data['status'] ?? '') === 'rejected') {
                $body = 'Realisasi kinerja perlu diperbaiki. Buka target dan realisasi untuk meninjau catatan supervisor.';
            }
        } elseif ($action === 'attendance.correct') {
            $recipients[] = DB::table('employees')->where('id', (int) ($data['employee_id'] ?? 0))->value('user_id');
        } else {
            // Other successful administrative actions notify the actor and affected account only.
            $recipients[] = $actor;
            if (isset($data['employee_id'])) {
                $recipients[] = DB::table('employees')->where('id', (int) $data['employee_id'])->value('user_id');
            }
            if (isset($data['user_id'])) {
                $recipients[] = (int) $data['user_id'];
            }
            if ($action === 'accounts.save' && preg_match('/user:(\d+)/', $subject, $m)) {
                $recipients[] = (int) $m[1];
            }
        }
        foreach (array_unique(array_filter($recipients)) as $userId) {
            $userId = (int) $userId;
            if (! DB::table('users')->where('id', $userId)->exists()) {
                continue;
            }
            DB::table('employee_notifications')->insertOrIgnore(['user_id' => $userId, 'audit_id' => $auditId, 'event' => $action, 'title' => $title, 'body' => $body, 'read_at' => $actor === $userId ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            self::prune($userId);
        }
    }
}
