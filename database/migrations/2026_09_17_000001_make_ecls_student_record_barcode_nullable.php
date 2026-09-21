<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Missing barcodes must be stored until the explicit generation step.
        Schema::table('ecls_student_records', function (Blueprint $table) {
            $table->string('barcode')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ecls_student_records', function (Blueprint $table) {
            $table->string('barcode')->nullable(false)->change();
        });
    }
};
