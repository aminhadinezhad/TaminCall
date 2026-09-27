<?php

namespace Tests\Feature;

use App\Console\Commands\ImportCalls;
use App\Enums\AcquisitionSource;
use App\Enums\CallStatus;
use App\Enums\CustomerType;
use App\Enums\NoPurchaseReason;
use App\Enums\UserRole;
use App\Models\Call;
use App\Models\Customer;
use App\Models\SalesAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Morilog\Jalali\Jalalian;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportCallsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'calls').'.xlsx';
        $half = "\u{200C}";

        // the sheet's own layout: Timestamp, date, agent, caller, phone, type, location, notes, source, bought?, why not, satisfaction, invoice
        $rows = [
            ['Timestamp', 'تاریخ', 'کارشناس', 'تماس گیرنده', 'شماره تماس', 'شخص حقیقی/حقوقی', 'موقعیت شرکت', 'توضیحات', 'نحوه آشنایی', 'خرید کردند ؟ ', 'به چه علت خرید نکردند ؟ ', 'میزان رضایت مشتری ', 'شماره فاکتور '],
            // month/day, bought, 10 out of 10, an invoice
            [new \DateTime('2026-07-01 11:20:37'), '4/1/1405', 'خانم صلح'.$half.'فام', 'موحدی نیا(هواپیمایی آتا)', '09905661842', 'حقوقی', 'تهران', 'پاسخ دهنده : خانم حبیبی', 'سایت', 'بله', '', '10', '3031'],
            // day/month, the same agent spelled with a space, not bought for the price, 5 out of 10
            [new \DateTime('2026-08-23 09:00:00'), '01/06/1405', 'خانم صلح فام', 'اردستانی(درمانگاه خیریه حیدری)', '9102459081', 'حقوقی', 'شهرستان/حومه', 'ورامین', 'ترب', 'خیر', 'قیمت بالا بود', '5', ''],
            // not reached
            [new \DateTime('2026-08-24 09:00:00'), '6/2/1405', 'خانم چطردور', 'فروغی( شخص)', '0912 111 2233', 'حقیقی', 'تهران', '*', 'سایت', 'بی پاسخ', '-', '-', ''],
            // no result: stays done without a follow-up
            [new \DateTime('2026-08-24 10:00:00'), '6/2/1405', 'خانم چطردوز', 'سلمانی(نا واضح اسم شرکت رو گفتند)', '09127253876', 'حقوقی', 'تهران', '*', 'سایت', '', '', '', ''],
            // the secretary named in the agent column: she took the call, no agent is made for her
            [new \DateTime('2026-08-26 10:00:00'), '6/4/1405', 'خانم مرادی', 'رحمتی(ایزدی)', '09121110000', 'حقوقی', 'تهران', '*', 'سایت', '', '', '', ''],
            // a landline with an extension: skipped
            [new \DateTime('2026-08-25 10:00:00'), '6/3/1405', 'خانم خاتمی', 'کیانی(درنا دور)', '68235000 داخلی 25', 'حقوقی', 'تهران', '*', 'سایت', '', '', '', ''],
        ];

        $writer = new Writer;
        $writer->openToFile($this->file);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        User::create(['name' => 'خانم حبیبی', 'email' => 'habibi@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]);
        User::create(['name' => 'خانم مرادی', 'email' => 'moradi@test.local', 'password' => 'secret123', 'role' => UserRole::Secretary]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('tamin:import-calls', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Call::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, SalesAgent::count());
    }

    public function test_the_rows_become_customers_calls_and_results(): void
    {
        $this->artisan('tamin:import-calls', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(5, Call::count(), 'the landline row is skipped');
        // «صلح‌فام» and «صلح فام» are one agent, «چطردور» and «چطردوز» too
        $this->assertEqualsCanonicalizing(['خانم صلح فام', 'خانم چطردوز'], SalesAgent::pluck('name')->all());

        $bought = Customer::where('phone', '09905661842')->first();
        $this->assertSame(['موحدی نیا', 'هواپیمایی آتا', CustomerType::Legal], [$bought->name, $bought->company, $bought->type]);
        $call = $bought->calls()->first();
        $this->assertSame('1405-04-01', Jalalian::fromCarbon($call->created_at)->format('Y-m-d'));
        $this->assertSame(CallStatus::Done, $call->status);
        $this->assertSame(AcquisitionSource::Website, $call->source);
        $this->assertSame('خانم حبیبی', $call->receiver->name);
        $this->assertStringNotContainsString('پاسخ دهنده', $call->notes);
        $result = $call->followUps()->first();
        $this->assertTrue($result->purchased);
        $this->assertSame(5, $result->overall_satisfaction);
        $this->assertStringContainsString('3031', $result->notes);

        // «01/06/1405» read as the 1st of Shahrivar (day/month), a missing leading 0 added
        $price = Customer::where('phone', '09102459081')->first()->calls()->first();
        $this->assertSame('1405-06-01', Jalalian::fromCarbon($price->created_at)->format('Y-m-d'));
        $this->assertSame(NoPurchaseReason::Price, $price->followUps()->first()->no_purchase_reason);
        $this->assertSame(3, $price->followUps()->first()->overall_satisfaction);
        $this->assertStringContainsString('ترب', $price->notes);
        $this->assertStringContainsString('شهرستان', $price->notes);

        $unreached = Customer::where('phone', '09121112233')->first();
        $this->assertSame(CallStatus::Unreachable, $unreached->calls()->first()->status);
        $this->assertNull($unreached->company, 'an individual has no company; «شخص» is not one');

        $noResult = Customer::where('phone', '09127253876')->first();
        $this->assertNull($noResult->company);
        $this->assertSame(CallStatus::Done, $noResult->calls()->first()->status);
        $this->assertSame(0, $noResult->calls()->first()->followUps()->count());
        $this->assertSame(0, Call::dueBy(today())->count(), 'nothing lands in today\'s follow-up list');

        $bySecretary = Customer::where('phone', '09121110000')->first()->calls()->first();
        $this->assertNull($bySecretary->sales_agent_id);
        $this->assertSame('خانم مرادی', $bySecretary->receiver->name);
        $this->assertFalse(SalesAgent::where('name', 'like', '%مرادی%')->exists(), 'a secretary is not made a sales agent');

        $this->assertStringContainsString('68235000', file_get_contents(storage_path('app/import-skipped.csv')));
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->artisan('tamin:import-calls', ['file' => $this->file])->assertSuccessful();
        $this->artisan('tamin:import-calls', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(5, Call::count());
        $this->assertSame(5, Customer::count());
    }

    public function test_an_existing_customer_is_reused(): void
    {
        $existing = Customer::create(['name' => 'موحدی نیا', 'phone' => '09905661842', 'type' => CustomerType::Legal, 'company' => 'آتا']);

        $this->artisan('tamin:import-calls', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(1, Customer::where('phone', '09905661842')->count());
        $this->assertSame('آتا', $existing->fresh()->company, 'their details are left as they are');
        $this->assertSame(1, $existing->calls()->count());
    }

    public function test_reasons_and_numbers(): void
    {
        $this->assertSame(NoPurchaseReason::OutOfStock, ImportCalls::reason('چای احمد میخواستم موجود نداشتین'));
        $this->assertSame(NoPurchaseReason::Undecided, ImportCalls::reason('منتظر تائیدیه از طرف مدیرعامل هستند'));
        $this->assertSame(NoPurchaseReason::BoughtElsewhere, ImportCalls::reason('از شرکت دیگری خرید انجام شئه'));
        $this->assertSame(NoPurchaseReason::Other, ImportCalls::reason('خرید غیررسمی'));

        $this->assertSame('09195239558', ImportCalls::mobile('9195239558'));
        $this->assertSame('09121234567', ImportCalls::mobile('۰۹۱۲۱۲۳۴۵۶۷'));
        $this->assertNull(ImportCalls::mobile('88929590 داخلی 107'));
        $this->assertNull(ImportCalls::mobile('0936938512'));
        $this->assertNull(ImportCalls::mobile('وصل شدند'));
    }
}
