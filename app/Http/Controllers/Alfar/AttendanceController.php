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
        $a->authorize($r->user(), 'qr.display');
        $token = Str::random(64);
        $expires = now()->addSeconds(15);
        DB::table('qr_tokens')->insert(['token_hash' => hash('sha256', $token), 'issued_by' => $r->user()->id, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['token' => $token, 'expires_at' => $expires->toIso8601String(), 'url' => url('/dashboard?scan='.$token)])->header('Cache-Control', 'no-store');
    }

    public function clock(Request $r, Access $a, Attendance $attendance): RedirectResponse
    {
        $data = $r->validate(['event' => 'required|in:in,out', 'mode' => 'required|in:wfo,wfh', 'token' => 'nullable|string|size:64', 'work_date' => 'nullable|date_format:Y-m-d']);
        $employee = $a->employee($r->user());
        abort_unless($employee !== null, 422, 'Akun belum terhubung dengan profil karyawan.');
        DB::transaction(function () use ($data, $employee, $attendance) {
            // Serialize all punches per employee, including two different QR tokens.
            DB::table('employees')->where('id', $employee->id)->lockForUpdate()->first();
            if ($data['mode'] === 'wfo') {
                $qr = DB::table('qr_tokens')->where('token_hash', hash('sha256', $data['token'] ?? ''))->lockForUpdate()->first();
                if (! $qr || CarbonImmutable::parse($qr->expires_at)->lte(now())) {
                    throw ValidationException::withMessages(['token' => 'QR tidak valid atau kedaluwarsa. Pindai QR terbaru.']);
                }
                if (DB::table('qr_uses')->where('qr_token_id', $qr->id)->where('employee_id', $employee->id)->exists()) {
                    throw ValidationException::withMessages(['token' => 'QR ini sudah digunakan oleh akun Anda.']);
                }
                DB::table('qr_uses')->insert(['qr_token_id' => $qr->id, 'employee_id' => $employee->id, 'event' => $data['event']]);
            }
            $attendance->record($employee->id, $data['event'], $data['mode'], $data['mode'] === 'wfo' ? 'qr' : 'web', CarbonImmutable::now(), $data['work_date'] ?? null);
        });

        return back()->with('success', 'Absen '.($data['event'] === 'in' ? 'masuk' : 'keluar').' berhasil dicatat.');
    }

    public function requestChange(Request $r, Access $a): RedirectResponse
    {
        $data = $r->validate(['work_date' => 'required|date_format:Y-m-d', 'type' => 'required|in:wfh,correction,leave,sick,permission', 'reason' => 'required|string|max:3000', 'clock_in' => 'nullable|date_format:Y-m-d H:i:s', 'clock_out' => 'nullable|date_format:Y-m-d H:i:s|after:clock_in']);
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
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_scalar($v) || $v === null ? trim((string) $v) : ''), $row->toArray());
                    if ($index === 1) {
                        if ($values !== ['nik', 'work_date', 'clock_in', 'clock_out', 'mode']) {
                            throw ValidationException::withMessages(['file' => 'Header wajib: nik, work_date, clock_in, clock_out, mode (5 kolom). Gunakan sel teks.']);
                        }

                        continue;
                    }
                    if (implode('', $values) === '') {
                        continue;
                    }
                    if (count($values) !== 5 || count($rows) >= 2000) {
                        throw ValidationException::withMessages(['file' => 'Maksimal 2000 baris, masing-masing 5 kolom.']);
                    }
                    $d = array_combine(['nik', 'work_date', 'clock_in', 'clock_out', 'mode'], $values);
                    validator($d, ['nik' => 'required|string', 'work_date' => 'required|date_format:Y-m-d', 'clock_in' => 'required|date_format:Y-m-d H:i:s', 'clock_out' => 'nullable|date_format:Y-m-d H:i:s|after:clock_in', 'mode' => 'required|in:wfo,wfh'])->validate();
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
                $e = DB::table('employees')->where('nik', $d['nik'])->first();
                if (! $e) {
                    throw ValidationException::withMessages(['file' => 'Baris '.($i + 2).': NIK tidak ditemukan.']);
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
        $device = DB::table('devices')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        abort_unless($device !== null, 401);
        $data = $r->validate(['external_event_id' => 'required|string|max:150', 'external_id' => 'required|string|max:100', 'event' => 'required|in:in,out', 'occurred_at' => 'required|date_format:Y-m-d H:i:s', 'work_date' => 'required|date_format:Y-m-d']);
        abort_if(CarbonImmutable::parse($data['occurred_at'])->gt(now()->addMinutes(2)), 422, 'Waktu perangkat berada di masa depan.');

        return DB::transaction(function () use ($device, $data, $attendance) {
            DB::table('devices')->where('id', $device->id)->lockForUpdate()->first();
            if (DB::table('device_events')->where('device_id', $device->id)->where('external_event_id', $data['external_event_id'])->exists()) {
                return response()->json(['status' => 'duplicate']);
            }
            $mapping = DB::table('device_employees')->where('device_id', $device->id)->where('external_id', $data['external_id'])->first();
            abort_unless($mapping !== null, 422, 'Identitas fingerprint belum dipetakan.');
            $record = $attendance->record($mapping->employee_id, $data['event'], 'wfo', 'fingerprint', CarbonImmutable::parse($data['occurred_at']), $data['work_date']);
            DB::table('device_events')->insert(['device_id' => $device->id, 'external_event_id' => $data['external_event_id'], 'occurred_at' => $data['occurred_at'], 'created_at' => now(), 'updated_at' => now()]);
            Access::audit(null, 'fingerprint.receive', 'device:'.$device->id, null, ['event_id' => $data['external_event_id'], 'attendance_id' => $record->id]);

            return response()->json(['status' => 'recorded', 'attendance_id' => $record->id]);
        });
    }
}
