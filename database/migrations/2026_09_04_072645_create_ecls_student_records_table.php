<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_student_records', function (Blueprint $table) {
            $table->id();

            // The project that owns this student record.
            $table->foreignId('project_id')
                ->constrained('ecls_projects')
                ->cascadeOnDelete();

            // Stored as a string so leading zeros are preserved.
            // Example: 00000001 must not become 1.
            $table->string('barcode');

            // Student ID from the Excel file.
            // Example: F62948091.
            $table->string('student_id')->nullable();

            // Student's name from Excel.
            $table->string('student_name')->nullable();

            // Stores additional Excel columns that are not fixed fields.
            // Example: course, batch, address, DOB, phone, etc.
            $table->json('data')->nullable();

            $table->timestamps();

            // A barcode should be unique within a project.
            $table->unique(['project_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_student_records');
    }
};
