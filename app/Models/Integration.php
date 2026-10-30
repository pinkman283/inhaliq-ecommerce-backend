<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'name',
        'category',
        'description',
        'icon',
        'is_enabled',
        'is_test_mode',
        'credentials',
        'settings',
        'last_tested_at',
        'test_status',
        'test_message',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_test_mode' => 'boolean',
        'settings' => 'array',
        'last_tested_at' => 'datetime',
    ];

    /**
     * Get decrypted credentials transparently with fallback for unencrypted legacy rows
     */
    public function getCredentialsAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        // Try decrypting with Laravel Crypt
        try {
            $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($value);
            $decoded = json_decode($decrypted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $e) {
            // Not encrypted or decryption failed, fallback to raw JSON decode
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set encrypted credentials
     */
    public function setCredentialsAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['credentials'] = null;
            return;
        }

        $json = is_array($value) ? json_encode($value) : (string) $value;
        try {
            $this->attributes['credentials'] = \Illuminate\Support\Facades\Crypt::encryptString($json);
        } catch (\Throwable $e) {
            $this->attributes['credentials'] = $json;
        }
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Return credentials with sensitive strings masked
     */
    public function getMaskedCredentials(): array
    {
        if (empty($this->credentials) || !is_array($this->credentials)) {
            return [];
        }

        $masked = [];
        foreach ($this->credentials as $key => $val) {
            if (!is_string($val) || empty($val)) {
                $masked[$key] = $val;
                continue;
            }

            $sensitiveKeys = ['secret', 'key', 'password', 'token', 'auth', 'hash', 'private'];
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && strlen($val) > 8) {
                $masked[$key] = substr($val, 0, 4) . str_repeat('•', 8) . substr($val, -4);
            } elseif ($isSensitive) {
                $masked[$key] = str_repeat('•', 8);
            } else {
                $masked[$key] = $val;
            }
        }

        return $masked;
    }

    protected static function booted(): void
    {
        static::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('headless_cors_allowed_origins');
        });

        static::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('headless_cors_allowed_origins');
        });
    }
}
