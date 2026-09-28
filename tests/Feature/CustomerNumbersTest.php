<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\UserRole;
use App\Filament\Resources\Calls\Actions\RecordFollowUpAction;
use App\Filament\Resources\Calls\Pages\CreateCall;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Call;
use App\Models\Customer;
use App\Models\SalesAgent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Mobile and landline are both optional; a customer without a number shows «—». */
class CustomerNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'منشی', 'email' => 'numbers@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]));
    }

    private function create(array $data)
    {
        return Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'علی رضایی', 'type' => CustomerType::Individual->value, ...$data])
            ->call('create');
    }

    public function test_a_customer_can_be_saved_without_any_number(): void
    {
        $this->create(['phone' => '', 'landline' => ''])->assertHasNoFormErrors();
        $this->create(['name' => 'مریم حسینی', 'phone' => '', 'landline' => ''])->assertHasNoFormErrors();

        // two customers without a mobile do not clash on the one-customer-per-mobile rule
        $this->assertSame(2, Customer::whereNull('phone')->whereNull('landline')->count());
    }

    public function test_the_landline_is_kept_as_digits_and_checked(): void
    {
        $this->create(['landline' => '۰۲۱-۴۴۰۰ ۱۱۰۰'])->assertHasNoFormErrors();
        $this->assertSame('02144001100', Customer::sole()->landline);
        $this->assertNull(Customer::sole()->phone);

        $this->create(['name' => 'کوتاه', 'landline' => '12345'])->assertHasFormErrors(['landline']);
        $this->create(['name' => 'موبایل', 'landline' => '09121234567'])->assertHasFormErrors(['landline']);
        $this->create(['name' => 'هشت رقمی', 'landline' => '44001100'])->assertHasNoFormErrors();
    }

    public function test_a_typed_mobile_is_still_checked_and_still_one_per_customer(): void
    {
        $this->create(['phone' => '0912'])->assertHasFormErrors(['phone']);
        $this->create(['phone' => '09121234567', 'landline' => '02144001100'])->assertHasNoFormErrors();
        $this->create(['name' => 'دوباره', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷'])->assertHasFormErrors(['phone']);
    }

    public function test_a_new_customer_without_a_number_can_be_added_from_the_call_form(): void
    {
        Livewire::test(CreateCall::class)
            ->callFormComponentAction('customer_id', 'createOption', data: ['name' => 'بدون شماره', 'type' => CustomerType::Individual->value])
            ->assertHasNoFormComponentActionErrors();

        $this->assertNull(Customer::where('name', 'بدون شماره')->sole()->phone);
    }

    public function test_missing_numbers_show_a_dash_and_a_landline_is_found_by_search(): void
    {
        $none = Customer::create(['name' => 'بدون شماره']);
        $office = Customer::create(['name' => 'دفتر مرکزی', 'landline' => '02144001100']);
        $agent = SalesAgent::create(['name' => 'آقای رضایی']);
        $noneCall = Call::create(['customer_id' => $none->id, 'sales_agent_id' => $agent->id, 'request' => 'دستمال', 'follow_up_on' => today()]);
        $officeCall = Call::create(['customer_id' => $office->id, 'sales_agent_id' => $agent->id, 'request' => 'دستمال', 'follow_up_on' => today()]);

        Livewire::test(ListCustomers::class)
            ->assertTableColumnFormattedStateSet('landline', '۰۲۱۴۴۰۰۱۱۰۰', $office)
            ->assertSeeHtml('—');

        Livewire::test(ListCalls::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$noneCall, $officeCall])
            ->searchTable('۴۴۰۰۱۱۰۰')
            ->assertCanSeeTableRecords([$officeCall])
            ->assertCanNotSeeTableRecords([$noneCall]);

        // a search with no digits still only matches by name, not every row
        Livewire::test(ListCalls::class, ['activeTab' => 'all'])
            ->searchTable('دفتر')
            ->assertCanSeeTableRecords([$officeCall])
            ->assertCanNotSeeTableRecords([$noneCall]);

        // the follow-up popup opens for a customer with no number too
        $this->assertStringStartsWith('شماره: — ·', RecordFollowUpAction::make()->record($noneCall)->getModalDescription());
        $this->assertStringStartsWith('شماره: ۰۲۱۴۴۰۰۱۱۰۰ ·', RecordFollowUpAction::make()->record($officeCall)->getModalDescription());
    }
}
