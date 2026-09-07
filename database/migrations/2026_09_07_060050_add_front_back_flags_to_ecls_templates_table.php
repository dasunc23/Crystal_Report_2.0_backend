<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecls_templates', function (Blueprint $table) {

            // Indicates whether this template has a front design.
            $table->boolean('has_front')
                ->default(true)
                ->after('height_mm');

            // Indicates whether this template has a back design.
            $table->boolean('has_back')
                ->default(true)
                ->after('has_front');
        });
    }

    public function down(): void
    {
        Schema::table('ecls_templates', function (Blueprint $table) {
            // Remove the fields if this migration is rolled back.
            $table->dropColumn([
                'has_front',
                'has_back',
            ]);
        });
    }
};
