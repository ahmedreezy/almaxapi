<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('football_tips', function (Blueprint $table) {
            if (! Schema::hasColumn('football_tips', 'caption')) {
                $table->text('caption')->default('')->after('image_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('football_tips', function (Blueprint $table) {
            $table->dropColumn('caption');
        });
    }
};
