<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Access
{
    public const PERMISSIONS = ['organization.manage', 'accounts.manage', 'roles.manage', 'attendance.read', 'attendance.manage', 'attendance.approve', 'attendance.scan', 'devices.manage', 'kpi.manage', 'kpi.submit', 'kpi.validate', 'reviews.publish', 'reports.read'];

    public const SCOPES = ['self', 'reports', 'department', 'all'];

    public function employee(User $user): ?\stdClass
    {
        return DB::table('employees')->join('positions', 'positions.id', '=', 'employees.position_id')
            ->select('employees.*', DB::raw('COALESCE(employees.department_id, positions.department_id) as department_id'))->whereNull('employees.deleted_at')->where('user_id', $user->id)->first();
    }

    /** @return list<string> */
    public function grants(User $user, string $permission): array
    {
        if (! $user->active || $user->deleted_at !== null) {
            return [];
        }
        if ($user->is_superadmin) {
            return ['all'];
        }

        return array_values(array_map('strval', DB::table('role_grants')->join('role_user', 'role_user.role_id', '=', 'role_grants.role_id')
            ->where('role_user.user_id', $user->id)->where('permission', $permission)->pluck('scope')->all()));
    }

    public function allows(User $user, string $permission, ?int $employeeId = null): bool
    {
        $scopes = $this->grants($user, $permission);
        if (in_array('all', $scopes, true)) {
            return true;
        }
        if ($employeeId === null) {
            return count($scopes) > 0;
        }
        $me = $this->employee($user);
        $employee = DB::table('employees')->join('positions', 'positions.id', '=', 'employees.position_id')
            ->select('employees.*', DB::raw('COALESCE(employees.department_id, positions.department_id) as department_id'))->whereNull('employees.deleted_at')->where('employees.id', $employeeId)->first();
        if (! $me || ! $employee) {
            return false;
        }

        return (in_array('self', $scopes, true) && $me->id === $employee->id)
            || (in_array('reports', $scopes, true) && $employee->supervisor_id === $me->id)
            || (in_array('department', $scopes, true) && $me->department_id !== null && $employee->department_id === $me->department_id);
    }

    public function authorize(User $user, string $permission, ?int $employeeId = null): void
    {
        abort_unless($this->allows($user, $permission, $employeeId), 403, 'Anda tidak memiliki akses untuk tindakan ini.');
    }

    /** @return array<int, int> */
    public function ids(User $user, string $permission): array
    {
        return DB::table('employees')->whereNull('deleted_at')->pluck('id')->filter(fn ($id) => $this->allows($user, $permission, $id))->all();
    }

    public function team(User $user, string $permission, int $departmentId): bool
    {
        $scopes = $this->grants($user, $permission);
        $me = $this->employee($user);

        return in_array('all', $scopes, true) || (in_array('department', $scopes, true) && $me?->department_id === $departmentId);
    }

    public static function audit(?int $actor, string $action, string $subject, mixed $before = null, mixed $after = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $action, $subject, $before, $after, $reason) {
            $auditId = DB::table('audit_logs')->insertGetId(['actor_id' => $actor, 'action' => $action, 'subject' => $subject, 'before' => $before === null ? null : json_encode($before), 'after' => $after === null ? null : json_encode($after), 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            ActivityNotifications::fromAudit($auditId, $actor, $action, $subject, $before, $after);
        });
    }
}
