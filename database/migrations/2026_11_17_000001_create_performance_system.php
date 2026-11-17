<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_superadmin')->default(false);
            $t->boolean('must_change_password')->default(false);
            $t->boolean('active')->default(true);
        });
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained();
            $t->string('name');
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });
        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->time('starts_at');
            $t->time('ends_at');
            $t->unsignedSmallInteger('tolerance_minutes')->default(0);
            $t->timestamps();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('nik')->unique();
            $t->foreignId('position_id')->constrained();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees');
            $t->boolean('allow_wfh')->default(false);
            $t->boolean('allow_wfo')->default(true);
            $t->timestamps();
        });
        Schema::create('placements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('position_id')->constrained();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamps();
        });
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('shift_id')->constrained();
            $t->date('work_date');
            $t->dateTime('scheduled_in');
            $t->dateTime('scheduled_out');
            $t->unsignedSmallInteger('tolerance_minutes');
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('role_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->string('permission');
            $t->string('scope');
            $t->unique(['role_id', 'permission']);
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->primary(['role_id', 'user_id']);
        });
        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status')->default('planning');
            $t->timestamps();
        });
        Schema::create('metrics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('position_id')->constrained();
            $t->string('name');
            $t->string('unit');
            $t->string('direction');
            $t->string('level');
            $t->string('source');
            $t->decimal('score_cap', 8, 2)->default(120);
            $t->timestamps();
        });
        Schema::create('targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('period_id')->constrained();
            $t->foreignId('metric_id')->constrained();
            $t->foreignId('employee_id')->nullable()->constrained();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->foreignId('shift_id')->nullable()->constrained();
            $t->decimal('target', 16, 4);
            $t->decimal('weight', 8, 2);
            $t->json('definition');
            $t->timestamps();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('target_id')->unique()->constrained();
            $t->decimal('actual', 16, 4);
            $t->string('evidence_path')->nullable();
            $t->text('note');
            $t->string('status')->default('pending');
            $t->foreignId('submitted_by')->constrained('users');
            $t->foreignId('validated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('period_id')->constrained();
            $t->string('status')->default('draft');
            $t->text('analysis');
            $t->text('follow_up');
            $t->date('due_on');
            $t->dateTime('published_at')->nullable();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->json('snapshot')->nullable();
            $t->text('response')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['employee_id', 'period_id']);
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->date('work_date');
            $t->dateTime('clock_in')->nullable();
            $t->dateTime('clock_out')->nullable();
            $t->string('mode');
            $t->string('source');
            $t->string('status')->default('present');
            $t->unsignedInteger('late_minutes')->default(0);
            $t->unsignedInteger('early_minutes')->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
        });
        Schema::create('requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->date('work_date');
            $t->string('type');
            $t->text('reason');
            $t->json('payload')->nullable();
            $t->string('status')->default('pending');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('qr_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash')->unique();
            $t->foreignId('issued_by')->constrained('users');
            $t->dateTime('expires_at');
            $t->timestamps();
        });
        Schema::create('qr_uses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qr_token_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('event');
            $t->unique(['qr_token_id', 'employee_id']);
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('token_hash')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('device_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('external_id');
            $t->unique(['device_id', 'external_id']);
        });
        Schema::create('device_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained();
            $t->string('external_event_id');
            $t->dateTime('occurred_at');
            $t->timestamps();
            $t->unique(['device_id', 'external_event_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('subject');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'device_events', 'device_employees', 'devices', 'qr_uses', 'qr_tokens', 'requests', 'attendances', 'reviews', 'submissions', 'targets', 'metrics', 'periods', 'role_user', 'role_grants', 'roles', 'schedules', 'placements', 'employees', 'shifts', 'positions', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_superadmin', 'must_change_password', 'active']));
    }
};
