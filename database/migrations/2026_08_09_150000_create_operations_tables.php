<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 20);
            $t->string('status', 20)->index();
            $t->string('disk', 30);
            $t->string('file_name')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('scheduler_heartbeats', function (Blueprint $t): void {
            $t->id();
            $t->string('task', 100)->unique();
            $t->string('status', 20);
            $t->timestamp('last_started_at')->nullable();
            $t->timestamp('last_succeeded_at')->nullable();
            $t->text('safe_message')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('backup_runs');
    }
};
