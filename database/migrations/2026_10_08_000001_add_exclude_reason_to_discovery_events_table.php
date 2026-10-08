<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_events', function (Blueprint $table) {
            $table->string('exclude_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('discovery_events', function (Blueprint $table) {
            $table->dropColumn('exclude_reason');
        });
    }
};
