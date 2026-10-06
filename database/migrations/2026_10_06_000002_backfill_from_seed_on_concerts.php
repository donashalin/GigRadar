<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Seeding stamps alerted_at and first_seen_at with the same timestamp;
        // concerts found later have alerted_at NULL at insert.
        DB::table('concerts')->whereNotNull('alerted_at')->whereColumn('alerted_at', 'first_seen_at')->update(['from_seed' => true]);
    }

    public function down(): void
    {
        //
    }
};
