<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'requested_via',
        'approval_requested_at',
        'approved_at',
        'approved_by',
        'google_id',
        'google_avatar',
        'onboarding_guide_answers',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dashboard_layout' => 'array',
            'ui_preferences' => 'array',
            'is_active' => 'boolean',
            'approval_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'onboarding_guide_answers' => 'array',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function isAdmin(): bool
    {
        return ($this->role ?? 'admin') === 'admin';
    }

    public function isManager(): bool
    {
        return ($this->role ?? '') === 'manager';
    }

    public function isPouring(): bool
    {
        return ($this->role ?? '') === 'pouring';
    }

    public function isMarketingManager(): bool
    {
        return ($this->role ?? '') === 'marketing_manager';
    }

    public function canAccessMarketing(): bool
    {
        return $this->isAdmin() || $this->isMarketingManager();
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['role', 'membership_active'])
            ->withTimestamps();
    }

    /**
     * The tenant ids this user is a member of — the set of tenants they may act
     * within. Used to fail-safely scope tenant-owned queries (a member of tenant N
     * can only touch tenant N's rows), which closes cross-tenant IDOR at query
     * sites that run outside the tenant.access middleware (e.g. Livewire
     * components, where the request tenant attribute is not reliably present).
     *
     * @return array<int, int>
     */
    public function accessibleTenantIds(): array
    {
        return $this->tenants()
            ->pluck('tenants.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
