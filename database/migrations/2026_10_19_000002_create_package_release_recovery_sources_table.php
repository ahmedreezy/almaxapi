<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_release_recovery_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recovery_release_id')->constrained('package_releases')->cascadeOnDelete();
            $table->foreignId('source_release_id')->constrained('package_releases')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['recovery_release_id', 'source_release_id'], 'recovery_source_pair_unique');
            $table->unique('source_release_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_release_recovery_sources');
    }
};
