<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_template_elements', function (Blueprint $table) {
            $table->id();

            // The template this element belongs to.
            // If the template is deleted, its elements are deleted too.
            $table->foreignId('template_id')
                ->constrained('ecls_templates')
                ->cascadeOnDelete();

            // Which side of the card this element belongs to.
            // Example: front or back.
            $table->enum('side', ['front', 'back']);

            // Type of element placed on the card.
            // Examples: photo, barcode, student_name, student_id_grid, etc.
            $table->string('type');

            // Name used to identify this element inside the editor.
            // Example: "Student Name", "Profile Photo", "Main Barcode".
            $table->string('name')->nullable();

            // Excel/data field connected to this element.
            // Example: barcode, student_name, student_id.
            // Static elements can leave this empty.
            $table->string('field_name')->nullable();

            // Position and size are stored in millimetres.
            // This allows accurate physical card printing.
            $table->decimal('x_mm', 8, 2)->default(0);
            $table->decimal('y_mm', 8, 2)->default(0);
            $table->decimal('width_mm', 8, 2)->default(10);
            $table->decimal('height_mm', 8, 2)->default(10);

            // Allows the user to hide/show an element without deleting it.
            $table->boolean('visible')->default(true);

            // Stores additional editor properties as JSON.
            // Examples: font size, font family, alignment, border,
            // barcode format, text settings, ID-grid settings, etc.
            $table->json('properties')->nullable();

            // Used to control which element appears above another
            // when elements overlap in the editor.
            $table->integer('z_index')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_template_elements');
    }
};
