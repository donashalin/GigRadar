<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('genre_id')->nullable();
            $table->string('genre_name')->nullable();
            $table->string('sub_genre_id')->nullable();
            $table->string('sub_genre_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn(['genre_id', 'genre_name', 'sub_genre_id', 'sub_genre_name']);
        });
    }
};
