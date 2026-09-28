<?php

namespace App\Filament\Resources\Calls\Tables;

use App\Enums\AcquisitionSource;
use App\Enums\CallStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\Calls\Actions\RecordFollowUpAction;
use App\Models\Call;
use App\Models\Customer;
use App\Support\Persian;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CallsTable
{
    /** Periods for the "date of call" filter, in days back from today. */
    public const PERIODS = [
        'today' => 'امروز',
        '7' => '۷ روز اخیر',
        '30' => '۳۰ روز اخیر',
        '90' => '۳ ماه اخیر',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'salesAgent', 'result']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('تاریخ تماس')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn ($state): string => Persian::date($state))
                    ->sortable(),

                // the number sits under the name, so the row fits without a sideways scroll
                TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->description(fn (Call $record): string => collect([Persian::digits($record->customer?->numbers()), $record->customer?->company])->filter()->join(' · '))
                    // a search with no digits in it must not fall through to "phone like %%",
                    // which would match every row and look like the search doing nothing
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = Customer::digitsOnly($search);
                        $phone = Customer::normalizePhone($search);

                        return $query->whereHas('customer', fn (Builder $customer) => $customer
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%")
                            ->when($phone !== null, fn (Builder $c) => $c->orWhere('phone', 'like', "%{$phone}%"))
                            ->when($digits !== null, fn (Builder $c) => $c->orWhere('landline', 'like', "%{$digits}%")));
                    }),

                TextColumn::make('customer.phone')
                    ->label('شماره')
                    ->state(fn (Call $record): ?string => $record->customer?->numbers())
                    ->formatStateUsing(fn ($state): string => Persian::digits($state))
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('salesAgent.name')
                    ->label('کارشناس فروش')
                    ->visibleFrom('md')
                    ->placeholder('—'),

                TextColumn::make('request')
                    ->label('درخواست')
                    ->visibleFrom('lg')
                    ->limit(20)
                    ->tooltip(fn (Call $record): string => $record->request)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('وضعیت')
                    ->visibleFrom('md')
                    ->badge(),

                TextColumn::make('follow_up_on')
                    ->label('تاریخ پیگیری')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn ($state, Call $record): string => $record->status === CallStatus::AwaitingFollowUp ? Persian::date($state) : '—')
                    ->color(fn (Call $record): ?string => $record->status === CallStatus::AwaitingFollowUp && $record->follow_up_on->lt(today()) ? 'danger' : null)
                    ->sortable(),

                IconColumn::make('result.purchased')
                    ->label('خرید کرد؟')
                    ->visibleFrom('md')
                    ->boolean()
                    ->placeholder('—'),

                // switched on from the columns menu when needed; off by default so a row fits a laptop screen
                // the badge takes its colour from the enum, the same colour as the slice in the report
                TextColumn::make('source')
                    ->label('نحوه آشنایی')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('customer.type')
                    ->label('نوع مشتری')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notes')
                    ->label('توضیحات')
                    ->limit(30)
                    ->tooltip(fn (Call $record): ?string => $record->notes)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // newest call on top; the columns are still sortable by hand
            ->defaultSort('created_at', 'desc')
            // the filters open from the funnel beside the search, as on every other table, instead
            // of taking a near-empty row of their own above the list
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::ExtraLarge)
            ->filters([
                SelectFilter::make('sales_agent_id')
                    ->label('کارشناس فروش')
                    ->relationship('salesAgent', 'name')
                    ->preload(),

                SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(CallStatus::class),

                SelectFilter::make('source')
                    ->label('نحوه آشنایی')
                    ->options(AcquisitionSource::class),

                SelectFilter::make('customer_type')
                    ->label('نوع مشتری')
                    ->options(CustomerType::class)
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $type): Builder => $query->whereHas('customer', fn (Builder $c) => $c->where('type', $type)),
                    )),

                Filter::make('period')
                    ->schema([
                        Select::make('period')
                            ->label('تاریخ تماس')
                            ->options(self::PERIODS)
                            ->placeholder('همه'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['period'] ?? null,
                        fn (Builder $query, string $period): Builder => $period === 'today'
                            ? $query->whereDate('created_at', today())
                            : $query->where('created_at', '>=', today()->subDays((int) $period)),
                    )),
            ])
            // a call is never deleted on its own: it goes and comes back with its customer
            ->recordActions([
                RecordFollowUpAction::make(),
                EditAction::make()->label('جزئیات'),
            ])
            // an empty list says why it is empty, tab by tab; only a search with no match adds a hint
            ->emptyStateIcon(fn (HasTable $livewire): Heroicon => self::emptyState($livewire)[0])
            ->emptyStateHeading(fn (HasTable $livewire): string => self::emptyState($livewire)[1])
            ->emptyStateDescription(fn (HasTable $livewire): ?string => self::emptyState($livewire)[2]);
    }

    /**
     * @return array{0: Heroicon, 1: string, 2: ?string}
     */
    private static function emptyState(HasTable $livewire): array
    {
        if (filled($livewire->getTableSearch())) {
            return [Heroicon::OutlinedMagnifyingGlass, 'نتیجه ای پیدا نشد', 'با نام، شرکت یا شماره ی دیگری جستجو کنید.'];
        }

        return match ($livewire->activeTab ?? null) {
            'today' => [Heroicon::OutlinedCheckCircle, 'امروز پیگیری ای نمانده', null],
            'awaiting' => [Heroicon::OutlinedClock, 'تماسی در انتظار پیگیری نیست', null],
            'done' => [Heroicon::OutlinedPhone, 'هنوز تماسی پیگیری نشده', null],
            'unreachable' => [Heroicon::OutlinedPhoneXMark, 'مشتری بی پاسخی نیست', null],
            default => [Heroicon::OutlinedPhone, 'هنوز تماسی ثبت نشده', null],
        };
    }
}
