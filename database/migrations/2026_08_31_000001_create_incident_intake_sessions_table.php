<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_intake_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->string('telegram_chat_id', 100)->index();
            $table->string('operator_telegram_id', 100)->index();
            $table->enum('status', [
                'collecting', 'analyzing', 'awaiting_clarification',
                'awaiting_approval', 'completed', 'cancelled',
            ])->default('collecting')->index();
            $table->json('ai_analysis_result')->nullable();
            $table->text('clarification_question')->nullable();
            $table->unsignedBigInteger('selected_assignee_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_intake_sessions');
    }
};
