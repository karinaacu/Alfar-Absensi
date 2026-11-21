<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use App\Services\Payroll;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    private function manager(Request $r, Access $a): bool
    {
        return in_array('all', $a->grants($r->user(), 'payroll.manage')) || in_array('all', $a->grants($r->user(), 'payroll.publish'));
    }

    private function manage(Request $r, Access $a): void
    {
        abort_unless(in_array('all', $a->grants($r->user(), 'payroll.manage')), 403);
    }

    /** @return array<string,mixed> */
    private function componentRules(): array
    {
        return ['components' => 'present|array|max:30', 'components.*.name' => 'required|string|max:80', 'components.*.type' => 'required|in:earning,deduction', 'components.*.amount' => 'required|integer|min:0|max:1000000000000'];
    }

    /** @return array<string,mixed> */
    private function policyRules(): array
    {
        return ['safe_minutes' => 'nullable|integer|min:0|max:100000', 'warning_minutes' => 'nullable|integer|min:0|max:100000|gte:safe_minutes', 'free_late_minutes' => 'required|integer|min:0|max:100000', 'late_rate' => 'nullable|integer|min:0|max:1000000000', 'late_cap' => 'nullable|integer|min:0|max:1000000000000', 'overtime_rate' => 'nullable|integer|min:0|max:1000000000'];
    }

    public function index(Request $r, Access $a): JsonResponse
    {
        abort_unless($this->manager($r, $a), 403);
        $employees = DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->join('positions', 'positions.id', '=', 'employees.position_id')->leftJoin('departments', 'departments.id', '=', 'employees.department_id')->leftJoin('salary_profiles', 'salary_profiles.employee_id', '=', 'employees.id')->whereNull('employees.deleted_at')->whereNull('users.deleted_at')->where('users.active', true)->select('employees.id', 'users.name', 'positions.name as position', 'departments.name as department', 'salary_profiles.base_salary', 'salary_profiles.components')->orderBy('users.name')->get()->map(fn ($e) => [...(array) $e, 'components' => json_decode($e->components ?? '[]', true)]);

        return response()->json(['employees' => $employees, 'runs' => DB::table('payroll_runs')->orderByDesc('starts_on')->get()])->header('Cache-Control', 'no-store');
    }

    public function profile(Request $r, int $id, Access $a): RedirectResponse
    {
        $this->manage($r, $a);
        $data = $r->validate(['base_salary' => 'required|integer|min:0|max:1000000000000', ...$this->componentRules()]);
        DB::transaction(function () use ($r, $id, $data) {
            abort_unless(DB::table('employees')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first() !== null, 404);
            $before = DB::table('salary_profiles')->where('employee_id', $id)->first();
            DB::table('salary_profiles')->updateOrInsert(['employee_id' => $id], ['base_salary' => $data['base_salary'], 'components' => json_encode($data['components']), 'created_at' => $before->created_at ?? now(), 'updated_at' => now()]);
            Access::audit($r->user()->id, 'payroll.profile', 'employee:'.$id, $before, $data);
        });

        return back()->with('success', 'Komponen gaji disimpan. Draf yang sudah ada perlu dihitung ulang.');
    }

    public function store(Request $r, Access $a, Payroll $payroll): RedirectResponse
    {
        $this->manage($r, $a);
        $data = $r->validate(['name' => 'required|string|max:150', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'pays_on' => 'required|date_format:Y-m-d', 'employee_ids' => 'required|array|min:1|max:2000', 'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->whereNull('deleted_at')], ...$this->policyRules()]);
        abort_if(CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 365, 422, 'Periode maksimal 366 hari.');
        DB::transaction(function () use ($r, $data, $payroll) {
            $ids = $data['employee_ids'];
            sort($ids);
            $employees = DB::table('employees')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_if(DB::table('payroll_items')->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')->whereIn('employee_id', $ids)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists(), 422, 'Ada karyawan yang sudah masuk periode penggajian bertumpuk. Periksa periode sebelumnya.');
            $values = $data;
            unset($values['employee_ids']);
            $id = DB::table('payroll_runs')->insertGetId([...$values, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($employees as $e) {
                DB::table('payroll_items')->insert(['payroll_run_id' => $id, 'employee_id' => $e->id, 'adjustments' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            }
            $payroll->recalculate($this->draft($id));
            Access::audit($r->user()->id, 'payroll.create', 'payroll:'.$id, null, ['employees' => count($ids), 'period' => $data['name']]);
        });

        return back()->with('success', 'Draf penggajian dibuat. Periksa nominal dan aturan sebelum diterbitkan.');
    }

    private function draft(int $id): \stdClass
    {
        $run = DB::table('payroll_runs')->where('id', $id)->lockForUpdate()->first();
        abort_unless($run !== null, 404);
        abort_unless($run->status === 'draft', 422, 'Slip yang sudah terbit dikunci dan tidak dapat diubah.');

        return $run;
    }

    public function policy(Request $r, int $id, Access $a, Payroll $payroll): RedirectResponse
    {
        $this->manage($r, $a);
        $data = $r->validate($this->policyRules());
        DB::transaction(function () use ($r, $id, $data, $payroll) {
            $this->draft($id);
            DB::table('payroll_runs')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            $payroll->recalculate($this->draft($id));
            Access::audit($r->user()->id, 'payroll.policy', 'payroll:'.$id, null, ['policy_updated' => true]);
        });

        return back()->with('success', 'Aturan disimpan dan draf dihitung ulang.');
    }

    public function recalculate(Request $r, int $id, Access $a, Payroll $payroll): RedirectResponse
    {
        $this->manage($r, $a);
        DB::transaction(function () use ($r, $id, $payroll) {
            $run = $this->draft($id);
            $payroll->recalculate($run);
            Access::audit($r->user()->id, 'payroll.recalculate', 'payroll:'.$id, null, ['period' => $run->name]);
        });

        return back()->with('success', 'Gaji, keterlambatan, dan lembur pada draf dihitung ulang.');
    }

    public function adjust(Request $r, int $id, Access $a, Payroll $payroll): RedirectResponse
    {
        $this->manage($r, $a);
        $data = $r->validate([...$this->componentRules(), 'reason' => 'required|string|max:2000']);
        $item = DB::table('payroll_items')->where('id', $id)->first();
        abort_unless($item !== null, 404);
        DB::transaction(function () use ($r, $item, $data, $payroll) {
            $run = $this->draft($item->payroll_run_id);
            DB::table('payroll_items')->where('id', $item->id)->update(['adjustments' => json_encode($data['components']), 'updated_at' => now()]);
            $payroll->recalculate($run);
            Access::audit($r->user()->id, 'payroll.adjust', 'payroll-item:'.$item->id, ['components' => json_decode($item->adjustments, true)], ['components' => $data['components']], $data['reason']);
        });

        return back()->with('success', 'Penyesuaian draf disimpan.');
    }

    public function publish(Request $r, int $id, Access $a, Payroll $payroll): RedirectResponse
    {
        abort_unless(in_array('all', $a->grants($r->user(), 'payroll.publish')), 403);
        DB::transaction(function () use ($r, $id, $payroll) {
            $payroll->publish($this->draft($id), $r->user()->id);
        });

        return back()->with('success', 'Slip diterbitkan dan dapat dilihat masing-masing karyawan.');
    }

    public function run(Request $r, int $id, Access $a): JsonResponse
    {
        abort_unless($this->manager($r, $a), 403);
        $run = DB::table('payroll_runs')->where('id', $id)->first();
        abort_unless($run !== null, 404);

        return response()->json(['run' => $run, 'items' => DB::table('payroll_items')->where('payroll_run_id', $id)->orderBy('employee_id')->get()->map(fn ($e) => [...(array) $e, 'snapshot' => json_decode($e->snapshot ?? '{}', true), 'adjustments' => json_decode($e->adjustments, true)])])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $r, int $id, Access $a): RedirectResponse
    {
        $this->manage($r, $a);
        DB::transaction(function () use ($r, $id) {
            $run = $this->draft($id);
            DB::table('payroll_items')->where('payroll_run_id', $id)->delete();
            DB::table('payroll_runs')->where('id', $id)->delete();
            Access::audit($r->user()->id, 'payroll.delete', 'payroll:'.$id, null, ['period' => $run->name]);
        });

        return back()->with('success', 'Draf penggajian dihapus. Data gaji dan absensi tetap tersimpan.');
    }

    public function slips(Request $r, Access $a): JsonResponse
    {
        $ids = $a->ids($r->user(), 'payroll.read');
        if ($me = $a->employee($r->user())) {
            $ids[] = $me->id;
        }$items = DB::table('payroll_items')->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')->where('payroll_runs.status', 'published')->whereIn('employee_id', $ids)->select('payroll_items.id', 'payroll_items.snapshot', 'payroll_runs.published_at')->orderByDesc('pays_on')->get()->map(fn ($e) => [...(array) $e, 'snapshot' => json_decode($e->snapshot, true)]);

        return response()->json($items)->header('Cache-Control', 'no-store');
    }

    private function slip(Request $r, int $id, Access $a): \stdClass
    {
        $item = DB::table('payroll_items')->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')->where('payroll_items.id', $id)->select('payroll_items.*', 'payroll_runs.status', 'payroll_runs.published_at')->first();
        abort_unless($item !== null, 404);
        abort_unless($this->manager($r, $a) || ($item->status === 'published' && ($a->employee($r->user())?->id === $item->employee_id || $a->allows($r->user(), 'payroll.read', $item->employee_id))), 404);

        return $item;
    }

    public function lateness(Request $r, Access $a): JsonResponse
    {
        $data = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'mine' => 'nullable|boolean', 'search' => 'nullable|string|max:150']);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365, 422, 'Rentang maksimal 366 hari.');
        $me = $a->employee($r->user());
        $ids = $a->ids($r->user(), 'attendance.read');
        if ($me) {
            $ids[] = $me->id;
        }if ($r->boolean('mine')) {
            $ids = $me ? [$me->id] : [];
        }
        abort_unless($me !== null || $a->allows($r->user(), 'attendance.read'), 403);
        $employees = DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->whereIn('employees.id', $ids)->whereNull('employees.deleted_at')->select('employees.id', 'users.name')->get();
        $totals = DB::table('attendances')->whereIn('employee_id', $ids)->whereBetween('work_date', [$data['from'], $data['to']])->selectRaw('employee_id, SUM(late_minutes) as minutes')->groupBy('employee_id')->pluck('minutes', 'employee_id');
        $rows = $employees->filter(fn ($e) => empty($data['search']) || str_contains(mb_strtolower($e->name), mb_strtolower($data['search'])))->map(function ($e) use ($data, $totals) {
            $late = (int) ($totals[$e->id] ?? 0);
            $policy = DB::table('payroll_runs')->join('payroll_items', 'payroll_items.payroll_run_id', '=', 'payroll_runs.id')->where('employee_id', $e->id)->where('starts_on', '<=', $data['from'])->where('ends_on', '>=', $data['to'])->select('safe_minutes', 'warning_minutes')->first();

            return ['id' => $e->id, 'name' => $e->name, 'minutes' => $late, 'zone' => $policy && $policy->safe_minutes !== null && $policy->warning_minutes !== null ? ($late <= $policy->safe_minutes ? 'safe' : ($late <= $policy->warning_minutes ? 'warning' : 'red')) : 'unconfigured'];
        })->values();

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }

    public function print(Request $r, int $id, Access $a): Response
    {
        $item = $this->slip($r, $id, $a);
        abort_unless($item->status === 'published', 422, 'Cetak tersedia setelah slip diterbitkan.');

        return response()->view('alfar.payslip', ['slip' => json_decode($item->snapshot, true), 'publishedAt' => $item->published_at])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
