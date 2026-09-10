<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('assignee_message_id')->nullable()->after('resolution_pending');
        });
    }

    public function down(): void
    {
        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->dropColumn('assignee_message_id');
        });
    }
};
