<?php

use App\Enums\TaskSession;
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
        Schema::create('daily_checklists', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('task_template_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('task_name');
            $table->enum('session', TaskSession::values());
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at', 6)->nullable();
            $table->foreignId('completed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unique(['date', 'task_template_id']);
            $table->index('date');
            $table->index(['date', 'is_completed']);
            $table->index(['is_completed', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_checklists');
    }
};
