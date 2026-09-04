<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_student_photos', function (Blueprint $table) {
            $table->id();

            // The student this photo belongs to.
            $table->foreignId('student_record_id')
                ->constrained('ecls_student_records')
                ->cascadeOnDelete();

            // Barcode extracted from the photo filename.
            // Example: 00000001.jpg -> 00000001
            // Stored as a string to preserve leading zeros.
            $table->string('barcode');

            // Actual location of the uploaded photo.
            // We store the path, not the image itself in MySQL.
            $table->string('file_path');

            // Original filename uploaded by the user.
            $table->string('original_filename')->nullable();

            // Used to track whether the photo was matched successfully.
            $table->enum('match_status', [
                'matched',
                'missing',
                'invalid',
            ])->default('matched');

            // Useful for identifying image format/metadata later.
            $table->string('mime_type')->nullable();

            $table->unsignedBigInteger('file_size')->nullable();

            $table->timestamps();

            // One student should have one active matched photo.
            $table->unique('student_record_id');

            // Barcode should also identify only one photo record.
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_student_photos');
    }
};
