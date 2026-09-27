<?php

namespace App\Filament\Resources\Calls\Schemas;

use App\Enums\AcquisitionSource;
use App\Enums\CustomerType;
use App\Models\Call;
use App\Models\Customer;
use App\Support\Persian;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class CallForm
{
    /** When to call the customer back, counted in days from today (Fridays are skipped). */
    public const FOLLOW_UP_CHOICES = [
        1 => 'فردا',
        2 => 'دو روز بعد',
        3 => 'سه روز بعد',
        7 => 'یک هفته بعد',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('تماس مشتری')
                    ->columns(2)
                    ->columnSpanFull()
                    ->components([
                        Select::make('customer_id')
                            ->label('مشتری')
                            // newest first: the customer just added sits at the top of the list
                            ->relationship('customer', 'name', fn (EloquentBuilder $query) => $query->orderByDesc('id'))
                            ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->name.' - '.$record->phone)
                            ->searchable(['name', 'phone', 'company'])
                            ->preload()
                            // the cursor starts here on a new call, so typing the name or number is the first key
                            ->autofocus(fn (string $operation): bool => $operation === 'create')
                            ->required()
                            ->helperText('با نام یا شماره جستجو کنید؛ مشتری جدید را با دکمه + اضافه کنید.')
                            ->createOptionForm(self::customerFields())
                            ->createOptionModalHeading('مشتری جدید'),

                        Select::make('sales_agent_id')
                            ->label('ارجاع به کارشناس فروش')
                            ->relationship('salesAgent', 'name', fn ($query) => $query->active())
                            ->preload()
                            ->required(),

                        Textarea::make('request')
                            ->label('درخواست مشتری')
                            ->placeholder('مثلاً: استعلام قیمت ۵۰ کارتن دستمال کاغذی برای شرکت')
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),

                        // required, so the "how customers find us" report has no gaps
                        Select::make('source')
                            ->label('نحوه آشنایی')
                            ->options(AcquisitionSource::class)
                            ->placeholder('انتخاب کنید')
                            ->required(),

                        Textarea::make('notes')
                            ->label('توضیحات')
                            ->placeholder('هر نکته ی دیگری درباره ی این تماس (اختیاری)')
                            ->rows(2),

                        ToggleButtons::make('follow_up_in')
                            ->label(fn (string $operation): string => $operation === 'create' ? 'پیگیری مشتری' : 'تغییر زمان پیگیری')
                            ->helperText(fn (?Call $record): ?string => $record
                                ? 'زمان فعلی: '.Persian::dayName($record->follow_up_on).'. فقط اگر می خواهید عوض شود انتخاب کنید.'
                                : 'در این روز، این مشتری در فهرست «پیگیری امروز» قرار می گیرد.')
                            ->options(self::FOLLOW_UP_CHOICES)
                            ->default(fn (string $operation): ?int => $operation === 'create' ? 1 : null)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->inline()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Customer fields, shared by the call form's "new customer" button and the customers page.
     *
     * @return array<int, Component>
     */
    public static function customerFields(): array
    {
        return [
            TextInput::make('name')
                ->label('نام و نام خانوادگی')
                ->required()
                ->maxLength(255),

            TextInput::make('phone')
                ->label('شماره موبایل')
                // not ->tel(): its check rejects Persian digits before they are converted below
                ->inputMode('tel')
                ->required()
                ->placeholder('09123456789')
                ->extraInputAttributes(['dir' => 'ltr'])
                ->dehydrateStateUsing(fn (?string $state): ?string => Customer::normalizePhone($state))
                // inside the call form's "new customer" popup the record is the call, not a customer
                ->rules(fn ($record) => [
                    function (string $attribute, $value, \Closure $fail) use ($record) {
                        $record = $record instanceof Customer ? $record : null;
                        $phone = Customer::normalizePhone($value);

                        if (! preg_match('/^09\d{9}$/', (string) $phone)) {
                            $fail('شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');

                            return;
                        }

                        $taken = Customer::where('phone', $phone)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->first();

                        if ($taken) {
                            $fail("این شماره قبلاً برای «{$taken->name}» ثبت شده است.");
                        }
                    },
                ]),

            ToggleButtons::make('type')
                ->label('نوع مشتری')
                ->options(CustomerType::class)
                ->inline()
                ->required()
                ->live()
                // switching to «حقیقی» empties the company, so nothing is left behind in the hidden field
                ->afterStateUpdated(function ($state, Set $set): void {
                    if (! self::isLegal($state)) {
                        $set('company', null);
                    }
                }),

            // only a legal customer is a company or organisation, so only it has (and needs) one
            TextInput::make('company')
                ->label('شرکت / سازمان')
                ->visible(fn (Get $get): bool => self::isLegal($get('type')))
                ->required(fn (Get $get): bool => self::isLegal($get('type')))
                ->maxLength(255),

            Textarea::make('notes')
                ->label('توضیحات')
                ->rows(2),
        ];
    }

    private static function isLegal(mixed $type): bool
    {
        return $type === CustomerType::Legal || $type === CustomerType::Legal->value;
    }
}
