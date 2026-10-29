<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('color', 30)->nullable()->after('year');
            $table->string('body_type', 30)->nullable()->after('color');
            $table->unsignedTinyInteger('wheels')->nullable()->after('body_type');
            $table->string('engine_number', 50)->nullable()->after('vin');
            $table->string('home_depot', 100)->nullable()->after('assigned_driver_id');
            $table->string('gps_device_id', 50)->nullable()->unique()->after('home_depot');
            $table->unsignedInteger('odometer_km')->nullable()->after('gps_device_id');
            $table->date('acquired_at')->nullable()->after('odometer_km');
            $table->decimal('cargo_volume_m3', 7, 2)->nullable()->after('capacity_kg');
            $table->decimal('length_m', 5, 2)->nullable()->after('cargo_volume_m3');
            $table->decimal('width_m', 5, 2)->nullable()->after('length_m');
            $table->decimal('height_m', 5, 2)->nullable()->after('width_m');
            $table->decimal('fuel_tank_l', 7, 2)->nullable()->after('fuel_type');
            $table->decimal('fuel_economy_kml', 5, 2)->nullable()->after('fuel_tank_l');
            $table->string('emission_standard', 20)->nullable()->after('fuel_economy_kml');
            $table->json('features')->nullable()->after('emission_standard');
        });

        Schema::create('vehicle_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 60);
            $table->unsignedInteger('file_size');
            $table->unsignedTinyInteger('position')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photos');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['gps_device_id']);
            $table->dropColumn([
                'color', 'body_type', 'wheels', 'engine_number', 'home_depot', 'gps_device_id', 'odometer_km', 'acquired_at',
                'cargo_volume_m3', 'length_m', 'width_m', 'height_m', 'fuel_tank_l', 'fuel_economy_kml', 'emission_standard', 'features',
            ]);
        });
    }
};
