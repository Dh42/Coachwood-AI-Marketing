<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each run of a scheduled task: by the scheduler on its timer, or by a
 * person from the Scheduled tasks page. Old rows are pruned
 * (scheduler.keep_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 120);
            // schedule (the timer) or manual (a person, user_id)
            $table->string('source', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // queued (a manual run waiting its turn), running, ok, failed
            $table->string('status', 10);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->text('error')->nullable();
            // What a manual run printed; the scheduler's own runs print to nowhere.
            $table->text('output')->nullable();

            $table->index(['command', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
