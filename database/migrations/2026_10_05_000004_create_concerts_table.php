<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('ticketmaster_id');
            $table->string('name');
            $table->dateTime('starts_at');
            $table->string('venue_name');
            $table->string('city');
            $table->string('country');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('ticket_url', 1024);
            $table->enum('status', ['onsale', 'offsale', 'cancelled', 'postponed', 'rescheduled'])->default('onsale');
            $table->timestamp('first_seen_at');
            $table->timestamps();
            $table->unique(['artist_id', 'ticketmaster_id']);
            $table->index(['artist_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concerts');
    }
};
