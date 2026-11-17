<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->change();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('deleted_at')->nullable());
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->constrained();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::table('placements', fn (Blueprint $t) => $t->foreignId('department_id')->nullable()->constrained());
        foreach (DB::table('positions')->whereNotNull('department_id')->get() as $position) {
            DB::table('employees')->where('position_id', $position->id)->update(['department_id' => $position->department_id]);
            DB::table('placements')->where('position_id', $position->id)->update(['department_id' => $position->department_id]);
        }
        foreach (['shifts', 'schedules'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedSmallInteger('checkin_early_minutes')->default(120);
                $t->unsignedSmallInteger('checkout_after_minutes')->default(240);
                $t->unsignedSmallInteger('checkout_grace_minutes')->default(120);
            });
        }
    }

    public function down(): void
    {
        foreach (['shifts', 'schedules'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['checkin_early_minutes', 'checkout_after_minutes', 'checkout_grace_minutes']));
        }
        Schema::table('placements', function (Blueprint $t) {
            $t->dropForeign(['department_id']);
            $t->dropColumn('department_id');
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->dropForeign(['department_id']);
            $t->dropColumn(['department_id', 'deleted_at']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deleted_at'));
        Schema::table('positions', fn (Blueprint $t) => $t->dropColumn('deleted_at'));
        // Standalone positions cannot safely be assigned an invented legacy division on rollback.
    }
};
