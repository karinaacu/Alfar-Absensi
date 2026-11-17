<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use App\Services\Performance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkController extends Controller
{
    public function index(Request $r, Access $a, Performance $performance): Response
    {
        $user = $r->user();
        $me = $a->employee($user);
        $permissions = collect(Access::PERMISSIONS)->filter(fn ($p) => $a->allows($user, $p))->values()->all();
        $reportIds = $a->ids($user, 'reports.read');
        $attendanceIds = $a->ids($user, 'attendance.read');
        // An employee can always see their own input workspace; published reports require reports.read.
        $workspaceIds = array_unique([...$reportIds, ...$attendanceIds, ...$a->ids($user, 'kpi.submit'), ...$a->ids($user, 'kpi.validate'), ...$a->ids($user, 'reviews.publish'), ...$a->ids($user, 'attendance.approve'), ...($me ? [$me->id] : [])]);
        $admin = $a->allows($user, 'organization.manage') || $a->allows($user, 'accounts.manage');
        $employees = DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->join('positions', 'positions.id', '=', 'employees.position_id')->join('departments', 'departments.id', '=', 'positions.department_id')->select('employees.*', 'users.name', 'users.active', 'positions.name as position', 'positions.department_id', 'departments.name as department')->when(! $admin, fn ($q) => $q->whereIn('employees.id', $workspaceIds))->get();
        $periods = DB::table('periods')->orderByDesc('starts_on')->get();
        $periodId = (int) ($r->query('period') ?: $periods->first()?->id);
        $period = DB::table('periods')->where('id', (int) ($periodId))->first();
        $reports = [];
        if ($period) {
            foreach ($employees as $e) {
                if (! in_array($e->id, $workspaceIds)) {
                    continue;
                }
                $review = DB::table('reviews')->where('employee_id', $e->id)->where('period_id', $periodId)->first();
                $canReview = $a->allows($user, 'reviews.publish', $e->id) || $a->allows($user, 'kpi.validate', $e->id);
                $own = $me?->id === $e->id;
                if (! $canReview && ! $own && ! in_array($e->id, $reportIds)) {
                    continue;
                }
                $live = $performance->report($e->id, $periodId);
                // Employees and report readers see the immutable published version; evaluators see live inputs.
                if (! $canReview && $review?->status === 'published') {
                    $data = json_decode($review->snapshot, true);
                } else {
                    $data = $live;
                }
                if (! $canReview && ! $own && $review?->status !== 'published') {
                    continue;
                }
                if (! $canReview && $review?->status !== 'published') {
                    $review = null;
                }
                $previous = DB::table('reviews')->where('employee_id', $e->id)->where('status', 'published')->where('period_id', '!=', $periodId)->where('published_at', '<', $review->published_at ?? now())->orderByDesc('published_at')->first();
                $reports[] = ['employee' => $e, 'data' => $data, 'review' => $review, 'previous_score' => $previous ? json_decode($previous->snapshot, true)['score'] : null, 'can_review' => $canReview];
            }
        }
        $targets = DB::table('targets')->when($period, fn ($q) => $q->where('period_id', $periodId))->get()->filter(function ($t) use ($user, $a) {
            if ($t->employee_id) {
                return $a->allows($user, 'kpi.submit', $t->employee_id) || $a->allows($user, 'kpi.validate', $t->employee_id) || $a->allows($user, 'kpi.manage');
            }

            return $a->team($user, 'kpi.submit', $t->department_id) || $a->team($user, 'kpi.validate', $t->department_id) || $a->allows($user, 'kpi.manage');
        })->map(fn ($t) => [...(array) $t, 'definition' => json_decode($t->definition, true), 'submission' => DB::table('submissions')->where('target_id', $t->id)->first()])->values();
        $readIds = array_unique([...$attendanceIds, ...($me ? [$me->id] : [])]);
        $approvalIds = $a->ids($user, 'attendance.approve');
        $master = $a->allows($user, 'organization.manage') || $a->allows($user, 'kpi.manage');

        return Inertia::render('alfar/Workspace', [
            'permissions' => $permissions, 'me' => $me, 'employees' => $employees, 'periods' => $periods, 'periodId' => $periodId, 'reports' => $reports, 'targets' => $targets,
            'departments' => $master ? DB::table('departments')->get() : [], 'positions' => $master ? DB::table('positions')->get() : [],
            'shifts' => ($master || $a->allows($user, 'reports.read')) ? DB::table('shifts')->get() : [], 'metrics' => $a->allows($user, 'kpi.manage') ? DB::table('metrics')->get() : [],
            'accounts' => $a->allows($user, 'accounts.manage') || $a->allows($user, 'roles.manage') || $a->allows($user, 'organization.manage') ? DB::table('users')->select('id', 'name', 'email', 'active')->get() : [],
            'roles' => $a->allows($user, 'roles.manage') ? DB::table('roles')->get()->map(fn ($x) => [...(array) $x, 'grants' => DB::table('role_grants')->where('role_id', $x->id)->get()]) : [],
            'roleAssignments' => $a->allows($user, 'roles.manage') ? DB::table('role_user')->get() : [],
            'permissionCatalog' => Access::PERMISSIONS, 'scopeCatalog' => Access::SCOPES,
            'attendances' => DB::table('attendances')->whereIn('employee_id', $readIds)->when($period, fn ($q) => $q->whereBetween('work_date', [$period->starts_on, $period->ends_on]))->orderByDesc('work_date')->limit(500)->get(),
            'schedules' => DB::table('schedules')->when(! $admin, fn ($q) => $q->whereIn('employee_id', $readIds))->when($period, fn ($q) => $q->whereBetween('work_date', [$period->starts_on, $period->ends_on]))->orderByDesc('work_date')->limit(500)->get(),
            'requests' => DB::table('requests')->whereIn('employee_id', array_unique([...$approvalIds, ...($me ? [$me->id] : [])]))->orderByDesc('id')->limit(200)->get(),
            'devices' => ($a->allows($user, 'devices.manage') || $a->allows($user, 'attendance.scan')) ? DB::table('devices')->select('id', 'name', 'type', 'active')->when(! $a->allows($user, 'devices.manage'), fn ($q) => $q->where('active', true)->whereIn('type', ['scanner', 'phone_camera']))->get() : [],
            'deviceMappings' => $a->allows($user, 'devices.manage') ? DB::table('device_employees')->get() : [],
            'audits' => $user->is_superadmin ? DB::table('audit_logs')->latest('id')->limit(100)->get() : [],
            'placements' => $admin ? DB::table('placements')->latest('id')->limit(200)->get() : [],
        ]);
    }

    private function targetAccess(Request $r, Access $a, \stdClass $t, string $p): void
    {
        if ($t->employee_id) {
            $a->authorize($r->user(), $p, $t->employee_id);
        } else {
            abort_unless($a->team($r->user(), $p, $t->department_id), 403);
        }
    }

    public function submit(Request $r, int $id, Access $a): RedirectResponse
    {
        $data = $r->validate(['actual' => 'required|numeric|min:0|max:999999999999', 'note' => 'required|string|max:4000', 'evidence' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240']);
        $t = DB::table('targets')->where('id', (int) ($id))->first();
        abort_unless($t !== null, 404);
        $this->targetAccess($r, $a, $t, 'kpi.submit');
        $path = $r->file('evidence')->store('evidence', 'local');
        abort_unless(is_string($path), 500, 'Bukti tidak dapat disimpan.');
        try {
            DB::transaction(function () use ($r, $id, $t, $data, $path) {
                $p = DB::table('periods')->where('id', $t->period_id)->lockForUpdate()->first();
                if (! in_array($p->status, ['active', 'review'])) {
                    throw ValidationException::withMessages(['actual' => 'Periode tidak menerima realisasi.']);
                }
                if (DB::table('reviews')->where('period_id', $p->id)->where('status', 'published')->when($t->employee_id, fn ($q) => $q->where('employee_id', $t->employee_id))->exists()) {
                    throw ValidationException::withMessages(['actual' => 'Realisasi sudah masuk laporan terbit. Gunakan revisi review untuk perubahan catatan.']);
                }
                $before = DB::table('submissions')->where('target_id', $id)->first();
                if ($before?->status === 'validated') {
                    throw ValidationException::withMessages(['actual' => 'Realisasi tervalidasi dikunci. Supervisor harus mengembalikan terlebih dahulu.']);
                }
                DB::table('submissions')->updateOrInsert(['target_id' => $id], ['actual' => $data['actual'], 'note' => $data['note'], 'evidence_path' => $path, 'submitted_by' => $r->user()->id, 'validated_by' => null, 'status' => 'pending', 'created_at' => $before->created_at ?? now(), 'updated_at' => now()]);
                Access::audit($r->user()->id, 'kpi.submit', 'target:'.$id, $before, ['actual' => $data['actual'], 'note' => $data['note']]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Realisasi dikirim untuk validasi.');
    }

    public function validateSubmission(Request $r, int $id, Access $a): RedirectResponse
    {
        $data = $r->validate(['status' => 'required|in:validated,rejected', 'reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $id, $a, $data) {
            $t = DB::table('targets')->where('id', (int) ($id))->first();
            abort_unless($t !== null, 404);
            $this->targetAccess($r, $a, $t, 'kpi.validate');
            $p = DB::table('periods')->where('id', $t->period_id)->lockForUpdate()->first();
            abort_unless(in_array($p->status, ['active', 'review']), 422, 'Periode terkunci.');
            abort_if(DB::table('reviews')->where('period_id', $p->id)->where('status', 'published')->when($t->employee_id, fn ($q) => $q->where('employee_id', $t->employee_id))->exists(), 422, 'Laporan sudah terbit.');
            $s = DB::table('submissions')->where('target_id', $id)->first();
            abort_unless($s !== null, 404);
            abort_if($s->submitted_by === $r->user()->id && ! $r->user()->is_superadmin, 403, 'Realisasi sendiri tidak boleh divalidasi sendiri.');
            DB::table('submissions')->where('id', $s->id)->update(['status' => $data['status'], 'validated_by' => $r->user()->id, 'updated_at' => now()]);
            Access::audit($r->user()->id, 'kpi.validate', 'submission:'.$s->id, $s, $data, $data['reason']);
        });

        return back()->with('success', 'Validasi disimpan.');
    }

    public function correctSubmission(Request $r, int $id, Access $a): RedirectResponse
    {
        $data = $r->validate(['actual' => 'required|numeric|min:0|max:999999999999', 'note' => 'required|string|max:4000', 'reason' => 'required|string|max:2000', 'evidence' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240']);
        $t = DB::table('targets')->where('id', $id)->first();
        abort_unless($t !== null, 404);
        $this->targetAccess($r, $a, $t, 'kpi.validate');
        $this->targetAccess($r, $a, $t, 'reviews.publish');
        abort_if($t->employee_id === $a->employee($r->user())?->id && ! $r->user()->is_superadmin, 403, 'Koreksi realisasi sendiri harus dilakukan supervisor.');
        $path = $r->file('evidence')->store('evidence', 'local');
        abort_unless(is_string($path), 500, 'Bukti tidak dapat disimpan.');
        try {
            DB::transaction(function () use ($r, $id, $t, $data, $path) {
                $period = DB::table('periods')->where('id', $t->period_id)->lockForUpdate()->first();
                abort_unless(in_array($period->status, ['review', 'closed']), 422, 'Koreksi ini khusus tahap review atau periode ditutup.');
                $before = DB::table('submissions')->where('target_id', $id)->first();
                abort_unless($before !== null, 422, 'Belum ada realisasi yang dapat dikoreksi.');
                DB::table('submissions')->where('id', $before->id)->update(['actual' => $data['actual'], 'note' => $data['note'], 'evidence_path' => $path, 'status' => 'validated', 'validated_by' => $r->user()->id, 'updated_at' => now()]);
                Access::audit($r->user()->id, 'kpi.correct', 'target:'.$id, $before, DB::table('submissions')->where('id', $before->id)->first(), $data['reason']);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Koreksi disimpan. Terbitkan revisi review beralasan untuk memperbarui laporan; laporan terbit sebelumnya tetap utuh.');
    }

    public function review(Request $r, Access $a, Performance $performance): RedirectResponse
    {
        $data = $r->validate(['employee_id' => 'required|exists:employees,id', 'period_id' => 'required|exists:periods,id', 'analysis' => 'required|string|max:8000', 'follow_up' => 'required|string|max:4000', 'due_on' => 'required|date', 'publish' => 'required|boolean', 'reason' => 'nullable|string|max:2000']);
        $a->authorize($r->user(), 'reviews.publish', $data['employee_id']);
        abort_if($a->employee($r->user())?->id === (int) $data['employee_id'] && ! $r->user()->is_superadmin, 403, 'Review sendiri harus dilakukan supervisor.');
        DB::transaction(function () use ($r, $data, $performance) {
            $period = DB::table('periods')->where('id', $data['period_id'])->lockForUpdate()->first();
            abort_unless(in_array($period->status, ['review', 'closed']), 422, 'Periode belum masuk tahap review.');
            $before = DB::table('reviews')->where('employee_id', $data['employee_id'])->where('period_id', $data['period_id'])->first();
            if ($before?->status === 'published' && (empty($data['reason']) || ! $data['publish'])) {
                throw ValidationException::withMessages(['reason' => 'Revisi laporan terbit wajib alasan dan tetap diterbitkan.']);
            }
            abort_if($period->status === 'closed' && $before?->status !== 'published', 422, 'Periode ditutup.');
            $report = $performance->report($data['employee_id'], $data['period_id']);
            if ($data['publish'] && ! $report['complete']) {
                throw ValidationException::withMessages(['publish' => 'Belum Lengkap: validasi seluruh KPI individu dan pastikan bobot 100%.']);
            }
            foreach ($report['targets'] as $target) {
                if ($data['publish'] && ! $target['complete']) {
                    throw ValidationException::withMessages(['publish' => 'KPI tim yang terkait juga harus divalidasi.']);
                }
            }
            DB::table('reviews')->updateOrInsert(['employee_id' => $data['employee_id'], 'period_id' => $data['period_id']], ['analysis' => $data['analysis'], 'follow_up' => $data['follow_up'], 'due_on' => $data['due_on'], 'status' => $data['publish'] ? 'published' : 'draft', 'published_at' => $data['publish'] ? now() : null, 'snapshot' => $data['publish'] ? json_encode($report) : null, 'reviewer_id' => $r->user()->id, 'revision' => ($before->revision ?? 0) + 1, 'created_at' => $before->created_at ?? now(), 'updated_at' => now()]);
            Access::audit($r->user()->id, 'review.'.($data['publish'] ? 'publish' : 'draft'), 'employee:'.$data['employee_id'].':period:'.$data['period_id'], $before, DB::table('reviews')->where('employee_id', $data['employee_id'])->where('period_id', $data['period_id'])->first(), $data['reason'] ?? null);
        });

        return back()->with('success', 'Review disimpan.');
    }

    public function respond(Request $r, int $id, Access $a): RedirectResponse
    {
        $data = $r->validate(['response' => 'required|string|max:4000']);
        $review = DB::table('reviews')->where('id', (int) ($id))->first();
        abort_unless($review !== null, 404);
        abort_unless($review->employee_id === $a->employee($r->user())?->id && $review->status === 'published', 403);
        DB::table('reviews')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        Access::audit($r->user()->id, 'review.respond', 'review:'.$id, ['response' => $review->response], $data);

        return back()->with('success', 'Tanggapan disimpan.');
    }

    public function evidence(Request $r, int $id, Access $a): StreamedResponse
    {
        $s = DB::table('submissions')->where('id', (int) ($id))->first();
        abort_unless($s?->evidence_path, 404);
        $t = DB::table('targets')->where('id', (int) ($s->target_id))->first();
        $canInput = $t->employee_id
            ? ($a->allows($r->user(), 'kpi.validate', $t->employee_id) || $a->allows($r->user(), 'kpi.submit', $t->employee_id))
            : ($a->team($r->user(), 'kpi.validate', $t->department_id) || $a->team($r->user(), 'kpi.submit', $t->department_id));
        $publishedEvidence = false;
        if (! $canInput) {
            $reviews = DB::table('reviews')->where('period_id', $t->period_id)->where('status', 'published')->whereIn('employee_id', $a->ids($r->user(), 'reports.read'))->get();
            foreach ($reviews as $review) {
                foreach (json_decode($review->snapshot, true)['targets'] as $target) {
                    if ($target['id'] === $t->id && ($target['submission']['evidence_path'] ?? null) === $s->evidence_path) {
                        $publishedEvidence = true;
                    }
                }
            }
        }
        abort_unless($canInput || $publishedEvidence, 403);

        return Storage::disk('local')->download($s->evidence_path);
    }
}
