<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The edit form never shows a password: it opens empty, and the browser is told it is a new
 * password, so it does not fill in the one it saved for signing in.
 */
class PasswordFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_field_opens_empty_and_is_not_filled_by_the_browser(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::create(['name' => 'مدیر', 'email' => 'm@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]);
        $this->actingAs($user);

        $page = Livewire::test(ManageUsers::class)->mountTableAction('edit', $user);

        $this->assertNull($page->instance()->mountedActions[0]['data']['password']);
        $field = collect($page->instance()->getMountedAction()->getSchema(Schema::make($page->instance()))->getFlatFields(withHidden: true))->first(fn ($field) => $field->getName() === 'password');
        $this->assertSame('new-password', $field->getAutocomplete());
    }
}
