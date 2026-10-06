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
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * A deactivated account can no longer sign in; its past records keep its name. A super
     * admin always gets in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active || $this->is_super_admin;
    }

    /** A super admin is a manager whatever their role says. */
    public function isManager(): bool
    {
        return $this->role === UserRole::Manager || $this->is_super_admin;
    }

    /**
     * Whether $manager may change this account. A super admin's account is theirs alone (set and
     * taken away only by php artisan tamin:super-admin on the server).
     */
    public function isManageableBy(?User $manager): bool
    {
        if (! $manager?->isManager()) {
            return false;
        }

        return ! $this->is_super_admin || $manager->is($this);
    }
}
