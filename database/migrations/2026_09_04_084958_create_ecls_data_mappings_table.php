<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_data_mappings', function (Blueprint $table) {
            $table->id();

            // The template element that needs data.
            // Example: Student Name Box or Barcode Box.
            $table->foreignId('template_element_id')
                ->constrained('ecls_template_elements')
                ->cascadeOnDelete();

            // The name of the field/column from the imported student data.
            // Example: barcode, student_name, student_id, course, batch.
            $table->string('source_field');

            // Whether this mapping is required before card generation.
            // Example: Barcode can be required, while an optional field
            // such as Course may not be required.
            $table->boolean('is_required')->default(false);

            $table->timestamps();

            // One template element should normally have one data mapping.
            $table->unique('template_element_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_data_mappings');
    }
};
