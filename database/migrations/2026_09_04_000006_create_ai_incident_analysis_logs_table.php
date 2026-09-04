<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_incident_analysis_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('session_id')
                ->constrained('incident_intake_sessions', 'session_id')
                ->cascadeOnDelete();
            $table->string('phase', 40)->index();
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('operator_telegram_id', 100)->nullable()->index();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->json('normalized_response')->nullable();
            $table->string('status', 20)->default('started')->index();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_incident_analysis_logs');
    }
};
