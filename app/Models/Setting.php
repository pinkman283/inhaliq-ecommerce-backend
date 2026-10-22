<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
        'description',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($setting->value) ? (float) $setting->value : $default,
            'json' => json_decode($setting->value, true) ?? $default,
            default => $setting->value,
        };
    }

    public static function isVatEnabled(): bool
    {
        $setting = self::where('key', 'vat_enabled')->first();
        if (!$setting) {
            $taxSetting = self::where('key', 'tax_enabled')->first();
            if ($taxSetting) {
                return filter_var($taxSetting->value, FILTER_VALIDATE_BOOLEAN);
            }
            return false;
        }
        return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isOtpShieldEnabled(): bool
    {
        $setting = self::where('key', 'otp_shield_enabled')->first();
        if (!$setting) {
            return true;
        }
        return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getVatRate(): float
    {
        if (!self::isVatEnabled()) {
            return 0.0;
        }
        $val = self::get('vat_rate', self::get('tax_rate', 8.0));
        return is_numeric($val) ? (float) $val : 8.0;
    }

    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string', ?string $label = null): self
    {
        $stringValue = is_array($value) ? json_encode($value) : (string) $value;
        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $stringValue,
                'group' => $group,
                'type' => $type,
                'label' => $label ?? ucwords(str_replace('_', ' ', $key)),
            ]
        );
    }
}
