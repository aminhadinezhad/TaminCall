<?php

namespace App\Console\Commands;

use App\Enums\AcquisitionSource;
use App\Enums\CallStatus;
use App\Enums\CustomerType;
use App\Enums\NoPurchaseReason;
use App\Enums\UserRole;
use App\Models\Call;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\SalesAgent;
use App\Models\User;
use App\Support\Persian;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\CalendarUtils;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Brings in the calls logged in the Google Form sheet before Tamin Call existed
 * («Contact Information (Responses)»): one call per row, its customer (found by mobile number or
 * added), the sales agent, and the follow-up result where the sheet has one.
 *
 *   php artisan tamin:import-calls "/root/contacts.xlsx" --dry-run   shows what would happen, saves nothing
 *   php artisan tamin:import-calls "/root/contacts.xlsx"             does it
 *
 * Running it twice adds nothing the second time: a row whose call is already in (same customer,
 * same day, same request text) is skipped. Rows without a usable mobile number are skipped and
 * listed in storage/app/import-skipped.csv.
 */
class ImportCalls extends Command
{
    protected $signature = 'tamin:import-calls {file : the .xlsx file} {--dry-run : show what would be added without saving anything}';

    protected $description = 'ورود تماس های ثبت شده در فایل اکسل فرم قبلی';

    /** The request text of every imported call; the sheet has no request column. */
    public const REQUEST = 'ثبت شده از فرم قبلی';

    // columns of the sheet «Form responses 1»
    private const TIMESTAMP = 0;

    private const DATE = 1;

    private const AGENT = 2;

    private const CALLER = 3;

    private const PHONE = 4;

    private const TYPE = 5;

    private const LOCATION = 6;

    private const NOTES = 7;

    private const SOURCE = 8;

    private const RESULT = 9;

    private const REASON = 10;

    private const SATISFACTION = 11;

    private const INVOICE = 12;

    private array $counts = [
        'rows' => 0, 'calls' => 0, 'customers_new' => 0, 'customers_existing' => 0, 'agents_new' => 0,
        'follow_ups' => 0, 'already_in' => 0, 'skipped' => 0,
    ];

    /** @var array<int, array{row: int, phone: string, caller: string, why: string}> */
    private array $skipped = [];

    /** @var Collection<string, SalesAgent> by a spelling-free key */
    private Collection $agents;

    /** @var Collection<int, User> */
    private Collection $users;

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("فایل پیدا نشد: {$file}");

            return self::FAILURE;
        }

        $rows = $this->readRows($file);
        $dry = (bool) $this->option('dry-run');

        $this->agents = SalesAgent::all()->keyBy(fn (SalesAgent $agent) => self::key($agent->name));
        // only secretaries take calls; a manager's surname must not catch an agent's name
        $this->users = User::where('role', UserRole::Secretary)->get();

        DB::beginTransaction();

        try {
            foreach ($rows as $number => $row) {
                $this->counts['rows']++;
                $this->importRow($number, $row);
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('خطا در ردیف '.($number ?? '?').': '.$e->getMessage());
            $this->error('هیچ چیزی ذخیره نشد.');

            return self::FAILURE;
        }

        $dry ? DB::rollBack() : DB::commit();

        $this->report($dry);

        return self::SUCCESS;
    }

    /** @return array<int, array<int, mixed>> the sheet's rows by their row number, header left out */
    private function readRows(string $file): array
    {
        $reader = new Reader;
        $reader->open($file);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $number = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $number++;
                if ($number === 1) {
                    continue;
                }
                $values = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                if (collect($values)->filter(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? 'x' : $v)) !== '')->isNotEmpty()) {
                    $rows[$number] = $values;
                }
            }
            break; // the responses are on the first sheet; the second is a chart's data
        }

        $reader->close();

        return $rows;
    }

    private function importRow(int $number, array $row): void
    {
        $text = fn (int $column): string => trim(Persian::plain((string) ($row[$column] ?? '')));

        $phone = self::mobile($text(self::PHONE));
        if ($phone === null) {
            $this->skip($number, $text(self::PHONE), $text(self::CALLER), 'شماره موبایل معتبر نیست');

            return;
        }

        $when = $this->callTime($row[self::TIMESTAMP] ?? null, $text(self::DATE));

        $customer = Customer::withTrashed()->where('phone', $phone)->first();
        if ($customer?->trashed()) {
            $this->skip($number, $phone, $text(self::CALLER), 'این مشتری در تامین کال حذف شده است');

            return;
        }

        if ($customer === null) {
            $customer = $this->newCustomer($text(self::CALLER), $phone, $text(self::TYPE), $when);
            $this->counts['customers_new']++;
        } elseif ($customer->wasRecentlyCreated === false && ! isset($this->seenCustomers[$customer->id])) {
            $this->counts['customers_existing']++;
        }
        $this->seenCustomers[$customer->id] = true;

        $already = Call::withTrashed()
            ->where('customer_id', $customer->id)
            ->where('request', self::REQUEST)
            ->whereDate('created_at', $when->toDateString())
            ->exists();
        if ($already) {
            // a second run, or two rows with one number on one day (in the sheet, two different
            // names sharing a number): listed so the second can be checked by hand
            $this->counts['already_in']++;
            $this->skipped[] = ['row' => $number, 'phone' => $phone, 'caller' => $text(self::CALLER),
                'why' => 'تماس همین شماره در همین روز از قبل هست ('.$customer->name.')'];

            return;
        }

        [$source, $sourceNote] = self::source($text(self::SOURCE));

        $receiver = $this->receiver($text(self::NOTES));
        // «پاسخ دهنده : خانم حبیبی» or «مرادی» only names who took the call; with that saved as the
        // call's receiver, the note has nothing more to say
        $notes = $receiver && preg_match('/^(پاسخ\s*دهند\s*ه\s*:\s*)?(خانم|آقای)?\s*\S+$/u', $text(self::NOTES)) ? '' : $text(self::NOTES);

        // a secretary named in the agent column (خانم مرادی) took the call herself; she is not a
        // sales agent, so she becomes the receiver and the call has no agent
        $secretary = $this->receiver($text(self::AGENT));
        $agent = $secretary ? null : $this->agent($text(self::AGENT));
        $receiver ??= $secretary;

        $call = new Call;
        $call->forceFill([
            'customer_id' => $customer->id,
            'sales_agent_id' => $agent?->id,
            'received_by' => $receiver?->id,
            'request' => self::REQUEST,
            'source' => $source,
            'notes' => $this->callNotes($number, $notes, $text(self::LOCATION), $sourceNote),
            'status' => CallStatus::Done,
            'follow_up_on' => $when->toDateString(),
            'unanswered_attempts' => 0,
            'created_at' => $when,
            'updated_at' => $when,
        ])->save();
        $this->counts['calls']++;

        $this->followUp($call, $text(self::RESULT), $text(self::REASON), $text(self::SATISFACTION), $text(self::INVOICE), $when);
    }

    /** @var array<int, true> customers already counted */
    private array $seenCustomers = [];

    private function newCustomer(string $caller, string $phone, string $type, Carbon $when): Customer
    {
        // «موحدی نیا(هواپیمایی آتا)»: the person, then the company in brackets
        $name = $caller;
        $company = null;
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/u', $caller, $m)) {
            $name = trim($m[1]);
            $company = trim($m[2]);
        }

        $type = match ($type) {
            'حقوقی' => CustomerType::Legal,
            'حقیقی' => CustomerType::Individual,
            default => null,
        };

        // brackets that say the company was not named, or that hold something other than a company
        $notes = null;
        if ($company !== null && ($type !== CustomerType::Legal || preg_match('/ذکر نشد|نا ?واضح|^شخص/u', $company))) {
            $notes = $company;
            $company = null;
        }

        $customer = new Customer;
        $customer->forceFill([
            'name' => $name !== '' ? $name : ($company ?? 'بدون نام'),
            'phone' => $phone,
            'type' => $type,
            'company' => $company,
            'notes' => $notes,
            'created_at' => $when,
            'updated_at' => $when,
        ])->save();

        return $customer;
    }

    private function followUp(Call $call, string $result, string $reason, string $satisfaction, string $invoice, Carbon $when): void
    {
        $reason = in_array($reason, ['', '-', '*'], true) ? '' : $reason;
        $notes = collect([$reason, $invoice !== '' ? "شماره فاکتور: {$invoice}" : null])->filter()->implode("\n") ?: null;
        $stars = is_numeric($satisfaction) ? max(1, min(5, (int) ceil(((float) $satisfaction) / 2))) : null;

        $data = match (true) {
            $result === 'بله' => ['answered' => true, 'purchased' => true],
            $result === 'خیر' => ['answered' => true, 'purchased' => false, 'no_purchase_reason' => self::reason($reason)],
            $result === 'در روند خرید' => ['answered' => true, 'purchased' => false, 'no_purchase_reason' => NoPurchaseReason::Undecided, 'note' => 'در روند خرید'],
            $result === 'بی پاسخ' => ['answered' => false],
            str_contains($result, 'شماره اشتباه') => ['answered' => false, 'note' => 'شماره اشتباه ثبت شده'],
            default => null,
        };

        if ($data === null) {
            // no result in the sheet: the call stays «پیگیری شد» without a follow-up; any text goes on the call
            if ($notes) {
                $call->forceFill(['notes' => trim($call->notes."\n".$notes)])->saveQuietly();
            }

            return;
        }

        $answered = $data['answered'];
        $followUp = new FollowUp;
        $followUp->forceFill([
            'call_id' => $call->id,
            'user_id' => null,
            'answered' => $answered,
            'purchased' => $answered ? $data['purchased'] : null,
            'no_purchase_reason' => $answered && ! $data['purchased'] ? ($data['no_purchase_reason'] ?? null) : null,
            'agent_satisfaction' => null,
            'overall_satisfaction' => $answered ? $stars : null,
            'notes' => collect([$data['note'] ?? null, $notes])->filter()->implode("\n") ?: null,
            'created_at' => $when,
            'updated_at' => $when,
        ])->save();
        $this->counts['follow_ups']++;

        if (! $answered) {
            // not reached (or a wrong number): the call shows as «پاسخ نداد»
            $call->forceFill(['status' => CallStatus::Unreachable, 'unanswered_attempts' => 1])->saveQuietly();
        }
    }

    /** The call's day from the «تاریخ» column (month/day or day/month), checked against the form's timestamp. */
    private function callTime(mixed $stamp, string $date): Carbon
    {
        $stamp = match (true) {
            $stamp instanceof \DateTimeInterface => Carbon::instance($stamp)->setTimezone('Asia/Tehran'),
            // an Excel date serial (days since 1899-12-30) when the cell carries no date format
            is_numeric($stamp) && $stamp > 30000 => Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Tehran')->addSeconds((int) round($stamp * 86400)),
            default => null,
        };

        $candidates = [];
        $date = strtr($date, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m)) {
            foreach ([[(int) $m[1], (int) $m[2]], [(int) $m[2], (int) $m[1]]] as [$month, $day]) {
                if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && CalendarUtils::checkDate((int) $m[3], $month, $day)) {
                    [$gy, $gm, $gd] = CalendarUtils::toGregorian((int) $m[3], $month, $day);
                    $candidates[] = Carbon::create($gy, $gm, $gd, 10, 0, 0, 'Asia/Tehran');
                }
            }
        }

        if ($stamp && $candidates) {
            // the call was logged on or after its day: the reading closest before the timestamp wins
            usort($candidates, function (Carbon $a, Carbon $b) use ($stamp) {
                $score = fn (Carbon $c) => $c->copy()->startOfDay()->lte($stamp) ? $stamp->diffInDays($c, true) : 10000 + $c->diffInDays($stamp, true);

                return $score($a) <=> $score($b);
            });
        }

        $day = $candidates[0] ?? $stamp ?? now('Asia/Tehran');

        // the form's own time of day when it was filled in the same day
        return $stamp && $stamp->isSameDay($day) ? $stamp->copy() : $day->copy();
    }

    private function agent(string $name): ?SalesAgent
    {
        if ($name === '') {
            return null;
        }

        $name = str_replace('چطردور', 'چطردوز', $name);
        $key = self::key($name);

        if (! $this->agents->has($key)) {
            $agent = SalesAgent::create(['name' => preg_replace('/\s+/u', ' ', $name), 'is_active' => true]);
            $this->agents->put($key, $agent);
            $this->counts['agents_new']++;
        }

        return $this->agents->get($key);
    }

    /** «پاسخ دهنده : خانم حبیبی», or just «مرادی»: the Tamin Call user with that surname, if there is one */
    private function receiver(string $notes): ?User
    {
        foreach ($this->users as $user) {
            $surname = collect(preg_split('/\s+/u', trim(Persian::plain($user->name))))->last();
            if ($surname && mb_strlen($surname) > 2 && str_contains($notes, $surname)) {
                return $user;
            }
        }

        return null;
    }

    private function callNotes(int $number, string $notes, string $location, ?string $sourceNote): string
    {
        $location = $location === 'تهرلن' ? 'تهران' : $location;

        return collect([
            in_array($notes, ['', '*', '-'], true) ? null : $notes,
            in_array($location, ['', '*', 'تهران'], true) ? null : "موقعیت: {$location}",
            $sourceNote,
            "از فایل اکسل فرم قبلی، ردیف {$number}",
        ])->filter()->implode("\n");
    }

    /** @return array{0: ?AcquisitionSource, 1: ?string} the source, and a note when it was mapped loosely */
    private static function source(string $source): array
    {
        return match ($source) {
            'سایت' => [AcquisitionSource::Website, null],
            'تبلیغات اینترنتی' => [AcquisitionSource::OnlineAds, null],
            'محیطی' => [AcquisitionSource::Outdoor, null],
            'ربات بله' => [AcquisitionSource::BaleBot, null],
            'معرفی دوستان', 'معرف' => [AcquisitionSource::Referral, null],
            '' => [null, null],
            default => [AcquisitionSource::OnlineAds, "نحوه آشنایی در فرم: {$source}"],
        };
    }

    /** The sheet's free-text reason, sorted into Tamin Call's reasons. The text itself is kept in the notes. */
    public static function reason(string $text): ?NoPurchaseReason
    {
        return match (true) {
            $text === '' => null,
            (bool) preg_match('/موجود\s*نداشت|موجود نبود|نداشتین|تموم شده/u', $text) => NoPurchaseReason::OutOfStock,
            (bool) preg_match('/قیمت|مبلغ|گرون|توافق نرسید/u', $text) => NoPurchaseReason::Price,
            (bool) preg_match('/جای ?دیگ|شرکت دیگر|مجموعه دیگر|خرید انجام شئه/u', $text) => NoPurchaseReason::BoughtElsewhere,
            (bool) preg_match('/تماس نگرفت|وصل نشده|پیگیری نکرد/u', $text) => NoPurchaseReason::NotContacted,
            (bool) preg_match('/منتظر|تائید|تایید|خبر میدم|اطلاع ?مید|زنگ میزنم|تماس میگیر|بررسی|تعویق|آینده|آبنده|روند خرید|درحال|چک میکنم|تصمیم|مناقصه|استعلام|اسرع وقت/u', $text) => NoPurchaseReason::Undecided,
            default => NoPurchaseReason::Other,
        };
    }

    /** A mobile number in any of the sheet's spellings (a missing 0, Persian digits, a second number), or null. */
    public static function mobile(string $value): ?string
    {
        $candidates = [$value, ...preg_split('/[^0-9۰-۹٠-٩+]+/u', $value)];

        foreach ($candidates as $candidate) {
            $phone = Customer::normalizePhone($candidate);
            if ($phone !== null && preg_match('/^09\d{9}$/', $phone)) {
                return $phone;
            }
        }

        return null;
    }

    private static function key(string $name): string
    {
        return preg_replace('/\s+/u', '', Persian::plain($name));
    }

    private function skip(int $number, string $phone, string $caller, string $why): void
    {
        $this->counts['skipped']++;
        $this->skipped[] = ['row' => $number, 'phone' => $phone, 'caller' => $caller, 'why' => $why];
    }

    private function report(bool $dry): void
    {
        $this->newLine();
        $this->info($dry ? 'اجرای آزمایشی: هیچ چیزی ذخیره نشد.' : 'ورود داده انجام شد.');

        $this->table(['', 'تعداد'], [
            ['ردیف های فایل', $this->counts['rows']],
            ['تماس های اضافه شده', $this->counts['calls']],
            ['مشتری جدید', $this->counts['customers_new']],
            ['مشتری که از قبل بود', $this->counts['customers_existing']],
            ['کارشناس فروش جدید', $this->counts['agents_new']],
            ['نتیجه پیگیری ثبت شده', $this->counts['follow_ups']],
            ['از قبل وارد شده (رد شد)', $this->counts['already_in']],
            ['رد شده (شماره نامعتبر یا مشتری حذف شده)', $this->counts['skipped']],
        ]);

        if ($this->skipped) {
            $path = storage_path('app/import-skipped.csv');
            $csv = fopen($path, 'w');
            fwrite($csv, "\xEF\xBB\xBF"); // so Excel reads the Persian text
            fputcsv($csv, ['ردیف', 'شماره', 'تماس گیرنده', 'علت']);
            foreach ($this->skipped as $skip) {
                fputcsv($csv, [$skip['row'], $skip['phone'], $skip['caller'], $skip['why']]);
            }
            fclose($csv);
            $this->line("فهرست ردیف های رد شده: {$path}");
        }
    }
}
