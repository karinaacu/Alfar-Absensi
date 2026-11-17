<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('audit_id');
            $t->string('event');
            $t->string('title');
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'audit_id']);
            $t->index(['user_id', 'id']);
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_notifications');
    }
};
