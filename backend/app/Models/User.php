<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const ROLE_SYSTEM_ADMIN = 'system_admin';
    public const ROLE_COMPANY_ADMIN = 'company_admin';
    public const ROLE_AGENT = 'agent';
    public const ROLE_LEGACY_ADMIN = 'admin';
    public const ROLE_LEGACY_COMPANY = 'company';

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
        'is_active',
        'disabled_at',
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
            'disabled_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    public function aiConversations()
    {
        return $this->hasMany(AiConversation::class, 'opened_by_user_id')->latest('id');
    }

    public function aiMessages()
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
            self::ROLE_LEGACY_COMPANY,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function assignableRoleValuesForSystemAdmin(): array
    {
        return [
            self::ROLE_SYSTEM_ADMIN,
            self::ROLE_COMPANY_ADMIN,
            self::ROLE_AGENT,
            self::ROLE_LEGACY_ADMIN,
            self::ROLE_LEGACY_COMPANY,
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
            self::ROLE_LEGACY_COMPANY,
        ];
    }

    public static function normalizeRole(?string $role): string
    {
        $value = mb_strtolower(trim((string) $role));
        if ($value === self::ROLE_LEGACY_ADMIN) {
            return self::ROLE_SYSTEM_ADMIN;
        }
        if ($value === self::ROLE_LEGACY_COMPANY) {
            return self::ROLE_COMPANY_ADMIN;
        }

        return $value;
    }

    public function isSystemAdmin(): bool
    {
        $normalizedRole = self::normalizeRole($this->role);

        return (bool) $this->is_active
            && $normalizedRole === self::ROLE_SYSTEM_ADMIN;
    }

    public function isCompanyAdmin(): bool
    {
        $normalizedRole = self::normalizeRole($this->role);

        return (bool) $this->is_active
            && ! empty($this->company_id)
            && $normalizedRole === self::ROLE_COMPANY_ADMIN;
    }

    public function isAgent(): bool
    {
        $normalizedRole = self::normalizeRole($this->role);

        return (bool) $this->is_active
            && ! empty($this->company_id)
            && $normalizedRole === self::ROLE_AGENT;
    }

    public function isAdmin(): bool
    {
        return $this->isSystemAdmin();
    }

    public function isCompanyUser(): bool
    {
        $normalizedRole = self::normalizeRole($this->role);

        return (bool) $this->is_active
            && ! empty($this->company_id)
            && in_array($normalizedRole, [self::ROLE_COMPANY_ADMIN, self::ROLE_AGENT], true);
    }

    public function canManageCompanyUsers(): bool
    {
        return $this->isCompanyAdmin();
    }

    public function areas()
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
}
