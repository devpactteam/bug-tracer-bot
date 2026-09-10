<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('incident_tickets')->cascadeOnDelete();
            $table->string('action', 80);
            $table->longText('description')->nullable();
            $table->string('actor', 190)->nullable();
            $table->longText('from_value')->nullable();
            $table->longText('to_value')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->longText('bug_owner_note')->nullable()->after('resolution_note');
            $table->longText('bug_cause_note')->nullable()->after('bug_owner_note');
            $table->longText('bug_action_note')->nullable()->after('bug_cause_note');
            $table->unsignedTinyInteger('resolution_step')->default(0)->after('bug_action_note');
        });
    }

    public function down(): void
    {
        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->dropColumn(['bug_owner_note', 'bug_cause_note', 'bug_action_note', 'resolution_step']);
        });
        Schema::dropIfExists('ticket_logs');
    }
};
