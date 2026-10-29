<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('full_name');
            $table->string('license_restrictions', 100)->nullable()->after('license_expiry');
            $table->string('emergency_contact_name')->nullable()->after('address');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relation', 50)->nullable()->after('emergency_contact_phone');
            // Private-disk photo, served through the API.
            $table->string('photo_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'license_restrictions', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation', 'photo_path']);
        });
    }
};
