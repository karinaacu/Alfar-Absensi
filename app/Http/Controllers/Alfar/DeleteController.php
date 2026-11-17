<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use App\Services\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteController extends Controller
{
    public function destroy(Request $r, string $entity, int $id, Access $a): RedirectResponse
    {
        abort_unless(in_array($entity, ['positions', 'accounts', 'employees']), 404);
        abort_unless(in_array('all', $a->grants($r->user(), $entity === 'accounts' ? 'accounts.manage' : 'organization.manage')), 403);
        DB::transaction(function () use ($r, $entity, $id) {
            $table = $entity === 'accounts' ? 'users' : $entity;
            $row = DB::table($table)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($entity === 'accounts') {
                abort_if($row->is_superadmin || $id === $r->user()->id, 422, 'Akun sendiri dan superadmin tidak dapat dihapus.');
            }
            if ($entity === 'employees') {
                $user = DB::table('users')->where('id', $row->user_id)->first();
                abort_if($user->is_superadmin || $row->user_id === $r->user()->id, 422, 'Profil sendiri dan superadmin tidak dapat dihapus.');
            }
            DB::table($table)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            if ($entity === 'accounts') {
                DB::table('users')->where('id', $id)->update(['active' => false, 'remember_token' => null]);
                DB::table('role_user')->where('user_id', $id)->delete();
                DB::table('employees')->where('user_id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
            if ($entity === 'employees') {
                DB::table('users')->where('id', $row->user_id)->update(['active' => false, 'remember_token' => null, 'updated_at' => now()]);
            }
            if ($entity !== 'positions') {
                DB::table('sessions')->where('user_id', $entity === 'accounts' ? $id : $row->user_id)->delete();
            }
            $employeeId = $entity === 'employees' ? $id : ($entity === 'accounts' ? DB::table('employees')->where('user_id', $id)->value('id') : null);
            if ($employeeId !== null) {
                DB::table('employees')->where('supervisor_id', $employeeId)->update(['supervisor_id' => null, 'updated_at' => now()]);
            }
            Access::audit($r->user()->id, $entity.'.delete', $entity.':'.$id, ['name' => $row->name ?? null], ['archived' => true]);
        });

        return back()->with('success', 'Data dihapus dari daftar aktif. Riwayat tetap tersimpan.');
    }
}
