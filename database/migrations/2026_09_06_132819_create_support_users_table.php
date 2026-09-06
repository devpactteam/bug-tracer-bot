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
        Schema::create('support_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('name');
            $table->string('role')->default('support');
            $table->json('categories_covered')->nullable();
            $table->boolean('can_be_assignee')->default(true);
            $table->boolean('auto_assign_on_mention')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_users');
    }
};
