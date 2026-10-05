<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('home_location_name')->nullable();
            $table->decimal('home_lat', 10, 7)->nullable();
            $table->decimal('home_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_miles')->default(50);
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_push')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_location_name', 'home_lat', 'home_lng', 'radius_miles', 'notify_email', 'notify_push']);
        });
    }
};
