<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->unique();
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('email')->nullable()->unique();
            $table->string('address')->nullable();
            $table->string('license_number', 50)->unique();
            $table->string('license_type', 30);
            $table->date('license_expiry');
            $table->date('date_hired')->nullable();
            $table->string('status', 20)->default('available')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('full_name');
            $table->index('license_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
