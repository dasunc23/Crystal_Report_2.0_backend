<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecls_generation_jobs', function (Blueprint $table) {
            $table->id();

            // The project for which cards are being generated.
            $table->foreignId('project_id')
                ->constrained('ecls_projects')
                ->cascadeOnDelete();

            // The template selected for this generation.
            $table->foreignId('template_id')
                ->constrained('ecls_templates')
                ->cascadeOnDelete();

            // Current state of the generation process.
            // pending    = waiting to start
            // processing = currently generating
            // completed  = successfully finished
            // failed     = generation encountered an error
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
            ])->default('pending');

            // Total number of student cards that need to be generated.
            $table->unsignedInteger('total_records')->default(0);

            // Number of records successfully processed so far.
            // This allows the frontend to show progress.
            $table->unsignedInteger('processed_records')->default(0);

            // Location of the final print-ready PDF.
            // This remains empty until generation is completed.
            $table->string('output_path')->nullable();

            // Stores the reason if generation fails.
            $table->text('error_message')->nullable();

            // When the generation started.
            $table->timestamp('started_at')->nullable();

            // When the generation finished.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecls_generation_jobs');
    }
};
