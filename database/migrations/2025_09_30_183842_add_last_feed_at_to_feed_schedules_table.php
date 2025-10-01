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
        Schema::table('feed_schedules', function (Blueprint $table) {
            $table->timestamp('last_feed_at')->nullable();
            $table->integer('interval_seconds')->default(3600); // Default 1 hour
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feed_schedules', function (Blueprint $table) {
            $table->dropColumn(['last_feed_at', 'interval_seconds']);
        });
    }
};
