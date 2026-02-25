<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
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
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === 'admin';
    }

    public function isCompanyUser(): bool
    {
        return $this->is_active && $this->role === 'company' && ! empty($this->company_id);
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
