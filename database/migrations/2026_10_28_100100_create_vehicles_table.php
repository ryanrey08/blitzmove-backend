<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique();
            $table->string('vehicle_type', 30)->index();
            $table->string('make', 60);
            $table->string('model', 60);
            $table->unsignedSmallInteger('year');
            $table->decimal('capacity_kg', 10, 2)->nullable();
            $table->string('fuel_type', 20)->default('diesel');
            $table->string('vin', 50)->nullable()->unique();
            $table->string('status', 20)->default('available')->index();
            $table->foreignId('assigned_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
