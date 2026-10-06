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
 * A super admin is always a manager and always gets in, and no other manager can change their
 * account. Only the server command makes or unmakes one.
 */
class SuperAdminTest extends TestCase
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

    private function superAdmin(array $attributes = []): User
    {
        $user = $this->user(UserRole::Manager, $attributes);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user;
    }

    public function test_a_super_admin_is_a_manager_and_gets_in_whatever_their_role_and_switch_say(): void
    {
        $boss = $this->superAdmin();
        // what another manager might have done to them before this change
        $boss->forceFill(['role' => UserRole::Secretary, 'is_active' => false])->save();

        $this->assertTrue($boss->fresh()->isManager());
        $this->actingAs($boss)->get('/')->assertOk();
        $this->actingAs($boss)->get('/users')->assertOk();

        // an ordinary secretary switched off is kept out
        $this->actingAs($this->user(UserRole::Secretary, ['is_active' => false]))->get('/')->assertForbidden();
    }

    public function test_other_managers_cannot_change_a_super_admin(): void
    {
        $boss = $this->superAdmin();
        $plain = $this->user(UserRole::Secretary);
        $this->actingAs($this->user(UserRole::Manager));

        // the row looks like any other, but its edit button only says it cannot be done
        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$boss, $plain])
            ->assertTableActionHidden('edit', $boss)
            ->assertTableActionVisible('editLocked', $boss)
            ->assertTableActionVisible('edit', $plain)
            ->assertTableActionHidden('editLocked', $plain)
            ->callTableAction('editLocked', $boss)
            ->assertNotified('این کاربر را نمی توان ویرایش کرد.');

        $this->assertTrue($boss->fresh()->is_super_admin);
        $this->assertSame(UserRole::Manager, $boss->fresh()->role);
    }

    public function test_a_super_admin_edits_their_own_name(): void
    {
        $boss = $this->superAdmin();
        $this->actingAs($boss);

        Livewire::test(ManageUsers::class)
            ->assertTableActionVisible('edit', $boss)
            ->callTableAction('edit', $boss, data: ['name' => 'امین هادی نژاد'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('امین هادی نژاد', $boss->fresh()->name);
        $this->assertTrue($boss->fresh()->is_super_admin);
    }

    public function test_the_mark_cannot_be_set_by_mass_assignment_or_from_the_panel(): void
    {
        $sneaky = $this->user(UserRole::Manager, ['is_super_admin' => true]);
        $this->assertFalse((bool) $sneaky->fresh()->is_super_admin);

        $other = $this->user(UserRole::Secretary);
        $this->actingAs($this->user(UserRole::Manager));

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $other, data: ['name' => 'y', 'is_super_admin' => true]);

        $this->assertFalse((bool) $other->fresh()->is_super_admin);
    }

    public function test_the_server_command_makes_and_unmakes_a_super_admin(): void
    {
        $user = $this->user(UserRole::Manager, ['email' => 'amin@test.local']);

        $this->artisan('tamin:super-admin', ['email' => 'amin@test.local'])
            ->expectsConfirmation($user->name.' (amin@test.local) مدیر کل شود؟', 'yes')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->is_super_admin);

        $this->artisan('tamin:super-admin', ['email' => 'amin@test.local', '--remove' => true])
            ->expectsConfirmation('مدیر کل بودن '.$user->name.' (amin@test.local) برداشته شود؟', 'yes')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->is_super_admin);

        $this->artisan('tamin:super-admin', ['email' => 'nobody@test.local'])->assertFailed();
    }
}
