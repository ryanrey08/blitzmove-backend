<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('reference_no', 100)->nullable()->after('trip_number');
            $table->string('trip_type', 20)->default('delivery')->after('reference_no')->index();
            $table->string('priority', 20)->default('normal')->after('trip_type')->index();
            $table->foreignId('co_driver_id')->nullable()->after('driver_id')->constrained('drivers')->nullOnDelete();
        });

        Schema::table('trip_stops', function (Blueprint $table) {
            $table->string('activity', 20)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('trip_stops', function (Blueprint $table) {
            $table->dropColumn('activity');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('co_driver_id');
            $table->dropIndex(['trip_type']);
            $table->dropIndex(['priority']);
            $table->dropColumn(['reference_no', 'trip_type', 'priority']);
        });
    }
};
