<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The owner's account (User::OWNER_EMAIL): no other manager can take its manager role away or
 * switch it off. Everything looks as it always did.
 */
class OwnerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private int $n = 0;

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'کاربر '.++$this->n,
            'email' => 'user'.$this->n.'@test.local',
            'password' => 'secret123',
            'role' => $role,
        ], $attributes));
    }

    public function test_another_manager_cannot_demote_switch_off_or_rename_the_owners_email(): void
    {
        $owner = $this->user(UserRole::Manager, ['email' => User::OWNER_EMAIL]);
        $this->actingAs($this->user(UserRole::Manager));

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $owner, data: [
                'name' => 'نام تازه',
                'email' => 'someone-else@test.local',
                'role' => UserRole::Secretary->value,
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $owner->refresh();
        $this->assertSame(UserRole::Manager, $owner->role);
        $this->assertTrue($owner->is_active);
        $this->assertSame(User::OWNER_EMAIL, $owner->email);
        // the rest of the account is edited as usual
        $this->assertSame('نام تازه', $owner->name);
    }

    public function test_the_owner_always_gets_in_as_a_manager(): void
    {
        $owner = $this->user(UserRole::Manager, ['email' => User::OWNER_EMAIL]);
        $owner->forceFill(['role' => UserRole::Secretary, 'is_active' => false])->saveQuietly();

        $this->assertTrue($owner->fresh()->isManager());
        $this->actingAs($owner->fresh())->get('/')->assertOk();
    }

    public function test_other_users_are_handled_as_before(): void
    {
        $other = $this->user(UserRole::Manager);
        $this->actingAs($this->user(UserRole::Manager));

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $other, data: ['role' => UserRole::Secretary->value, 'is_active' => false])
            ->assertHasNoTableActionErrors();

        $this->assertSame(UserRole::Secretary, $other->fresh()->role);
        $this->assertFalse($other->fresh()->is_active);
    }
}
