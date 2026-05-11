<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key-value configuration store. No primary key auto-increment — 'key' IS the PK.
 *
 * Usage: VipConfig::getValue('daily_price')
 *        VipConfig::setValue('daily_price', '10000')
 */
class VipConfig extends Model
{
    protected $table = 'vip_config';
    public $timestamps = false;
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    // ─── Static helpers ──────────────────────────────────────────────────

    public static function getValue(string $key, string $default = ''): string
    {
        $row = static::find($key);
        return $row ? (string) $row->value : $default;
    }

    public static function setValue(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Return all config as a plain key => value array.
     */
    public static function allAsMap(): array
    {
        return static::all()->pluck('value', 'key')->toArray();
    }
}
