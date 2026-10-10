<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\UserRole;
use App\Filament\Pages\ReportCalls;
use App\Filament\Resources\Calls\CallResource;
use App\Filament\Widgets\AcquisitionSourceChart;
use App\Filament\Widgets\AgentPerformanceTable;
use App\Filament\Widgets\CallsTrendChart;
use App\Filament\Widgets\CustomerTypeChart;
use App\Filament\Widgets\NoPurchaseReasonsChart;
use App\Filament\Widgets\ReportOverview;
use App\Filament\Widgets\SatisfactionChart;
use App\Filament\Widgets\TodayOverview;
use App\Models\Call;
use App\Models\Customer;
use App\Models\SalesAgent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every figure on the dashboard opens the calls behind it, and that list holds exactly the calls
 * the figure counts.
 */
class ReportDetailsTest extends TestCase
{
    use RefreshDatabase;

    private SalesAgent $ahmadi;

    private SalesAgent $karimi;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::create(['name' => 'مدیر', 'email' => 'm@test.local', 'password' => 'secret123', 'role' => UserRole::Manager]));

        $this->ahmadi = SalesAgent::create(['name' => 'آقای احمدی']);
        $this->karimi = SalesAgent::create(['name' => 'خانم کریمی']);
        $n = 0;
        $call = function (SalesAgent $agent, string $source, CustomerType $type, string $name) use (&$n): Call {
            $customer = Customer::create(['name' => $name, 'phone' => '0913000000'.$n++, 'type' => $type]);

            return Call::create(['customer_id' => $customer->id, 'sales_agent_id' => $agent->id, 'request' => 'درخواست '.$name, 'source' => $source, 'follow_up_on' => today()]);
        };
        $bought = ['answered' => true, 'purchased' => true, 'agent_satisfaction' => 5, 'overall_satisfaction' => 4];

        $call($this->ahmadi, 'website', CustomerType::Individual, 'خریدار سایت')->recordFollowUp($bought);
        $call($this->ahmadi, 'website', CustomerType::Individual, 'گران دید')->recordFollowUp(['answered' => true, 'purchased' => false, 'no_purchase_reason' => 'price', 'agent_satisfaction' => 2, 'overall_satisfaction' => 2]);
        $call($this->ahmadi, 'referral', CustomerType::Legal, 'منتظر پیگیری');
        $call($this->karimi, 'referral', CustomerType::Legal, 'خریدار معرفی')->recordFollowUp($bought);
        // received before the 30 days the reports cover
        $old = $call($this->karimi, 'website', CustomerType::Individual, 'تماس قدیمی');
        $old->recordFollowUp($bought);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
    }

    private function names(array $slice): array
    {
        return ReportCalls::query($slice)->with('customer')->get()->pluck('customer.name')->sort()->values()->all();
    }

    public function test_each_slice_holds_exactly_the_calls_it_counts(): void
    {
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'خریدار معرفی', 'گران دید', 'منتظر پیگیری'], $this->names([]));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'خریدار معرفی', 'گران دید'], $this->names(['show' => 'results']));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'خریدار معرفی'], $this->names(['show' => 'purchases']));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'گران دید', 'منتظر پیگیری'], $this->names(['agent' => $this->ahmadi->id]));
        $this->assertEqualsCanonicalizing(['خریدار سایت'], $this->names(['agent' => $this->ahmadi->id, 'show' => 'purchases']));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'گران دید'], $this->names(['source' => 'website']));
        $this->assertEqualsCanonicalizing(['خریدار معرفی', 'منتظر پیگیری'], $this->names(['type' => 'legal']));
        $this->assertEqualsCanonicalizing(['گران دید'], $this->names(['reason' => 'price']));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'خریدار معرفی'], $this->names(['sat' => 'overall_satisfaction', 'level' => 4]));
        $this->assertEqualsCanonicalizing(['گران دید'], $this->names(['sat' => 'agent_satisfaction', 'level' => 2]));
        // a year back takes in the old call too
        $this->assertEqualsCanonicalizing(['تماس قدیمی', 'خریدار سایت', 'خریدار معرفی'], $this->names(['period' => 365, 'show' => 'purchases']));
    }

    public function test_the_page_lists_the_calls_for_a_manager_only(): void
    {
        $this->get(ReportCalls::link(['show' => 'purchases']))
            ->assertOk()
            ->assertSee('خریدها · ۳۰ روز اخیر')
            ->assertSee('خریدار سایت')
            ->assertSee('خریدار معرفی')
            ->assertDontSee('گران دید');

        // a secretary is turned away, and sees none of it
        $this->actingAs(User::create(['name' => 'منشی', 'email' => 's@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]));
        $response = $this->get(ReportCalls::link([]));
        $this->assertNotSame(200, $response->status());
        $this->assertFalse(ReportCalls::canAccess());
    }

    public function test_todays_calls_tile_opens_todays_calls_for_a_manager_and_the_calls_list_for_a_secretary(): void
    {
        $today = ['from' => today()->toDateString(), 'to' => today()->addDay()->toDateString()];

        // a manager: the calls received today, the very list today's point on the trend chart opens
        Livewire::test(TodayOverview::class)
            ->assertSee(e(ReportCalls::link($today)), false)
            ->assertSee('تماس های امروز');
        // the tile's number is that list's: four today, not the one from forty days ago
        $this->assertSame(4, ReportCalls::query($today)->count());
        $this->assertSame(4, Call::query()->whereDate('created_at', today())->count());
        $this->get(ReportCalls::link($today))->assertOk()->assertSee('۴ تماس')->assertDontSee('تماس قدیمی');

        // a secretary can not open the report page: the tile keeps its calls list, as before
        $this->actingAs(User::create(['name' => 'منشی', 'email' => 's@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]));
        Livewire::test(TodayOverview::class)
            ->assertSee(e(CallResource::getUrl('index', ['tab' => 'all'])), false)
            ->assertDontSee('reports/calls');
    }

    public function test_a_hand_edited_address_still_opens_the_page(): void
    {
        foreach (['from=garbage', 'from=2026-13-45', 'from=2026-01-01&to=nope', 'period=abc', 'agent=999999', 'sat=password&level=3', 'reason[]=x'] as $query) {
            $this->get(ReportCalls::getUrl().'?'.$query)->assertOk();
        }
        // a date that is not a real one falls back to the period
        $this->assertSame(ReportCalls::query([])->count(), ReportCalls::query(['from' => '2026-13-45'])->count());

        $this->get(ReportCalls::getUrl().'?type=<script>alert(1)</script>')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_cards_and_the_agents_table_link_to_their_calls(): void
    {
        Livewire::test(ReportOverview::class, ['pageFilters' => ['period' => 30]])
            ->assertSee(e(ReportCalls::link(['period' => 30, 'show' => 'purchases'])), false);
        Livewire::test(AgentPerformanceTable::class, ['pageFilters' => ['period' => 30]])
            ->assertSee(e(ReportCalls::link(['period' => 30, 'agent' => $this->ahmadi->id, 'show' => 'results'])), false);
    }

    public function test_a_click_on_a_chart_opens_the_calls_of_that_slice_with_the_dashboards_filters(): void
    {
        $filters = ['pageFilters' => ['period' => 30, 'sales_agent_id' => $this->ahmadi->id]];

        // «سایت» is the first source, «حقوقی» the second type
        Livewire::test(AcquisitionSourceChart::class, $filters)->call('openDetails', 0, 0)
            ->assertRedirect(ReportCalls::link(['period' => 30, 'agent' => $this->ahmadi->id, 'source' => 'website']));
        Livewire::test(CustomerTypeChart::class, $filters)->call('openDetails', 0, 1)
            ->assertRedirect(ReportCalls::link(['period' => 30, 'agent' => $this->ahmadi->id, 'type' => 'legal']));
        // the only reason given, so the first bar
        Livewire::test(NoPurchaseReasonsChart::class, $filters)->call('openDetails', 0, 0)
            ->assertRedirect(ReportCalls::link(['period' => 30, 'agent' => $this->ahmadi->id, 'reason' => 'price']));
        // the fourth bar is «راضی»
        Livewire::test(SatisfactionChart::class, $filters)->call('openDetails', 0, 3)
            ->assertRedirect(ReportCalls::link(['period' => 30, 'agent' => $this->ahmadi->id, 'sat' => 'overall_satisfaction', 'level' => 4]));
        // today's point on the purchases line: the last of 30 days
        Livewire::test(CallsTrendChart::class, ['pageFilters' => ['period' => 30]])->call('openDetails', 1, 29)
            ->assertRedirect(ReportCalls::link(['period' => 30, 'show' => 'purchases', 'from' => today()->toDateString(), 'to' => today()->addDay()->toDateString()]));
        $this->assertEqualsCanonicalizing(['خریدار سایت', 'خریدار معرفی'], $this->names(['show' => 'purchases', 'from' => today()->toDateString(), 'to' => today()->addDay()->toDateString()]));
    }
}
