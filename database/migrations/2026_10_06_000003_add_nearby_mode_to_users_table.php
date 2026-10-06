<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('home_country_code', 2)->nullable()->after('home_lng');
            $table->enum('nearby_mode', ['country', 'radius'])->default('country')->after('radius_miles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_country_code', 'nearby_mode']);
        });
    }
};
