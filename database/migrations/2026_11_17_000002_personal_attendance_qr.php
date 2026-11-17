<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_tokens', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->constrained('employees');
            $t->index(['employee_id', 'expires_at']);
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->string('type')->default('fingerprint');
        });
        // Existing display-only roles now operate the office scanner, not employee QR issuance.
        foreach (DB::table('role_grants')->where('permission', 'qr.display')->get() as $grant) {
            DB::table('role_grants')->updateOrInsert(['role_id' => $grant->role_id, 'permission' => 'attendance.scan'], ['scope' => $grant->scope]);
        }
        DB::table('role_grants')->where('permission', 'qr.display')->delete();
    }

    public function down(): void
    {
        foreach (DB::table('role_grants')->where('permission', 'attendance.scan')->get() as $grant) {
            DB::table('role_grants')->updateOrInsert(['role_id' => $grant->role_id, 'permission' => 'qr.display'], ['scope' => $grant->scope]);
        }
        DB::table('role_grants')->where('permission', 'attendance.scan')->delete();
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('type'));
        Schema::table('qr_tokens', function (Blueprint $t) {
            $t->dropIndex(['employee_id', 'expires_at']);
            $t->dropForeign(['employee_id']);
            $t->dropColumn('employee_id');
        });
    }
};
