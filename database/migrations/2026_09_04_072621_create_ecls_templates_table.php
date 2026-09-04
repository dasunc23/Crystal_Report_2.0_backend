<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('ecls_projects')
                ->cascadeOnDelete();

            $table->string('name');

            $table->decimal('width_mm', 8, 2)->default(92.00);
            $table->decimal('height_mm', 8, 2)->default(57.00);

            $table->string('front_design_path')->nullable();
            $table->string('back_design_path')->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_templates');
    }
};
