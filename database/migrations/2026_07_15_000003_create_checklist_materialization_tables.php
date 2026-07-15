<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record every materialized operational date, including intentionally empty
     * sheets, and provide a durable mutex for template synchronization.
     */
    public function up(): void
    {
        Schema::create('checklist_materializations', function (Blueprint $table) {
            $table->date('date');
            $table->primary('date');
        });

        Schema::create('checklist_sync_locks', function (Blueprint $table) {
            $table->string('name', 64);
            $table->primary('name');
        });

        DB::table('checklist_sync_locks')->insert([
            'name' => 'template-synchronization',
        ]);

        // Preserve any non-empty sheets if this migration is applied to an
        // already-running installation.
        DB::table('daily_checklists')
            ->select('date')
            ->distinct()
            ->orderBy('date')
            ->get()
            ->each(static fn (object $row) => DB::table('checklist_materializations')->insertOrIgnore([
                'date' => $row->date,
            ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_sync_locks');
        Schema::dropIfExists('checklist_materializations');
    }
};
