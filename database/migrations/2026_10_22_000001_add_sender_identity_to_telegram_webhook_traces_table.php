<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_webhook_traces', function (Blueprint $table): void {
            $table->string('sender_username')->nullable()->index();
            $table->unsignedBigInteger('sender_telegram_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_webhook_traces', function (Blueprint $table): void {
            $table->dropIndex(['sender_username']);
            $table->dropIndex(['sender_telegram_id']);
            $table->dropColumn(['sender_username', 'sender_telegram_id']);
        });
    }
};
