<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Calls\Pages\CreateCall;
use App\Filament\Resources\Calls\Pages\EditCall;
use App\Models\Call;
use App\Models\Customer;
use App\Models\SalesAgent;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeletedCustomerPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'manager@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));
    }

    private function customerPicker($page): Select
    {
        return collect($page->instance()->form->getFlatComponents())
            ->first(fn ($component) => $component instanceof Select && $component->getName() === 'customer_id');
    }

    public function test_a_deleted_customer_is_not_offered_on_a_new_call(): void
    {
        $kept = Customer::create(['name' => 'علی محمدی', 'phone' => '09121234567']);
        $deleted = Customer::create(['name' => 'علی رضایی', 'phone' => '09121234568']);
        $deleted->delete();

        $picker = $this->customerPicker(Livewire::test(CreateCall::class));

        // neither in the list that opens with the field, nor when searching by name or number
        $this->assertArrayHasKey($kept->id, $picker->getOptions());
        $this->assertArrayNotHasKey($deleted->id, $picker->getOptions());
        $this->assertArrayNotHasKey($deleted->id, $picker->getSearchResults('علی'));
        $this->assertArrayNotHasKey($deleted->id, $picker->getSearchResults('09121234568'));
        $this->assertArrayHasKey($kept->id, $picker->getSearchResults('علی'));
    }

    public function test_a_call_shows_its_customer_in_the_picker(): void
    {
        $customer = Customer::create(['name' => 'علی رضایی', 'phone' => '09121234568']);
        $call = Call::create([
            'customer_id' => $customer->id,
            'sales_agent_id' => SalesAgent::create(['name' => 'آقای رضایی'])->id,
            'request' => 'دستمال کاغذی',
            'follow_up_on' => today(),
        ]);

        $picker = $this->customerPicker(Livewire::test(EditCall::class, ['record' => $call->id]));

        $this->assertSame('علی رضایی - 09121234568', $picker->getOptionLabel());
    }
}
