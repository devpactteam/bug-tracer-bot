<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_intake_sessions', function (Blueprint $table): void {
            $table->json('telegram_bot_message_ids')->nullable()->after('selected_assignee_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_intake_sessions', function (Blueprint $table): void {
            $table->dropColumn('telegram_bot_message_ids');
        });
    }
};
