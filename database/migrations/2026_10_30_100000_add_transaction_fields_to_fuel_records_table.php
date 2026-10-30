<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_records', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('total_cost');
            // Free-form PO / trip / reference number.
            $table->string('reference_number', 60)->nullable()->after('receipt_number');
            // Private-disk receipt photo, served through the API.
            $table->string('receipt_path')->nullable()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_records', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'reference_number', 'receipt_path']);
        });
    }
};
