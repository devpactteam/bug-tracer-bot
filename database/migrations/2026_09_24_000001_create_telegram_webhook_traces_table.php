<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_webhook_traces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->unique();
            $table->unsignedBigInteger('update_id')->nullable()->index();
            $table->string('update_type', 50)->nullable();
            $table->string('outcome', 40)->default('received')->index();
            $table->string('latest_checkpoint', 100)->nullable();
            $table->uuid('session_id')->nullable()->index();
            $table->string('failure_class', 190)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['outcome', 'created_at']);
        });

        Schema::create('telegram_webhook_trace_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trace_id')->constrained('telegram_webhook_traces')->cascadeOnDelete();
            $table->string('checkpoint', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['trace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_trace_events');
        Schema::dropIfExists('telegram_webhook_traces');
    }
};
