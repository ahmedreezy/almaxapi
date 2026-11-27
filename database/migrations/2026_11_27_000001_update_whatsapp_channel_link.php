<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_LINK = 'https://whatsapp.com/channel/0029VbDlmLz9Bb5xVZ7OJz0O';

    private const OLD_LINK = 'https://whatsapp.com/channel/0029Vb77Bmb0wajqBRujZY30';

    public function up(): void
    {
        DB::table('vip_config')->upsert(
            [['key' => 'whatsapp_link', 'value' => self::NEW_LINK]],
            ['key'],
            ['value']
        );
    }

    public function down(): void
    {
        DB::table('vip_config')
            ->where('key', 'whatsapp_link')
            ->where('value', self::NEW_LINK)
            ->update(['value' => self::OLD_LINK]);
    }
};
