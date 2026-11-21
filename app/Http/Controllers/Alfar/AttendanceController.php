<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use App\Services\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

class AttendanceController extends Controller
{
    public function qr(Request $r, Access $a): JsonResponse
    {
        $employee = $a->employee($r->user());
        abort_unless($employee !== null, 422, 'Akun belum terhubung dengan profil karyawan.');
        abort_unless($r->user()->active && $employee->allow_wfo, 403, 'Akun tidak memiliki akses absensi WFO.');
        $token = Str::random(64);
        $expires = now()->addSeconds(15);
        DB::transaction(function () use ($r, $employee, $token, $expires) {
            DB::table('employees')->where('id', $employee->id)->lockForUpdate()->first();
            // Retain used tokens; discard old unused images to keep rotation storage bounded.
            DB::table('qr_tokens')->where('employee_id', $employee->id)->where('expires_at', '<', now()->subDay())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('qr_uses')->whereColumn('qr_uses.qr_token_id', 'qr_tokens.id'))->delete();
            // Only the newest QR for this employee remains valid, including across tabs.
            DB::table('qr_tokens')->where('employee_id', $employee->id)->where('expires_at', '>', now())->update(['expires_at' => now()]);
            DB::table('qr_tokens')->insert(['employee_id' => $employee->id, 'token_hash' => hash('sha256', $token), 'issued_by' => $r->user()->id, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['expires_at' => $expires->toIso8601String(), 'url' => url('/dashboard?employee_qr='.$token), 'last_attendance' => DB::table('attendances')->where('employee_id', $employee->id)->select('work_date', 'clock_in', 'clock_out')->orderByDesc('work_date')->first()])->header('Cache-Control', 'no-store');
    }

    public function scan(Request $r, Access $a, Attendance $attendance): JsonResponse
    {
        $a->authorize($r->user(), 'attendance.scan');
        $data = $r->validate(['token' => 'required|string|size:64', 'device_id' => 'nullable|integer|exists:devices,id', 'work_date' => 'nullable|date_format:Y-m-d', 'input_method' => 'required|in:laptop_camera,usb_scanner,phone_camera']);

        return DB::transaction(function () use ($r, $a, $attendance, $data) {
            // Browser cameras and USB keyboard scanners are independent stations.
            // A registered device is optional; explicit hardware IDs must remain active.
            $device = null;
            if (! empty($data['device_id'])) {
                $expectedType = $data['input_method'] === 'phone_camera' ? 'phone_camera' : 'scanner';
                $device = DB::table('devices')->where('id', $data['device_id'])->where('active', true)->where('type', $expectedType)->first();
                abort_unless($device !== null, 422, 'Perangkat tidak aktif atau jenisnya tidak sesuai.');
            }
            // Read the immutable owner first; lock employee before token to match issuance order.
            $owner = DB::table('qr_tokens')->where('token_hash', hash('sha256', $data['token']))->first();
            if (! $owner?->employee_id) {
                throw ValidationException::withMessages(['token' => 'QR karyawan tidak valid. Buka QR pribadi terbaru.']);
            }
            $a->authorize($r->user(), 'attendance.scan', $owner->employee_id);
            $employee = DB::table('employees')->where('id', $owner->employee_id)->lockForUpdate()->first();
            $qr = DB::table('qr_tokens')->where('id', $owner->id)->lockForUpdate()->first();
            if (! $qr || CarbonImmutable::parse($qr->expires_at)->lte(now())) {
                throw ValidationException::withMessages(['token' => 'QR sudah kedaluwarsa. Tampilkan QR terbaru dari akun karyawan.']);
            }
            $user = DB::table('users')->where('id', $employee->user_id)->first();
            abort_unless($user && $user->active && $qr->issued_by === $user->id, 422, 'QR tidak lagi terhubung ke akun karyawan aktif.');
            if (DB::table('qr_uses')->where('qr_token_id', $qr->id)->exists()) {
                throw ValidationException::withMessages(['token' => 'QR ini sudah digunakan.']);
            }
            $result = $attendance->automaticRecord($employee->id, $data['input_method'], CarbonImmutable::now());
            $record = $result['record'];
            $data['event'] = $result['event'];
            DB::table('qr_uses')->insert(['qr_token_id' => $qr->id, 'employee_id' => $employee->id, 'event' => $data['event']]);
            Access::audit($r->user()->id, 'qr.scan', 'attendance:'.$record->id, null, ['device_id' => $device?->id, 'employee_id' => $employee->id, 'event' => $data['event'], 'input_method' => $data['input_method']]);

            return response()->json(['message' => 'Absen '.($data['event'] === 'in' ? 'masuk' : 'pulang').' berhasil dicatat.', 'employee_name' => $user->name, 'nik' => $employee->nik, 'event' => $data['event'], 'work_date' => $record->work_date, 'recorded_at' => $data['event'] === 'in' ? $record->clock_in : $record->clock_out]);
        });
    }

    public function clock(Request $r, Access $a, Attendance $attendance): RedirectResponse
    {
        // WFO can only be recorded by the authorized scanner or fingerprint device.
        $data = $r->validate(['event' => 'required|in:in,out', 'mode' => 'required|in:wfh', 'work_date' => 'nullable|date_format:Y-m-d']);
        $employee = $a->employee($r->user());
        abort_unless($employee !== null, 422, 'Akun belum terhubung dengan profil karyawan.');
        $attendance->record($employee->id, $data['event'], 'wfh', 'web', CarbonImmutable::now(), $data['work_date'] ?? null);

        return back()->with('success', 'Absensi WFH berhasil dicatat.');
    }

    public function requestChange(Request $r, Access $a): RedirectResponse
    {
        $data = $r->validate(['work_date' => 'required|date_format:Y-m-d', 'type' => 'required|in:wfh,correction,leave,sick,permission', 'reason' => 'required|string|max:3000', 'clock_in' => 'nullable|date_format:Y-m-d H:i:s', 'clock_out' => 'nullable|date_format:Y-m-d H:i:s|after:clock_in'], ['required' => 'Kolom :attribute wajib diisi.', 'date_format' => 'Format :attribute tidak valid. Gunakan pemilih tanggal dan waktu.', 'after' => 'Waktu keluar harus setelah waktu masuk.', 'in' => 'Jenis pengajuan tidak tersedia.'], ['work_date' => 'tanggal kerja', 'type' => 'jenis pengajuan', 'reason' => 'alasan', 'clock_in' => 'waktu masuk', 'clock_out' => 'waktu keluar']);
        $me = $a->employee($r->user());
        abort_unless($me !== null, 422, 'Profil karyawan belum ditetapkan.');
        abort_unless(DB::table('schedules')->where('employee_id', $me->id)->where('work_date', $data['work_date'])->exists(), 422, 'Jadwal belum tersedia.');
        if ($data['type'] === 'correction' && empty($data['clock_in'])) {
            throw ValidationException::withMessages(['clock_in' => 'Koreksi memerlukan waktu masuk.']);
        }
        if (DB::table('requests')->where('employee_id', $me->id)->where('work_date', $data['work_date'])->where('type', $data['type'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['type' => 'Pengajuan yang sama masih menunggu persetujuan.']);
        }
        $id = DB::table('requests')->insertGetId(['employee_id' => $me->id, 'work_date' => $data['work_date'], 'type' => $data['type'], 'reason' => $data['reason'], 'payload' => json_encode(['clock_in' => $data['clock_in'] ?? null, 'clock_out' => $data['clock_out'] ?? null]), 'created_at' => now(), 'updated_at' => now()]);
        Access::audit($r->user()->id, 'request.create', 'request:'.$id, null, $data);

        return back()->with('success', 'Pengajuan dikirim ke supervisor.');
    }

    public function decide(Request $r, int $id, Access $a, Attendance $attendance): RedirectResponse
    {
        $data = $r->validate(['status' => 'required|in:approved,rejected', 'decision_note' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $id, $a, $attendance, $data) {
            $request = DB::table('requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($request !== null, 404);
            $a->authorize($r->user(), 'attendance.approve', $request->employee_id);
            abort_if($a->employee($r->user())?->id === $request->employee_id && ! $r->user()->is_superadmin, 403, 'Tidak dapat menyetujui pengajuan sendiri.');
            abort_unless($request->status === 'pending', 422, 'Pengajuan sudah diputuskan.');
            DB::table('requests')->where('id', $id)->update([...$data, 'decided_by' => $r->user()->id, 'updated_at' => now()]);
            if ($data['status'] === 'approved' && $request->type !== 'wfh') {
                DB::table('employees')->where('id', $request->employee_id)->lockForUpdate()->first();
                $old = DB::table('attendances')->where('employee_id', $request->employee_id)->where('work_date', $request->work_date)->first();
                if (in_array($request->type, ['leave', 'sick', 'permission'])) {
                    abort_if($old !== null, 422, 'Absensi sudah ada; gunakan koreksi, bukan menimpa dengan izin.');
                    DB::table('attendances')->insert(['employee_id' => $request->employee_id, 'work_date' => $request->work_date, 'mode' => 'wfo', 'source' => 'approved_request', 'status' => $request->type, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $payload = json_decode($request->payload, true);
                    DB::table('attendances')->where('employee_id', $request->employee_id)->where('work_date', $request->work_date)->delete();
                    $mode = $old->mode ?? 'wfo';
                    $attendance->record($request->employee_id, 'in', $mode, 'correction', CarbonImmutable::parse($payload['clock_in']), $request->work_date);
                    if (! empty($payload['clock_out'])) {
                        $attendance->record($request->employee_id, 'out', $mode, 'correction', CarbonImmutable::parse($payload['clock_out']), $request->work_date);
                    }
                }
                Access::audit($r->user()->id, 'attendance.correct', 'employee:'.$request->employee_id.':'.$request->work_date, $old, DB::table('attendances')->where('employee_id', $request->employee_id)->where('work_date', $request->work_date)->first(), $request->reason);
            }
            Access::audit($r->user()->id, 'request.decide', 'request:'.$id, $request, $data, $data['decision_note']);
        });

        return back()->with('success', 'Keputusan pengajuan disimpan.');
    }

    public function import(Request $r, Access $a, Attendance $attendance): RedirectResponse
    {
        $a->authorize($r->user(), 'attendance.manage');
        $r->validate(['file' => 'required|file|mimes:xlsx|max:5120']);
        $reader = new Reader;
        $reader->open($r->file('file')->getRealPath());
        $rows = [];
        $identity = 'nik';
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_scalar($v) || $v === null ? trim((string) $v) : ''), $row->toArray());
                    if ($index === 1) {
                        if (! in_array($values, [['email', 'work_date', 'clock_in', 'clock_out', 'mode'], ['nik', 'work_date', 'clock_in', 'clock_out', 'mode']], true)) {
                            throw ValidationException::withMessages(['file' => 'Header wajib: email, work_date, clock_in, clock_out, mode (5 kolom). Template NIK lama tetap diterima. Gunakan sel teks.']);
                        }

                        $identity = $values[0];

                        continue;
                    }
                    if (implode('', $values) === '') {
                        continue;
                    }
                    if (count($values) !== 5 || count($rows) >= 2000) {
                        throw ValidationException::withMessages(['file' => 'Maksimal 2000 baris, masing-masing 5 kolom.']);
                    }
                    $d = array_combine([$identity, 'work_date', 'clock_in', 'clock_out', 'mode'], $values);
                    validator($d, [$identity => $identity === 'email' ? ['required', 'email', 'regex:/^[^@\s]+@alfar\.id$/i'] : 'required|string', 'work_date' => 'required|date_format:Y-m-d', 'clock_in' => 'required|date_format:Y-m-d H:i:s', 'clock_out' => 'nullable|date_format:Y-m-d H:i:s|after:clock_in', 'mode' => 'required|in:wfo,wfh'])->validate();
                    $rows[] = $d;
                }
                break;
            }
        } finally {
            $reader->close();
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Tidak ada data untuk diimpor.']);
        }
        DB::transaction(function () use ($rows, $a, $r, $attendance) {
            foreach ($rows as $i => $d) {
                $e = isset($d['email']) ? DB::table('employees')->where('user_id', DB::table('users')->where('email', strtolower(trim($d['email'])))->value('id'))->first() : DB::table('employees')->where('nik', $d['nik'])->first();
                if (! $e) {
                    throw ValidationException::withMessages(['file' => 'Baris '.($i + 2).(isset($d['email']) ? ': Email karyawan tidak ditemukan.' : ': NIK tidak ditemukan.')]);
                }
                $a->authorize($r->user(), 'attendance.manage', $e->id);
                try {
                    $attendance->record($e->id, 'in', $d['mode'], 'excel', CarbonImmutable::parse($d['clock_in']), $d['work_date']);
                    if ($d['clock_out']) {
                        $attendance->record($e->id, 'out', $d['mode'], 'excel', CarbonImmutable::parse($d['clock_out']), $d['work_date']);
                    }
                } catch (ValidationException $ex) {
                    throw ValidationException::withMessages(['file' => 'Baris '.($i + 2).': '.implode(' ', $ex->validator->errors()->all())]);
                }
            }
            Access::audit($r->user()->id, 'attendance.import', 'xlsx', null, ['rows' => count($rows)]);
        });

        return back()->with('success', count($rows).' baris diimpor. Duplikasi tidak ditimpa.');
    }

    // Vendor-neutral authenticated ingestion. A vendor adapter will translate the hardware protocol.
    public function fingerprint(Request $r, Attendance $attendance): JsonResponse
    {
        $token = $r->bearerToken();
        abort_unless(is_string($token) && $token !== '', 401);
        $device = DB::table('devices')->where('token_hash', hash('sha256', $token))->where('active', true)->where('type', 'fingerprint')->first();
        abort_unless($device !== null, 401);
        $data = $r->validate(['external_event_id' => 'required|string|max:150', 'external_id' => 'required|string|max:100', 'event' => 'nullable|in:in,out', 'occurred_at' => 'required|date_format:Y-m-d H:i:s', 'work_date' => 'nullable|date_format:Y-m-d']);
        abort_if(CarbonImmutable::parse($data['occurred_at'])->gt(now()->addMinutes(2)), 422, 'Waktu perangkat berada di masa depan.');

        return DB::transaction(function () use ($device, $data, $attendance) {
            DB::table('devices')->where('id', $device->id)->lockForUpdate()->first();
            if (DB::table('device_events')->where('device_id', $device->id)->where('external_event_id', $data['external_event_id'])->exists()) {
                return response()->json(['status' => 'duplicate']);
            }
            $mapping = DB::table('device_employees')->where('device_id', $device->id)->where('external_id', $data['external_id'])->first();
            abort_unless($mapping !== null, 422, 'Identitas fingerprint belum dipetakan.');
            $record = $attendance->automaticRecord($mapping->employee_id, 'fingerprint', CarbonImmutable::parse($data['occurred_at']))['record'];
            DB::table('device_events')->insert(['device_id' => $device->id, 'external_event_id' => $data['external_event_id'], 'occurred_at' => $data['occurred_at'], 'created_at' => now(), 'updated_at' => now()]);
            Access::audit(null, 'fingerprint.receive', 'device:'.$device->id, null, ['event_id' => $data['external_event_id'], 'attendance_id' => $record->id]);

            return response()->json(['status' => 'recorded', 'attendance_id' => $record->id]);
        });
    }
}
