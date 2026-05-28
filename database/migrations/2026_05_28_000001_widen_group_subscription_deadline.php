<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE groups MODIFY subscription_deadline VARCHAR(16) NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE groups ALTER COLUMN subscription_deadline TYPE VARCHAR(16) USING subscription_deadline::varchar');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE groups MODIFY subscription_deadline TIME NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE groups ALTER COLUMN subscription_deadline TYPE TIME USING subscription_deadline::time');
        }
    }
};
