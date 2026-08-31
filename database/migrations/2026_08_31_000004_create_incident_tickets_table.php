<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 40)->unique();
            $table->foreignUuid('session_id')->unique()->constrained('incident_intake_sessions', 'session_id')->cascadeOnDelete();
            $table->string('title');
            $table->longText('description');
            $table->enum('scope', ['system_wide', 'user_specific', 'unknown'])->default('unknown');
            $table->json('sample_data')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('priority', 30)->default('normal');
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->string('root_cause_category', 100)->nullable();
            $table->longText('root_cause_description')->nullable();
            $table->longText('resolution_action')->nullable();
            $table->unsignedBigInteger('root_cause_author_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_tickets');
    }
};
