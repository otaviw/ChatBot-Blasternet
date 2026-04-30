<?php

declare(strict_types=1);


namespace App\Models;

use App\Casts\UserPermissionsCast;
use App\Support\Enums\UserRole;
use App\Support\UserPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    public const ROLE_SYSTEM_ADMIN  = UserRole::SYSTEM_ADMIN->value;
    public const ROLE_RESELLER_ADMIN = UserRole::RESELLER_ADMIN->value;
    public const ROLE_COMPANY_ADMIN = UserRole::COMPANY_ADMIN->value;
    public const ROLE_AGENT         = UserRole::AGENT->value;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

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
        'company_id',
        'reseller_id',
        'is_active',
        'can_use_ai',
        'disabled_at',
        'permissions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'can_use_ai' => true,
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
            'is_active' => 'boolean',
            'can_use_ai' => 'boolean',
            'disabled_at' => 'datetime',
            'permissions' => UserPermissionsCast::class,
        ];
    }

    /**
     * Returns the effective permission list for this user.
     * Company/system admins always receive the full set; agents use their stored list
     * or the default set when nothing is explicitly configured.
     *
     * @return list<string>
     */
    public function resolvedPermissions(): array
    {
        return UserPermissions::resolve(
            self::normalizeRole($this->role),
            $this->permissions
        );
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->resolvedPermissions(), true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class, 'opened_by_user_id')->latest('id');
    }

    public function aiMessages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->latest('id');
    }

    /**
     * @return array<int, string>
     */
    public static function companyRoleValues(): array
    {
        return [
            self::ROLE_COMPANY_ADMIN,
            self::ROLE_AGENT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function assignableRoleValuesForSystemAdmin(): array
    {
        return [
            self::ROLE_SYSTEM_ADMIN,
            self::ROLE_RESELLER_ADMIN,
            self::ROLE_COMPANY_ADMIN,
            self::ROLE_AGENT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function assignableRoleValuesForCompanyAdmin(): array
    {
        return [
            self::ROLE_COMPANY_ADMIN,
            self::ROLE_AGENT,
        ];
    }

    public static function normalizeRole(?string $role): string
    {
        return UserRole::normalize((string) $role);
    }

    public function setRoleAttribute(?string $value): void
    {
        $this->attributes['role'] = self::normalizeRole($value);
    }

    public function isSystemAdmin(): bool
    {
        return (bool) $this->is_active
            && self::normalizeRole($this->role) === UserRole::SYSTEM_ADMIN->value;
    }

    public function isCompanyAdmin(): bool
    {
        return (bool) $this->is_active
            && ! empty($this->company_id)
            && self::normalizeRole($this->role) === UserRole::COMPANY_ADMIN->value;
    }

    public function isResellerAdmin(): bool
    {
        return (bool) $this->is_active
            && self::normalizeRole($this->role) === UserRole::RESELLER_ADMIN->value;
    }

    public function isAgent(): bool
    {
        return (bool) $this->is_active
            && ! empty($this->company_id)
            && self::normalizeRole($this->role) === UserRole::AGENT->value;
    }

    public function isAdmin(): bool
    {
        return $this->isSystemAdmin() || $this->isResellerAdmin();
    }

    public function isCompanyUser(): bool
    {
        $normalizedRole = self::normalizeRole($this->role);

        return (bool) $this->is_active
            && ! empty($this->company_id)
            && in_array($normalizedRole, [UserRole::COMPANY_ADMIN->value, UserRole::AGENT->value], true);
    }

    public function canManageCompanyUsers(): bool
    {
        return $this->isCompanyAdmin();
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'area_user')->orderBy('name');
    }

    public function hasArea(string|int $area): bool
    {
        if (is_int($area) || ctype_digit((string) $area)) {
            $targetId = (int) $area;
            if ($targetId <= 0) {
                return false;
            }

            if ($this->relationLoaded('areas')) {
                return $this->areas->contains(fn(Area $item) => (int) $item->id === $targetId);
            }

            return $this->areas()->where('areas.id', $targetId)->exists();
        }

        $targetName = mb_strtolower(trim((string) $area));
        if ($targetName === '') {
            return false;
        }

        if ($this->relationLoaded('areas')) {
            return $this->areas->contains(
                fn(Area $item) => mb_strtolower(trim((string) $item->name)) === $targetName
            );
        }

        return $this->areas()
            ->whereRaw('LOWER(areas.name) = ?', [$targetName])
            ->exists();
    }

    public function appointmentStaffProfile(): HasOne
    {
        return $this->hasOne(AppointmentStaffProfile::class, 'user_id');
    }

    public function createdAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'created_by_user_id')->latest('id');
    }
}
