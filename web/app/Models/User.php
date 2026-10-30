<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** Associe le compte à sa fiche professionnelle. */
    public function professionalProfile(): HasOne
    {
        return $this->hasOne(ProfessionalProfile::class);
    }

    protected $attributes = ['role' => 'staff', 'status' => 'active'];

    /** Vérifie la permission uniquement pour un compte actif dont le rôle est connu. */
    public function hasPermission(string $permission): bool
    {
        return $this->status === 'active' && in_array($permission, Role::tryFrom($this->role)?->permissions() ?? [], true);
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Révoque tous les jetons mobiles dès que mot de passe, rôle ou statut change. */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged(['password', 'role', 'status'])) {
                $user->tokens()->delete();
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token',
        'invitation_expires_at',
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
            'invitation_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
