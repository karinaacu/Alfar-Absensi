<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->unique()->constrained();
            $t->unsignedBigInteger('base_salary');
            $t->json('components');
            $t->timestamps();
        });
        Schema::create('overtime_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->date('work_date');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedInteger('break_minutes')->default(0);
            $t->unsignedInteger('minutes');
            $t->text('reason');
            $t->string('status')->default('pending');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'work_date', 'status']);
        });
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->date('pays_on');
            $t->string('status')->default('draft');
            $t->unsignedInteger('safe_minutes')->nullable();
            $t->unsignedInteger('warning_minutes')->nullable();
            $t->unsignedInteger('free_late_minutes')->default(0);
            $t->unsignedBigInteger('late_rate')->nullable();
            $t->unsignedBigInteger('late_cap')->nullable();
            $t->unsignedBigInteger('overtime_rate')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('published_by')->nullable()->constrained('users');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payroll_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->json('adjustments');
            $t->json('snapshot')->nullable();
            $t->string('source_hash')->nullable();
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['payroll_items', 'payroll_runs', 'overtime_requests', 'salary_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
