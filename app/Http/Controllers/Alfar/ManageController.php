<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ManageController extends Controller
{
    public function store(Request $request, string $entity, Access $access): RedirectResponse
    {
        $permission = match ($entity) {
            'departments','positions','employees','shifts','schedules' => 'organization.manage',
            'accounts' => 'accounts.manage', 'roles','role-users' => 'roles.manage',
            'periods','metrics','targets','period-status' => 'kpi.manage',
            'devices','device-mappings' => 'devices.manage', default => abort(404),
        };
        // Master-data and identity administration are intentionally global operations.
        abort_unless(in_array('all', $access->grants($request->user(), $permission), true), 403);

        if ($entity === 'accounts' && is_string($request->email)) {
            $request->merge(['email' => strtolower(trim($request->email))]);
        }

        return DB::transaction(function () use ($request, $entity) {
            $id = null;
            $before = null;
            if (in_array($entity, ['departments', 'positions', 'shifts', 'metrics', 'periods', 'targets', 'devices', 'accounts']) && $request->filled('id')) {
                $request->validate(['id' => ['required', 'integer', Rule::exists($entity === 'accounts' ? 'users' : $entity, 'id')]]);
                $id = (int) $request->id;
                $before = DB::table($entity === 'accounts' ? 'users' : $entity)->where('id', $id)->lockForUpdate()->first();
                if (in_array($entity, ['accounts', 'positions']) && $before->deleted_at !== null) {
                    abort(404);
                }
                if ($entity === 'periods' && $before->status !== 'planning') {
                    throw ValidationException::withMessages(['id' => 'Periode aktif tidak dapat diubah.']);
                }
                if ($entity === 'targets') {
                    $oldPeriod = DB::table('periods')->where('id', $before->period_id)->lockForUpdate()->first();
                    if ($oldPeriod->status !== 'planning') {
                        throw ValidationException::withMessages(['id' => 'Target periode aktif tidak dapat diubah.']);
                    }
                }
            }
            switch ($entity) {
                case 'departments':
                    $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('departments')->ignore($id)]]);
                    break;
                case 'positions':
                    $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('positions')->whereNull('deleted_at')->ignore($id)]], ['name.unique' => 'Nama jabatan sudah digunakan.']);
                    break;
                case 'shifts':
                    $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('shifts')->ignore($id)], 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i|different:starts_at', 'tolerance_minutes' => 'required|integer|min:0|max:180', 'checkin_early_minutes' => 'sometimes|integer|min:0|max:360', 'checkout_after_minutes' => 'sometimes|integer|min:1|max:1440', 'checkout_grace_minutes' => 'sometimes|integer|min:0|max:720']);
                    break;
                case 'accounts':
                    $data = $request->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', 'regex:/^[^@\s]+@alfar\.id$/i', Rule::unique('users')->whereNull('deleted_at')->ignore($id)], 'active' => 'sometimes|boolean', 'password' => [$id ? 'nullable' : 'required', 'confirmed', Password::min(12)->letters()->numbers()]]);
                    if (! $id) {
                        $archived = User::where('email', $data['email'])->whereNotNull('deleted_at')->first();
                        if ($archived) {
                            $id = $archived->id;
                        }
                    }
                    $user = $id ? User::findOrFail($id) : new User;
                    if ($user->is_superadmin && ($data['email'] !== $user->email || ! ($data['active'] ?? true))) {
                        throw ValidationException::withMessages(['email' => 'Akun bootstrap superadmin tidak boleh dinonaktifkan atau diganti email.']);
                    }
                    $safeBefore = $id ? ['name' => $user->name, 'email' => $user->email, 'active' => $user->active] : null;
                    $user->fill(['name' => $data['name'], 'email' => $data['email']]);
                    if (! empty($data['password'])) {
                        $user->forceFill(['password' => $data['password'], 'must_change_password' => true]);
                    }
                    $user->forceFill(['active' => $data['active'] ?? true, 'email_verified_at' => now(), 'deleted_at' => null])->save();
                    Access::audit($request->user()->id, 'accounts.save', 'user:'.$user->id, $safeBefore, ['name' => $user->name, 'email' => $user->email, 'active' => $user->active]);

                    return back()->with('success', 'Akun disimpan. Password sementara wajib diganti saat login.');
                case 'employees':
                    $data = $request->validate(['id' => 'nullable|integer|exists:employees,id', 'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')], 'department_id' => 'required|exists:departments,id', 'position_id' => 'required|exists:positions,id', 'supervisor_id' => ['nullable', Rule::exists('employees', 'id')->whereNull('deleted_at')], 'allow_wfh' => 'required|boolean', 'allow_wfo' => 'required|boolean', 'starts_on' => 'required|date_format:Y-m-d']);
                    $id = isset($data['id']) ? (int) $data['id'] : null;
                    unset($data['id']);
                    if (! $id) {
                        $archived = DB::table('employees')->where('user_id', $data['user_id'])->whereNotNull('deleted_at')->lockForUpdate()->first();
                        if ($archived) {
                            $id = $archived->id;
                        }
                    }
                    validator($data, ['user_id' => Rule::unique('employees')->ignore($id)])->validate();
                    $before = $id ? DB::table('employees')->where('id', $id)->lockForUpdate()->first() : null;
                    if (DB::table('positions')->where('id', $data['position_id'])->whereNotNull('deleted_at')->exists() && (! $before || $before->position_id != $data['position_id'])) {
                        throw ValidationException::withMessages(['position_id' => 'Pilih jabatan aktif.']);
                    }
                    $start = $data['starts_on'];
                    unset($data['starts_on']);
                    if ($before && $before->user_id != $data['user_id']) {
                        throw ValidationException::withMessages(['user_id' => 'Akun karyawan tidak dapat diganti.']);
                    }
                    $seen = $id ? [$id] : [];
                    $next = $data['supervisor_id'] ?? null;
                    while ($next) {
                        if (in_array($next, $seen)) {
                            throw ValidationException::withMessages(['supervisor_id' => 'Relasi atasan membentuk siklus.']);
                        }$seen[] = $next;
                        $next = DB::table('employees')->where('id', $next)->value('supervisor_id');
                    }
                    $previous = $id ? DB::table('placements')->where('employee_id', $id)->whereNull('ends_on')->first() : null;
                    $changed = ! $before || $before->deleted_at !== null || (int) $before->position_id !== (int) $data['position_id'] || (int) ($before->department_id ?? 0) !== (int) $data['department_id'] || ($before->supervisor_id ?? null) != ($data['supervisor_id'] ?? null);
                    if ($changed && $previous && $start <= $previous->starts_on) {
                        throw ValidationException::withMessages(['starts_on' => 'Perubahan penempatan harus dimulai setelah penempatan sebelumnya.']);
                    }
                    $data['deleted_at'] = null;
                    if ($before) {
                        DB::table('employees')->where('id', $id)->update([...$data, 'updated_at' => now()]);
                    } else {
                        $id = DB::table('employees')->insertGetId([...$data, 'nik' => 'EMP-'.Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    if ($changed) {
                        DB::table('placements')->where('employee_id', $id)->whereNull('ends_on')->update(['ends_on' => CarbonImmutable::parse($start)->subDay()->toDateString(), 'updated_at' => now()]);
                        DB::table('placements')->insert(['employee_id' => $id, 'department_id' => $data['department_id'], 'position_id' => $data['position_id'], 'supervisor_id' => $data['supervisor_id'] ?? null, 'starts_on' => $start, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    break;
                case 'schedules':
                    $data = $request->validate(['employee_id' => 'required|exists:employees,id', 'shift_id' => 'required|exists:shifts,id', 'work_date' => 'required|date_format:Y-m-d']);
                    abort_unless(DB::table('employees')->where('id', $data['employee_id'])->whereNull('deleted_at')->lockForUpdate()->first() !== null, 422, 'Karyawan tidak aktif.');
                    if (DB::table('attendances')->where('employee_id', $data['employee_id'])->where('work_date', $data['work_date'])->exists()) {
                        throw ValidationException::withMessages(['work_date' => 'Jadwal dengan absensi tidak boleh ditimpa.']);
                    }
                    $shift = DB::table('shifts')->where('id', (int) ($data['shift_id']))->first();
                    $in = CarbonImmutable::parse($data['work_date'].' '.$shift->starts_at);
                    $out = CarbonImmutable::parse($data['work_date'].' '.$shift->ends_at);
                    if ($out->lte($in)) {
                        $out = $out->addDay();
                    }
                    DB::table('schedules')->updateOrInsert(['employee_id' => $data['employee_id'], 'work_date' => $data['work_date']], [...$data, 'scheduled_in' => $in, 'scheduled_out' => $out, 'tolerance_minutes' => $shift->tolerance_minutes, 'checkin_early_minutes' => $shift->checkin_early_minutes, 'checkout_after_minutes' => $shift->checkout_after_minutes, 'checkout_grace_minutes' => $shift->checkout_grace_minutes, 'created_at' => now(), 'updated_at' => now()]);
                    break;
                case 'roles':
                    $data = $request->validate(['id' => 'nullable|exists:roles,id', 'name' => 'required|string|max:150', 'grants' => 'present|array', 'grants.*.permission' => ['required', Rule::in(Access::PERMISSIONS), 'distinct'], 'grants.*.scope' => ['required', Rule::in(Access::SCOPES)]], ['name.required' => 'Nama peran wajib diisi.', 'name.max' => 'Nama peran maksimal 150 karakter.', 'name.string' => 'Nama peran harus berupa teks.', 'id.exists' => 'Peran tidak ditemukan.', 'grants.present' => 'Pilih hak akses untuk peran ini.', 'grants.array' => 'Pilihan hak akses tidak valid.', 'grants.*.permission.in' => 'Hak akses yang dipilih tidak tersedia.', 'grants.*.permission.distinct' => 'Hak akses yang sama hanya boleh dipilih satu kali.', 'grants.*.permission.required' => 'Pilih hak akses yang ingin diberikan.', 'grants.*.scope.in' => 'Cakupan akses tidak tersedia.', 'grants.*.scope.required' => 'Pilih cakupan akses.']);
                    foreach ($data['grants'] as $g) {
                        if (in_array($g['permission'], ['roles.manage', 'accounts.manage', 'organization.manage', 'kpi.manage', 'devices.manage', 'payroll.manage', 'payroll.publish']) && $g['scope'] !== 'all') {
                            throw ValidationException::withMessages(['grants' => 'Pengelolaan organisasi, akun, peran, perangkat, target, dan tampilan QR berlaku untuk seluruh perusahaan. Izin melihat laporan diberikan secara terpisah.']);
                        }
                    }
                    $id = $data['id'] ?? null;
                    validator($data, ['name' => Rule::unique('roles')->ignore($id)], ['name.unique' => 'Nama peran sudah digunakan. Pilih nama lain.'])->validate();
                    if ($id) {
                        $before = ['role' => DB::table('roles')->where('id', (int) ($id))->first(), 'grants' => DB::table('role_grants')->where('role_id', $id)->get()];
                        DB::table('roles')->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
                        DB::table('role_grants')->where('role_id', $id)->delete();
                    } else {
                        $id = DB::table('roles')->insertGetId(['name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
                    }
                    foreach ($data['grants'] as $g) {
                        DB::table('role_grants')->insert(['role_id' => $id, ...$g]);
                    }
                    break;
                case 'role-users':
                    $data = $request->validate(['user_id' => 'required|exists:users,id', 'role_ids' => 'present|array', 'role_ids.*' => 'required|exists:roles,id|distinct']);
                    $before = DB::table('role_user')->where('user_id', $data['user_id'])->get();
                    DB::table('role_user')->where('user_id', $data['user_id'])->delete();
                    foreach ($data['role_ids'] as $role) {
                        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $data['user_id']]);
                    } break;
                case 'periods':
                    $data = $request->validate(['name' => 'required|string|max:150', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on']);
                    $data['status'] = 'planning';
                    break;
                case 'period-status':
                    $data = $request->validate(['period_id' => 'required|exists:periods,id', 'status' => 'required|in:active,review,closed']);
                    $p = DB::table('periods')->where('id', $data['period_id'])->lockForUpdate()->first();
                    $next = ['planning' => 'active', 'active' => 'review', 'review' => 'closed'];
                    if (($next[$p->status] ?? null) !== $data['status']) {
                        throw ValidationException::withMessages(['status' => 'Transisi periode harus planning → active → review → closed.']);
                    }
                    $targets = DB::table('targets')->where('period_id', $p->id)->whereNotNull('employee_id')->get()->groupBy('employee_id');
                    if ($data['status'] === 'active') {
                        if ($targets->isEmpty()) {
                            throw ValidationException::withMessages(['period_id' => 'Tetapkan KPI individu sebelum mengaktifkan periode.']);
                        }
                        foreach ($targets as $group) {
                            if (abs($group->sum('weight') - 100) > 0.01) {
                                throw ValidationException::withMessages(['period_id' => 'Total bobot KPI setiap karyawan harus 100%.']);
                            }
                        }
                    }
                    if ($data['status'] === 'closed') {
                        foreach ($targets as $employeeId => $group) {
                            if (! DB::table('reviews')->where('employee_id', $employeeId)->where('period_id', $p->id)->where('status', 'published')->exists()) {
                                throw ValidationException::withMessages(['period_id' => 'Semua review karyawan harus diterbitkan sebelum periode ditutup.']);
                            }
                        }
                    }
                    DB::table('periods')->where('id', $p->id)->update(['status' => $data['status'], 'updated_at' => now()]);
                    $before = $p;
                    break;
                case 'metrics':
                    $data = $request->validate(['position_id' => 'required|exists:positions,id', 'name' => 'required|string|max:150', 'unit' => 'required|string|max:30', 'direction' => 'required|in:higher,lower', 'level' => 'required|in:individual,team', 'source' => 'required|string|max:255', 'score_cap' => 'required|numeric|min:100|max:200']);
                    break;
                case 'targets':
                    $data = $request->validate(['period_id' => 'required|exists:periods,id', 'metric_id' => 'required|exists:metrics,id', 'employee_id' => 'nullable|exists:employees,id', 'department_id' => 'nullable|exists:departments,id', 'shift_id' => 'nullable|exists:shifts,id', 'target' => 'required|numeric|gt:0', 'weight' => 'required|numeric|gt:0|max:100']);
                    $p = DB::table('periods')->where('id', $data['period_id'])->lockForUpdate()->first();
                    if ($p->status !== 'planning') {
                        throw ValidationException::withMessages(['period_id' => 'Target hanya dapat ditetapkan sebelum periode aktif.']);
                    }
                    $metric = DB::table('metrics')->where('id', (int) ($data['metric_id']))->first();
                    if ($metric->level === 'individual') {
                        $e = DB::table('employees')->where('id', (int) ($data['employee_id'] ?? 0))->first();
                        if (! $e || $e->position_id !== $metric->position_id || ! empty($data['department_id']) || ! empty($data['shift_id'])) {
                            throw ValidationException::withMessages(['employee_id' => 'Pilih karyawan dengan jabatan yang sesuai; kosongkan tim/shift.']);
                        }
                        if (DB::table('targets')->where('period_id', $p->id)->where('employee_id', $e->id)->when($id, fn ($q) => $q->where('id', '!=', $id))->sum('weight') + $data['weight'] > 100.001) {
                            throw ValidationException::withMessages(['weight' => 'Total bobot melebihi 100%.']);
                        }
                    } elseif (! empty($data['employee_id']) || empty($data['department_id'])) {
                        throw ValidationException::withMessages(['department_id' => 'KPI tim wajib memilih divisi, tanpa karyawan individu.']);
                    }
                    $duplicate = DB::table('targets')->where('period_id', $p->id)->where('metric_id', $data['metric_id'])
                        ->where('employee_id', $data['employee_id'] ?? null)->where('department_id', $data['department_id'] ?? null)->where('shift_id', $data['shift_id'] ?? null)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages(['metric_id' => 'Target indikator yang sama sudah ditetapkan.']);
                    }
                    $data['definition'] = json_encode((array) $metric);
                    break;
                case 'devices':
                    $data = $request->validate(['name' => 'required|string|max:150', 'type' => 'required|in:fingerprint,scanner,phone_camera', 'token' => ['nullable', 'string', 'min:40', 'max:200', Rule::requiredIf(! $id && $request->type === 'fingerprint')], 'active' => 'sometimes|boolean'], ['type.required' => 'Pilih jenis perangkat.', 'token.required' => 'Kunci koneksi diperlukan untuk perangkat sidik jari.']);
                    if ($id && $before->type !== $data['type']) {
                        throw ValidationException::withMessages(['type' => 'Jenis perangkat tidak dapat diganti. Daftarkan perangkat baru agar riwayat tetap jelas.']);
                    }
                    $data = ['name' => $data['name'], 'type' => $data['type'], 'active' => $data['active'] ?? true, ...(! empty($data['token']) ? ['token_hash' => hash('sha256', $data['token'])] : (! $id ? ['token_hash' => hash('sha256', Str::random(80))] : []))];
                    break;
                case 'device-mappings':
                    $data = $request->validate(['device_id' => 'required|exists:devices,id', 'employee_id' => 'required|exists:employees,id', 'external_id' => 'required|string|max:100']);
                    abort_unless(DB::table('devices')->where('id', $data['device_id'])->where('type', 'fingerprint')->exists(), 422, 'Pemetaan identitas hanya untuk perangkat sidik jari.');
                    DB::table('device_employees')->updateOrInsert(['device_id' => $data['device_id'], 'external_id' => $data['external_id']], $data);
                    break;
            }
            $tables = ['role-users' => null, 'period-status' => null, 'device-mappings' => null, 'employees' => null, 'schedules' => null, 'roles' => null];
            if (! array_key_exists($entity, $tables)) {
                if ($id) {
                    DB::table($entity)->where('id', $id)->update([...$data, 'updated_at' => now()]);
                } else {
                    $id = DB::table($entity)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if ($entity === 'devices' && $before) {
                unset($before->token_hash);
            }
            $safe = $data;
            unset($safe['token_hash']);
            Access::audit($request->user()->id, $entity.'.save', $entity.':'.($id ?? ''), $before, $safe);

            return back()->with('success', 'Perubahan berhasil disimpan.');
        });
    }
}
