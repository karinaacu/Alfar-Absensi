<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActionCenterController extends Controller
{
    public function index(Request $r, Access $a): JsonResponse
    {
        $user = $r->user();
        abort_unless(collect(['attendance.read', 'attendance.approve', 'kpi.validate', 'kpi.manage', 'reviews.publish'])->contains(fn ($p) => $a->allows($user, $p)), 403);
        $data = $r->validate(['view' => 'sometimes|in:attention,approvals', 'kind' => 'nullable|in:all,missing_in,missing_out,request,kpi_missing,kpi_pending,kpi_rejected', 'search' => 'nullable|string|max:150', 'period' => 'nullable|integer|exists:periods,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1']);
        $from = $data['from'] ?? now()->subDays(29)->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        abort_if($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 61, 422, 'Rentang kehadiran maksimal 62 hari dan tanggal akhir tidak boleh sebelum awal.');
        $approvalView = ($data['view'] ?? 'attention') === 'approvals';
        $me = $a->employee($user);
        $items = collect();
        $names = DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->whereNull('employees.deleted_at')->whereNull('users.deleted_at')->where('users.active', true)->select('employees.id', 'users.name')->pluck('name', 'id');
        if (! $approvalView) {
            $ids = $a->ids($user, 'attendance.read');
            $rows = DB::table('schedules')->leftJoin('attendances', fn ($q) => $q->on('attendances.employee_id', '=', 'schedules.employee_id')->on('attendances.work_date', '=', 'schedules.work_date'))->whereIn('schedules.employee_id', $ids)->whereBetween('schedules.work_date', [$from, $to])->where('schedules.scheduled_in', '<=', now())->where(fn ($q) => $q->whereNull('attendances.id')->orWhere('attendances.status', 'present'))->select('schedules.*', 'attendances.clock_in', 'attendances.clock_out')->get();
            foreach ($rows as $row) {
                if (! isset($names[$row->employee_id])) {
                    continue;
                }
                $kind = null;
                if (! $row->clock_in && CarbonImmutable::parse($row->scheduled_in)->addMinutes($row->tolerance_minutes)->lt(now())) {
                    $kind = 'missing_in';
                } elseif ($row->clock_in && ! $row->clock_out && CarbonImmutable::parse($row->scheduled_out)->lt(now())) {
                    $kind = 'missing_out';
                }
                if ($kind) {
                    $items->push(['id' => 'schedule-'.$row->id, 'entity_id' => $row->id, 'kind' => $kind, 'name' => $names[$row->employee_id], 'employee_id' => $row->employee_id, 'date' => $row->work_date, 'title' => $kind === 'missing_in' ? 'Belum ada absen masuk' : 'Belum ada absen pulang', 'detail' => 'Jadwal '.substr($row->scheduled_in, 11, 5).'–'.substr($row->scheduled_out, 11, 5).'. Periksa atau arahkan karyawan mengajukan koreksi.', 'can_decide' => false, 'destination' => 'attendance']);
                }
            }
        }
        $approvalIds = $a->ids($user, 'attendance.approve');
        $requests = DB::table('requests')->whereIn('employee_id', $approvalIds)->where('status', 'pending')->orderBy('created_at')->get();
        $types = ['wfh' => 'WFH', 'correction' => 'Koreksi absensi', 'leave' => 'Cuti', 'sick' => 'Sakit', 'permission' => 'Izin'];
        foreach ($requests as $row) {
            if (! isset($names[$row->employee_id]) || (! $user->is_superadmin && $row->employee_id === $me?->id)) {
                continue;
            }
            $payload = json_decode($row->payload ?? '{}', true);
            $items->push(['id' => 'request-'.$row->id, 'entity_id' => $row->id, 'kind' => 'request', 'name' => $names[$row->employee_id], 'employee_id' => $row->employee_id, 'date' => $row->work_date, 'title' => ($types[$row->type] ?? 'Pengajuan').' menunggu persetujuan', 'detail' => $row->reason, 'clock_in' => $payload['clock_in'] ?? null, 'clock_out' => $payload['clock_out'] ?? null, 'can_decide' => true, 'destination' => 'requests']);
        }
        $periodId = $data['period'] ?? DB::table('periods')->whereIn('status', ['active', 'review'])->orderByDesc('starts_on')->value('id');
        $validationIds = $a->ids($user, 'kpi.validate');
        $publisherIds = $a->ids($user, 'reviews.publish');
        $manageKpi = $a->allows($user, 'kpi.manage');
        $validationDepartments = [];
        $publisherDepartments = [];
        foreach (DB::table('departments')->pluck('id') as $department) {
            if ($a->team($user, 'kpi.validate', $department)) {
                $validationDepartments[] = $department;
            }
            if ($a->team($user, 'reviews.publish', $department)) {
                $publisherDepartments[] = $department;
            }
        }
        if ($periodId) {
            $period = DB::table('periods')->where('id', $periodId)->first();
            if (in_array($period->status, ['active', 'review'])) {
                $targets = DB::table('targets')->leftJoin('submissions', 'submissions.target_id', '=', 'targets.id')->where('targets.period_id', $periodId)->select('targets.*', 'submissions.id as submission_id', 'submissions.status as submission_status', 'submissions.submitted_by', 'submissions.actual', 'submissions.note')->get();
                $published = DB::table('reviews')->where('period_id', $periodId)->where('status', 'published')->pluck('employee_id')->all();
                foreach ($targets as $row) {
                    $canValidate = $row->employee_id ? in_array($row->employee_id, $validationIds) : in_array($row->department_id, $validationDepartments);
                    $canRead = $canValidate || $manageKpi || ($row->employee_id ? in_array($row->employee_id, $publisherIds) : in_array($row->department_id, $publisherDepartments));
                    if (! $canRead || ($row->employee_id && ! isset($names[$row->employee_id])) || ($row->employee_id ? in_array($row->employee_id, $published) : count($published) > 0)) {
                        continue;
                    }
                    $canDecide = $canValidate && ($user->is_superadmin || $row->submitted_by !== $user->id);
                    $kind = ! $row->submission_id ? 'kpi_missing' : ($row->submission_status === 'pending' ? 'kpi_pending' : ($row->submission_status === 'rejected' ? 'kpi_rejected' : null));
                    if (! $kind || ($approvalView && ($kind !== 'kpi_pending' || ! $canDecide))) {
                        continue;
                    }
                    $definition = json_decode($row->definition, true);
                    $name = $row->employee_id ? $names[$row->employee_id] : 'Tim '.DB::table('departments')->where('id', $row->department_id)->value('name');
                    $items->push(['id' => 'target-'.$row->id, 'entity_id' => $row->id, 'kind' => $kind, 'name' => $name, 'employee_id' => $row->employee_id, 'date' => $period->ends_on, 'title' => $definition['name'].' · '.match ($kind) {
                        'kpi_missing' => 'Belum ada realisasi','kpi_pending' => 'Menunggu validasi',default => 'Perlu perbaikan'
                    }, 'detail' => $row->note ?? 'Minta penanggung jawab melengkapi realisasi dan bukti.', 'target' => $row->target, 'actual' => $row->actual, 'unit' => $definition['unit'], 'evidence_id' => $canValidate ? $row->submission_id : null, 'can_decide' => $kind === 'kpi_pending' && $canDecide, 'destination' => $canValidate || $manageKpi ? 'kpi' : 'reviews']);
                }
            }
        }
        $search = mb_strtolower($data['search'] ?? '');
        if ($search !== '') {
            $items = $items->filter(fn ($row) => str_contains(mb_strtolower($row['name'].' '.$row['title'].' '.$row['detail']), $search));
        }
        $counts = $items->countBy('kind');
        if (($data['kind'] ?? 'all') !== 'all') {
            $items = $items->where('kind', $data['kind']);
        }
        $items = $items->sortBy('date')->values();
        $total = $items->count();
        $page = (int) ($data['page'] ?? 1);

        return response()->json(['data' => $items->slice(($page - 1) * 25, 25)->values(), 'counts' => $counts, 'total' => $total, 'page' => $page, 'last_page' => max(1, (int) ceil($total / 25)), 'from' => $from, 'to' => $to, 'period_id' => $periodId, 'checked_at' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }
}
