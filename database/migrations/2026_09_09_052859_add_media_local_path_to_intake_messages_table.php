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
        Schema::table('intake_messages', function (Blueprint $table) {
            $table->string('media_local_path')->nullable()->after('media_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_messages', function (Blueprint $table) {
            $table->dropColumn('media_local_path');
        });
    }
};
