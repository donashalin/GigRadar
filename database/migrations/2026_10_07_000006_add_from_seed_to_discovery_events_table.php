<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_events', function (Blueprint $table) {
            $table->boolean('from_seed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('discovery_events', function (Blueprint $table) {
            $table->dropColumn('from_seed');
        });
    }
};
