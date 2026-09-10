<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->longText('resolution_note')->nullable()->after('resolution_action');
            $table->boolean('resolution_pending')->default(false)->after('resolution_note')->index();
        });
    }

    public function down(): void
    {
        Schema::table('incident_tickets', function (Blueprint $table): void {
            $table->dropColumn(['resolution_note', 'resolution_pending']);
        });
    }
};
