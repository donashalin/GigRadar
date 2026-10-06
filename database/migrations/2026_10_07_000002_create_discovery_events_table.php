<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('ticketmaster_event_id');
            $table->string('classification_id');
            $table->string('attraction_ticketmaster_id');
            $table->string('attraction_name');
            $table->string('attraction_image_url', 1024)->nullable();
            $table->string('name');
            $table->dateTime('starts_at');
            $table->date('local_date')->nullable();
            $table->string('venue_name');
            $table->string('city');
            $table->string('country');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('ticket_url', 1024);
            $table->enum('status', ['onsale', 'offsale', 'cancelled', 'postponed', 'rescheduled'])->default('onsale');
            $table->timestamp('first_seen_at');
            $table->timestamps();
            $table->unique(['classification_id', 'ticketmaster_event_id']);
            $table->index(['classification_id', 'country', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_events');
    }
};
