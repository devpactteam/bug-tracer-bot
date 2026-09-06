<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_users', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee_chat_id')->nullable()->after('telegram_id')->comment('Numeric Telegram chat_id captured when the user /start the assignee bot');
        });

        Schema::table('incident_intake_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_suggested_assignee_id')->nullable()->after('selected_assignee_id');
            $table->unsignedBigInteger('preview_message_id')->nullable()->after('ai_suggested_assignee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_intake_sessions', function (Blueprint $table) {
            $table->dropColumn(['preview_message_id', 'ai_suggested_assignee_id']);
        });

        Schema::table('support_users', function (Blueprint $table) {
            $table->dropColumn('assignee_chat_id');
        });
    }
};
