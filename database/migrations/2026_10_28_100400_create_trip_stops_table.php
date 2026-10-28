<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('type', 20);
            $table->string('location_name');
            $table->string('address')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('estimated_arrival_at')->nullable();
            $table->dateTime('actual_arrival_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stops');
    }
};
