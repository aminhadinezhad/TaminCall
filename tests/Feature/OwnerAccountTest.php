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
 * The owner's account (User::OWNER_EMAIL): anyone else may open it, but saving ends in an error
 * and changes nothing.
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

    public function test_another_manager_edits_the_owner_freely_but_saving_changes_nothing(): void
    {
        $owner = $this->user(UserRole::Manager, ['email' => User::OWNER_EMAIL]);
        $password = $owner->password;
        $this->actingAs($this->user(UserRole::Manager));

        Livewire::test(ManageUsers::class)
            ->mountTableAction('edit', $owner)
            ->setTableActionData(['name' => 'نام تازه', 'email' => 'other@test.local', 'password' => 'new-password-1', 'role' => UserRole::Secretary->value, 'is_active' => false])
            ->callMountedTableAction()
            ->assertNotified('امکان ذخیره تغییرات این کاربر وجود ندارد.');

        $owner->refresh();
        $this->assertSame([User::OWNER_EMAIL, UserRole::Manager, true, $password], [$owner->email, $owner->role, $owner->is_active, $owner->password]);
        $this->assertNotSame('نام تازه', $owner->name);
    }

    public function test_the_owner_changes_their_own_account(): void
    {
        $owner = $this->user(UserRole::Manager, ['email' => User::OWNER_EMAIL]);
        $this->actingAs($owner);

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $owner, data: ['name' => 'امین'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('امین', $owner->fresh()->name);
    }

    public function test_every_other_account_works_as_before(): void
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
