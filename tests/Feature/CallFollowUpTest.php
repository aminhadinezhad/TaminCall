<?php

namespace Tests\Feature;

use App\Enums\AcquisitionSource;
use App\Enums\CallStatus;
use App\Enums\CustomerType;
use App\Enums\NoPurchaseReason;
use App\Enums\UserRole;
use App\Filament\Resources\Calls\CallResource;
use App\Filament\Resources\Calls\Pages\CreateCall;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\SalesAgents\SalesAgentResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\AcquisitionSourceChart;
use App\Filament\Widgets\ReportOverview;
use App\Models\Call;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\SalesAgent;
use App\Models\User;
use App\Support\Persian;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CallFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(array $overrides = []): Call
    {
        static $n = 0;
        $customer = Customer::create(['name' => 'علی محمدی', 'phone' => '0912123456'.($n++ % 10)]);
        $agent = SalesAgent::create(['name' => 'آقای رضایی']);

        return Call::create(array_merge([
            'customer_id' => $customer->id,
            'sales_agent_id' => $agent->id,
            'request' => 'دستمال کاغذی',
            'follow_up_on' => today(),
        ], $overrides));
    }

    private function secretary(): User
    {
        return User::create(['name' => 'خانم مرادی', 'email' => 'moradi@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]);
    }

    public function test_the_next_working_day_skips_friday(): void
    {
        $thursday = Carbon::parse('2026-09-24'); // a Thursday

        $this->assertTrue($thursday->isThursday());
        $this->assertSame('2026-09-26', Call::workingDayAfter(1, $thursday)->toDateString()); // Saturday
        $this->assertSame('2026-09-23', Call::workingDayAfter(1, Carbon::parse('2026-09-22'))->toDateString());
    }

    public function test_dates_are_written_without_half_spaces(): void
    {
        // the Jalali library spells Tuesday and Thursday with a half-space
        foreach (range(0, 6) as $day) {
            $date = Carbon::parse('2026-09-19')->addDays($day);

            $this->assertStringNotContainsString("\u{200C}", Persian::dayName($date), $date->toDateString());
        }

        $this->assertStringStartsWith('سه شنبه', Persian::dayName(Carbon::parse('2026-09-22')));
        $this->assertStringStartsWith('پنج شنبه', Persian::dayName(Carbon::parse('2026-09-24')));
    }

    public function test_phone_numbers_are_normalised(): void
    {
        foreach (['۰۹۱۲ ۱۲۳ ۴۵۶۷', '+989121234567', '00989121234567', '9121234567', '0912-123-4567'] as $typed) {
            $this->assertSame('09121234567', Customer::normalizePhone($typed), $typed);
        }
    }

    public function test_an_answered_follow_up_records_the_result_and_closes_the_call(): void
    {
        $call = $this->makeCall();

        $call->recordFollowUp([
            'answered' => true,
            'purchased' => false,
            'no_purchase_reason' => NoPurchaseReason::Price->value,
            'agent_satisfaction' => 4,
            'overall_satisfaction' => 3,
            'notes' => 'قیمت رقیب ارزان تر بود',
        ], $this->secretary());

        $call->refresh();
        $this->assertSame(CallStatus::Done, $call->status);
        $this->assertFalse($call->result->purchased);
        $this->assertSame(NoPurchaseReason::Price, $call->result->no_purchase_reason);
        $this->assertSame(4, $call->result->agent_satisfaction);
    }

    public function test_a_purchase_drops_any_no_purchase_reason(): void
    {
        $call = $this->makeCall();

        $call->recordFollowUp(['answered' => true, 'purchased' => true, 'no_purchase_reason' => 'price', 'agent_satisfaction' => 5, 'overall_satisfaction' => 5]);

        $this->assertNull($call->fresh()->result->no_purchase_reason);
    }

    public function test_calling_again_keeps_the_call_in_the_queue(): void
    {
        $call = $this->makeCall();

        $call->recordFollowUp(['answered' => true, 'purchased' => false, 'no_purchase_reason' => 'undecided', 'agent_satisfaction' => 4, 'overall_satisfaction' => 4], null, 3);

        $call->refresh();
        $this->assertSame(CallStatus::AwaitingFollowUp, $call->status);
        $this->assertTrue($call->follow_up_on->gte(today()->addDays(3)));
    }

    public function test_unanswered_calls_are_retried_then_closed_after_three_attempts(): void
    {
        $call = $this->makeCall();

        $call->recordFollowUp(['answered' => false]);
        $call->refresh();
        $this->assertSame(CallStatus::AwaitingFollowUp, $call->status);
        $this->assertTrue($call->follow_up_on->gt(today()));
        $this->assertNull($call->result);

        $call->recordFollowUp(['answered' => false]);
        $call->recordFollowUp(['answered' => false]);
        $call->refresh();
        $this->assertSame(CallStatus::Unreachable, $call->status);
        $this->assertSame(3, $call->followUps()->count());
    }

    public function test_due_calls_include_overdue_ones_only(): void
    {
        $this->makeCall(['follow_up_on' => today()->subDays(2)]);
        $done = $this->makeCall(['follow_up_on' => today()]);
        $done->update(['status' => CallStatus::Done]);

        $this->assertSame(1, Call::query()->dueBy(today())->count());
    }

    public function test_the_secretary_records_a_result_from_the_calls_table(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());
        $call = $this->makeCall();

        Livewire::test(ListCalls::class, ['activeTab' => 'today'])
            ->callTableAction('recordFollowUp', $call, [
                'answered' => true,
                'purchased' => true,
                'agent_satisfaction' => 5,
                'overall_satisfaction' => 5,
                'call_again_in' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $call->refresh();
        $this->assertSame(CallStatus::Done, $call->status);
        $this->assertTrue($call->result->purchased);
        $this->assertSame(auth()->id(), $call->result->user_id);
    }

    public function test_the_star_ratings_are_required_and_only_take_one_to_five(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());
        $call = $this->makeCall();

        Livewire::test(ListCalls::class, ['activeTab' => 'today'])
            ->callTableAction('recordFollowUp', $call, ['answered' => true, 'purchased' => true, 'overall_satisfaction' => 7])
            ->assertHasTableActionErrors(['agent_satisfaction' => 'required', 'overall_satisfaction' => 'between']);

        $this->assertSame(CallStatus::AwaitingFollowUp, $call->fresh()->status);
    }

    public function test_the_new_call_form_saves_source_and_notes_and_requires_the_source(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());
        $customer = Customer::create(['name' => 'زهرا موسوی', 'phone' => '09125550000', 'type' => CustomerType::Individual]);
        $agent = SalesAgent::create(['name' => 'خانم کریمی']);

        Livewire::test(CreateCall::class)
            ->fillForm(['customer_id' => $customer->id, 'sales_agent_id' => $agent->id, 'request' => 'کاغذ A4', 'follow_up_in' => 1])
            ->call('create')
            ->assertHasFormErrors(['source' => 'required']);

        Livewire::test(CreateCall::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'sales_agent_id' => $agent->id,
                'request' => 'کاغذ A4',
                'source' => AcquisitionSource::Referral->value,
                'notes' => 'از طرف آقای رحیمی',
                'follow_up_in' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $call = Call::sole();
        $this->assertSame(AcquisitionSource::Referral, $call->source);
        $this->assertSame('از طرف آقای رحیمی', $call->notes);
    }

    public function test_only_a_legal_customer_has_a_company(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());

        // a person: the field is not shown, and a company sent anyway is not kept
        Livewire::test(CreateCustomer::class)
            ->fillForm(['type' => CustomerType::Individual->value])
            ->assertFormFieldIsHidden('company')
            ->fillForm(['type' => CustomerType::Legal->value])
            ->assertFormFieldIsVisible('company');

        $person = Customer::create(['name' => 'سارا نیکو', 'phone' => '09121110000', 'type' => CustomerType::Individual, 'company' => 'شرکت الف']);
        $this->assertNull($person->fresh()->company);

        // a company turned into a person loses its company name
        $firm = Customer::create(['name' => 'علی راد', 'phone' => '09122220000', 'type' => CustomerType::Legal, 'company' => 'شرکت ب']);
        $this->assertSame('شرکت ب', $firm->fresh()->company);
        $firm->update(['type' => CustomerType::Individual]);
        $this->assertNull($firm->fresh()->company);
    }

    public function test_a_new_customer_can_be_added_from_inside_the_call_form(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());
        $agent = SalesAgent::create(['name' => 'خانم کریمی']);

        $page = Livewire::test(CreateCall::class)
            ->callFormComponentAction('customer_id', 'createOption', data: [
                'name' => 'پرویز نادری',
                'phone' => '۰۹۱۲ ۸۸۸ ۰۰۰۰',
                'type' => CustomerType::Individual->value,
            ])
            ->assertHasNoFormComponentActionErrors();

        $customer = Customer::where('phone', '09128880000')->sole();
        $page->assertFormSet(['customer_id' => $customer->id]);

        $page->fillForm(['sales_agent_id' => $agent->id, 'request' => 'دستمال کاغذی', 'source' => AcquisitionSource::Website->value, 'follow_up_in' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($customer->id, Call::sole()->customer_id);
        $this->assertSame(auth()->id(), Call::sole()->received_by);

        // the same number cannot be added twice
        Livewire::test(CreateCall::class)
            ->callFormComponentAction('customer_id', 'createOption', data: ['name' => 'دوباره', 'phone' => '09128880000', 'type' => CustomerType::Individual->value])
            ->assertHasFormComponentActionErrors(['phone']);
    }

    public function test_a_legal_customer_needs_a_company_name(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'علی رضایی', 'phone' => '09127770000', 'type' => CustomerType::Legal->value])
            ->call('create')
            ->assertHasFormErrors(['company' => 'required']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'علی رضایی', 'phone' => '۰۹۱۲۷۷۷۰۰۰۰', 'type' => CustomerType::Legal->value, 'company' => 'شرکت آریا'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CustomerType::Legal, Customer::where('phone', '09127770000')->sole()->type);
    }

    public function test_the_source_and_customer_type_charts_count_purchases_correctly(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'm@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));

        // three referrals: two bought; three from the website: one bought
        foreach ([['referral', true], ['referral', true], ['referral', false], ['website', true], ['website', false], ['website', false]] as [$source, $bought]) {
            $call = $this->makeCall(['source' => $source]);
            $call->recordFollowUp(['answered' => true, 'purchased' => $bought, 'no_purchase_reason' => $bought ? null : 'price', 'agent_satisfaction' => 4, 'overall_satisfaction' => 4]);
        }

        Livewire::test(AcquisitionSourceChart::class)
            ->assertSee('بیشترین نرخ خرید: معرف (۶۷٪ از مشتریان پیگیری شده)')
            ->assertSee('سایت · ۵۰٪', false);
    }

    public function test_deleting_a_customer_removes_them_their_calls_and_follow_ups_for_good(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'reports@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));

        $kept = $this->makeCall();
        $kept->recordFollowUp(['answered' => true, 'purchased' => true, 'agent_satisfaction' => 5, 'overall_satisfaction' => 5]);
        $gone = $this->makeCall();
        $gone->recordFollowUp(['answered' => true, 'purchased' => true, 'agent_satisfaction' => 5, 'overall_satisfaction' => 5]);

        Livewire::test(ReportOverview::class)->assertSee('۲ خرید از ۲ مشتری پیگیری شده', false);

        $gone->customer->delete();

        // nothing of theirs is left, in the lists, the database or the figures
        $this->assertNull(Customer::find($gone->customer_id));
        $this->assertNull(Call::find($gone->id));
        $this->assertSame(0, FollowUp::where('call_id', $gone->id)->count());
        $this->assertSame(1, Call::count());
        Livewire::test(ReportOverview::class)->assertSee('۱ خرید از ۱ مشتری پیگیری شده', false);
    }

    public function test_the_customers_list_has_no_bin_and_its_delete_button_removes_for_good(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'bin@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));

        $kept = Customer::create(['name' => 'مریم حسینی', 'phone' => '09120001111']);
        $gone = $this->makeCall()->customer;

        $list = Livewire::test(ListCustomers::class);
        $this->assertSame([], $list->instance()->getTabs(), 'no «حذف شده ها» tab');

        $list->assertCanSeeTableRecords([$kept, $gone])
            ->callTableAction('delete', $gone)
            ->assertCanNotSeeTableRecords([$gone]);

        $this->assertNull(Customer::find($gone->id));
        $this->assertSame(0, Call::where('customer_id', $gone->id)->count());
    }

    public function test_an_empty_calls_list_says_why_it_is_empty(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());

        Livewire::test(ListCalls::class, ['activeTab' => 'today'])->assertSee('امروز پیگیری ای نمانده');
        Livewire::test(ListCalls::class, ['activeTab' => 'all'])->assertSee('هنوز تماسی ثبت نشده')->assertSee('ثبت تماس جدید');

        $this->makeCall();
        Livewire::test(ListCalls::class, ['activeTab' => 'all'])->searchTable('کسی که نیست')->assertSee('نتیجه ای پیدا نشد');
    }

    public function test_saving_a_call_says_when_it_comes_back_for_follow_up(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());
        $customer = Customer::create(['name' => 'مینا صدری', 'phone' => '09126660000', 'type' => CustomerType::Individual]);
        $agent = SalesAgent::create(['name' => 'آقای نوری']);

        Livewire::test(CreateCall::class)
            ->fillForm(['customer_id' => $customer->id, 'sales_agent_id' => $agent->id, 'request' => 'لیوان کاغذی', 'source' => AcquisitionSource::Website->value, 'follow_up_in' => 1])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('تماس مینا صدری ثبت شد');
    }

    public function test_searching_the_calls_by_customer_name_narrows_the_list(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->secretary());

        $wanted = $this->makeCall();
        $wanted->customer->update(['name' => 'بهرام کریمی']);
        $other = $this->makeCall();
        $other->customer->update(['name' => 'سعید نوری']);

        Livewire::test(ListCalls::class, ['activeTab' => 'all'])
            ->searchTable('بهرام')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);

        // a number still finds its call, whatever way it is typed
        Livewire::test(ListCalls::class, ['activeTab' => 'all'])
            ->searchTable(Persian::digits($wanted->customer->phone))
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_manager_cannot_take_away_their_own_role(): void
    {
        Filament::setCurrentPanel('admin');
        $manager = User::create(['name' => 'مدیر', 'email' => 'boss@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]);
        $this->actingAs($manager);

        Livewire::test(ManageUsers::class)
            ->mountTableAction('edit', $manager)
            ->setTableActionData(['role' => UserRole::Secretary->value, 'is_active' => false])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $manager->refresh();
        $this->assertSame(UserRole::Manager, $manager->role, 'a manager must not be able to demote themselves');
        $this->assertTrue($manager->is_active);
    }

    public function test_deleting_is_refused_on_the_server_not_only_hidden(): void
    {
        $customer = $this->makeCall()->customer;

        $this->actingAs($this->secretary());
        $this->assertFalse(CustomerResource::canDelete($customer));
        $this->assertFalse(CallResource::canDelete($customer->calls()->first()));

        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'rules@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));
        $this->assertTrue(CustomerResource::canDelete($customer));
        $this->assertFalse(CallResource::canDelete($customer->calls()->first()));
        $this->assertFalse(UserResource::canDelete(auth()->user()));
        $this->assertFalse(SalesAgentResource::canDelete($customer->calls()->first()->salesAgent), 'an agent with calls stays');
    }

    public function test_the_manager_command_creates_the_first_sign_in_and_rejects_bad_input(): void
    {
        $this->artisan('tamin:manager')
            ->expectsQuestion('نام', 'مدیر فروش')
            ->expectsQuestion('ایمیل (برای ورود)', 'boss@taminfalat.test')
            ->expectsQuestion('رمز عبور (حداقل ۸ نویسه)', 'a-strong-pass')
            ->assertSuccessful();

        $manager = User::where('email', 'boss@taminfalat.test')->sole();
        $this->assertTrue($manager->isManager());
        $this->assertTrue($manager->is_active);
        $this->assertNotSame('a-strong-pass', $manager->password, 'the password is stored hashed');

        $this->artisan('tamin:manager')
            ->expectsQuestion('نام', 'دوباره')
            ->expectsQuestion('ایمیل (برای ورود)', 'boss@taminfalat.test')
            ->expectsQuestion('رمز عبور (حداقل ۸ نویسه)', 'short')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_seeding_the_server_creates_no_account(): void
    {
        $this->seed();

        $this->assertSame(0, User::count());
    }

    public function test_secretaries_cannot_open_manager_pages(): void
    {
        $this->actingAs($this->secretary());

        $this->get('/sales-agents')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/calls')->assertOk();
    }
}
