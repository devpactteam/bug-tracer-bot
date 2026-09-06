<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('session_id')->constrained('incident_intake_sessions', 'session_id')->cascadeOnDelete();
            $table->string('telegram_message_id', 100);
            $table->longText('content')->nullable();
            $table->string('media_type', 50)->nullable();
            $table->string('media_file_id', 255)->nullable();
            $table->json('forward_origin_metadata')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'telegram_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_messages');
    }
};
