<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A document can now hold several files. The single file columns on
 * vehicle_documents / driver_documents move into document_files.
 */
return new class extends Migration
{
    private const TABLES = ['vehicle_documents' => 'vehicle_document', 'driver_documents' => 'driver_document'];

    private const FILE_COLUMNS = ['file_path', 'original_name', 'mime_type', 'file_size'];

    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->morphs('document');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (self::TABLES as $table => $morph) {
            DB::table($table)->whereNotNull('file_path')->orderBy('id')->each(function ($row) use ($morph) {
                DB::table('document_files')->insert([
                    'document_type' => $morph,
                    'document_id' => $row->id,
                    'file_path' => $row->file_path,
                    'original_name' => $row->original_name ?? basename($row->file_path),
                    'mime_type' => $row->mime_type,
                    'file_size' => $row->file_size,
                    'uploaded_by' => $row->uploaded_by,
                    'created_at' => $row->updated_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(self::FILE_COLUMNS);
                $table->string('issuing_authority', 120)->nullable()->after('expires_at');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $morph) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('issuing_authority');
                $table->string('file_path')->nullable();
                $table->string('original_name')->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
            });

            // Keep the first file of each document.
            DB::table('document_files')->where('document_type', $morph)->orderByDesc('id')->each(function ($file) use ($table) {
                DB::table($table)->where('id', $file->document_id)->update([
                    'file_path' => $file->file_path,
                    'original_name' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'file_size' => $file->file_size,
                ]);
            });
        }

        Schema::dropIfExists('document_files');
    }
};
