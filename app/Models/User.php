<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'customer_id',
        'name',
        'email',
        'password',
        'role',
        'customer_type',
        'permissions',
        'status',
        'risk_level',
        'risk_score',
        'risk_reasons',
        'internal_notes',
        'suspended_until',
        'suspension_reason',
        'phone',
        'avatar',
        'admin_theme',
        'failed_login_attempts',
        'locked_until',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->role === 'customer' && empty($user->customer_id)) {
                $user->customer_id = self::generateCustomerId($user->customer_type);
            }
        });
    }

    public static function generateCustomerId(?string $customerType = 'registered'): string
    {
        $prefix = ($customerType === 'guest') ? 'GUEST-' : 'CUST-';

        $latest = self::where('customer_id', 'like', "{$prefix}%")
            ->orderByRaw('LENGTH(customer_id) DESC')
            ->orderBy('customer_id', 'desc')
            ->value('customer_id');

        if ($latest && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $latest, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        } else {
            $nextNumber = 10001;
        }

        $candidate = sprintf('%s%05d', $prefix, $nextNumber);

        while (self::where('customer_id', $candidate)->exists()) {
            $nextNumber++;
            $candidate = sprintf('%s%05d', $prefix, $nextNumber);
        }

        return $candidate;
    }

    protected $hidden = [
        'password',
        'remember_token',
        'risk_score',
        'risk_level',
        'risk_reasons',
        'internal_notes',
        'failed_login_attempts',
        'locked_until',
        'suspended_until',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_until' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'risk_reasons' => 'array',
            'risk_score' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isStaffOrAdmin(): bool
    {
        return in_array($this->role, ['staff', 'admin', 'super_admin']);
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isGuest(): bool
    {
        return $this->customer_type === 'guest';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'review';
    }

    public function isSuspended(): bool
    {
        if ($this->status !== 'suspended') {
            return false;
        }

        // If timed suspension duration has expired, auto-reactivate the account
        if ($this->suspended_until && now()->greaterThan($this->suspended_until)) {
            $this->update([
                'status' => 'active',
                'suspended_until' => null,
                'suspension_reason' => null,
            ]);
            return false;
        }

        return true;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return is_array($this->permissions) && in_array($permission, $this->permissions);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function customerIpLogs(): HasMany
    {
        return $this->hasMany(CustomerIpLog::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function storeCreditAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreCreditAccount::class);
    }

    public function storeCreditTransactions(): HasMany
    {
        return $this->hasMany(StoreCreditTransaction::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CustomerCartItem::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(CustomerWishlistItem::class);
    }

    public function adminNotifications(): HasMany
    {
        return $this->hasMany(AdminNotification::class);
    }
}

