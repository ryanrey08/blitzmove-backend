<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 40);
            $table->string('category', 20);
            $table->string('status', 20)->default('scheduled');
            // "Next Due" while open; the day the work is planned for.
            $table->date('scheduled_date');
            // The day the work was actually done; required once completed.
            $table->date('service_date')->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->string('service_provider')->nullable();
            $table->string('assigned_to', 120)->nullable();
            $table->unsignedTinyInteger('reminder_days')->nullable();
            $table->text('description')->nullable();
            // [{name, quantity, unit_cost}]
            $table->json('parts')->nullable();
            // [{item, done}]
            $table->json('checklist')->nullable();
            $table->text('notes')->nullable();
            // Set by the API on status changes; used for the month-over-month cards.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'scheduled_date']);
            $table->index(['status', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
