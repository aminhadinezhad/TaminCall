<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Same defaults as the table, so a user created in code is usable before it is reloaded. */
    protected $attributes = [
        'role' => 'secretary',
        'is_active' => true,
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
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** The owner's account: no other manager can take its manager role away or switch it off. */
    public const OWNER_EMAIL = 'mohammadaminhadinezhad@gmail.com';

    protected static function booted(): void
    {
        // anyone else saving the owner's account leaves it an active manager, and its email (which
        // is what marks the account as theirs) as it was
        static::saving(function (User $user) {
            if ($user->exists && $user->isOwner() && ! auth()->user()?->isOwner()) {
                $user->role = UserRole::Manager;
                $user->is_active = true;
                $user->email = $user->getOriginal('email');
            }
        });
    }

    public function isOwner(): bool
    {
        return strcasecmp(trim((string) ($this->getOriginal('email') ?? $this->email)), self::OWNER_EMAIL) === 0;
    }

    /**
     * A deactivated account can no longer sign in; its past records keep its name.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active || $this->isOwner();
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager || $this->isOwner();
    }
}
