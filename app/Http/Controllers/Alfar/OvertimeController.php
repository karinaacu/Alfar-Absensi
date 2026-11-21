<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OvertimeController extends Controller
{
    public function index(Request $r, Access $a): JsonResponse
    {
        $me = $a->employee($r->user());
        $ids = $a->ids($r->user(), 'overtime.approve');
        if ($me) {
            $ids[] = $me->id;
        }
        $rows = DB::table('overtime_requests')->join('employees', 'employees.id', '=', 'overtime_requests.employee_id')->join('users', 'users.id', '=', 'employees.user_id')->whereIn('employee_id', $ids)->select('overtime_requests.*', 'users.name')->orderByDesc('overtime_requests.id')->paginate(30);
        $rows->through(fn ($e) => [...(array) $e, 'can_decide' => $e->status === 'pending' && $a->allows($r->user(), 'overtime.approve', $e->employee_id) && ($r->user()->is_superadmin || $e->employee_id !== $me?->id)]);

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }

    public function store(Request $r, Access $a): RedirectResponse
    {
        $me = $a->employee($r->user());
        abort_unless($me !== null, 422, 'Profil karyawan belum terhubung.');
        $data = $r->validate(['work_date' => 'required|date_format:Y-m-d', 'starts_at' => 'required|date_format:Y-m-d H:i:s', 'ends_at' => 'required|date_format:Y-m-d H:i:s|after:starts_at', 'break_minutes' => 'required|integer|min:0|max:1440', 'reason' => 'required|string|max:2000']);
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = CarbonImmutable::parse($data['ends_at']);
        $minutes = (int) floor($start->diffInSeconds($end) / 60) - $data['break_minutes'];
        abort_if($minutes <= 0 || $start->diffInHours($end) > 24 || $start->toDateString() < $data['work_date'] || $start->toDateString() > CarbonImmutable::parse($data['work_date'])->addDay()->toDateString(), 422, 'Periksa jam lembur dan istirahat. Durasi harus positif dan maksimal 24 jam.');
        DB::transaction(function () use ($r, $me, $data, $minutes) {
            DB::table('employees')->where('id', $me->id)->lockForUpdate()->first();
            $schedule = DB::table('schedules')->where('employee_id', $me->id)->where('work_date', $data['work_date'])->first();
            abort_if($schedule !== null && $data['starts_at'] < $schedule->scheduled_out && $data['ends_at'] > $schedule->scheduled_in, 422, 'Jam lembur harus di luar jam kerja terjadwal. Pisahkan lembur sebelum dan sesudah shift.');
            abort_if(DB::table('overtime_requests')->where('employee_id', $me->id)->whereIn('status', ['pending', 'approved'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists(), 422, 'Jam lembur bertumpuk dengan pengajuan sebelumnya.');
            $id = DB::table('overtime_requests')->insertGetId([...$data, 'employee_id' => $me->id, 'minutes' => $minutes, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($r->user()->id, 'overtime.create', 'overtime:'.$id, null, ['employee_id' => $me->id, 'work_date' => $data['work_date'], 'minutes' => $minutes]);
        });

        return back()->with('success', 'Lembur diajukan. Pembayaran dihitung setelah disetujui.');
    }

    public function decide(Request $r, int $id, Access $a): RedirectResponse
    {
        $data = $r->validate(['status' => 'required|in:approved,rejected', 'decision_note' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $id, $a, $data) {
            $row = DB::table('overtime_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $a->authorize($r->user(), 'overtime.approve', $row->employee_id);
            abort_if(! $r->user()->is_superadmin && $a->employee($r->user())?->id === $row->employee_id, 403, 'Lembur sendiri harus ditinjau atasan.');
            DB::table('employees')->where('id', $row->employee_id)->lockForUpdate()->first();
            abort_unless($row->status === 'pending', 422, 'Lembur sudah ditinjau.');
            abort_if($data['status'] === 'approved' && CarbonImmutable::parse($row->ends_at)->gt(now()), 422, 'Lembur hanya dapat disetujui setelah selesai.');
            DB::table('overtime_requests')->where('id', $id)->update([...$data, 'decided_by' => $r->user()->id, 'updated_at' => now()]);
            Access::audit($r->user()->id, 'overtime.decide', 'overtime:'.$id, null, ['employee_id' => $row->employee_id, 'status' => $data['status'], 'decision_note' => $data['decision_note']]);
        });

        return back()->with('success', 'Keputusan lembur disimpan.');
    }
}
