<?php

namespace App\Filament\Pages;

use App\Enums\AcquisitionSource;
use App\Enums\CustomerType;
use App\Enums\NoPurchaseReason;
use App\Filament\Resources\Calls\Actions\RecordFollowUpAction;
use App\Filament\Resources\Calls\CallResource;
use App\Models\Call;
use App\Models\Customer;
use App\Models\SalesAgent;
use App\Support\Persian;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The calls behind a figure on the dashboard: a card, a slice, a bar, a point, a number in the agents
 * table. Each one links here with what it counts (the period, the agent, and the slice), and the list
 * is exactly those calls: who, when, what they asked for, how they found us, what came of it.
 * Managers only, like the reports; not in the menu.
 */
class ReportCalls extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'reports/calls';

    protected static ?string $title = 'جزئیات گزارش';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.report-calls';

    /** Days back from today, as on the dashboard. */
    #[Url]
    public ?int $period = null;

    #[Url]
    public ?int $agent = null;

    /** calls (received), results (reached, a result recorded) or purchases. */
    #[Url]
    public ?string $show = null;

    #[Url]
    public ?string $source = null;

    #[Url]
    public ?string $type = null;

    /** A no-purchase reason; implies results that did not end in a purchase. */
    #[Url]
    public ?string $reason = null;

    /** overall_satisfaction or agent_satisfaction, with $level 1 to 5. */
    #[Url]
    public ?string $sat = null;

    #[Url]
    public ?int $level = null;

    /** A stretch of days on the trend chart: from (included) to (not included), Y-m-d. */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    /**
     * @param  array<string, scalar|null>  $slice
     */
    public static function link(array $slice): string
    {
        return static::getUrl(array_filter($slice, fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * The calls a slice counts: received in the period (or the stretch), for the agent, narrowed by
     * the slice. A call's result is its last answered follow-up, as in the reports.
     *
     * @param  array<string, scalar|null>  $slice
     */
    public static function query(array $slice): Builder
    {
        $days = (int) ($slice['period'] ?? Dashboard::DEFAULT_PERIOD);
        $days = array_key_exists($days, Dashboard::PERIODS) ? $days : Dashboard::DEFAULT_PERIOD;
        $sat = static::satisfaction($slice['sat'] ?? null);
        $show = $slice['show'] ?? null;
        $from = static::day($slice['from'] ?? null);
        $to = static::day($slice['to'] ?? null);

        return Call::query()
            ->when(
                $from,
                fn (Builder $q) => $q->where('calls.created_at', '>=', $from)
                    ->when($to, fn (Builder $q) => $q->where('calls.created_at', '<', $to)),
                fn (Builder $q) => $q->where('calls.created_at', '>=', today()->subDays($days - 1)),
            )
            ->when(filled($slice['agent'] ?? null), fn (Builder $q) => $q->where('calls.sales_agent_id', (int) $slice['agent']))
            ->when(filled($slice['source'] ?? null), fn (Builder $q) => $q->where('calls.source', $slice['source']))
            ->when(filled($slice['type'] ?? null), fn (Builder $q) => $q->whereHas('customer', fn (Builder $c) => $c->where('type', $slice['type'])))
            ->when($show === 'results', fn (Builder $q) => $q->whereHas('result'))
            ->when($show === 'purchases', fn (Builder $q) => $q->whereHas('result', fn (Builder $r) => $r->where('purchased', true)))
            ->when(filled($slice['reason'] ?? null), fn (Builder $q) => $q->whereHas('result', fn (Builder $r) => $r->where('purchased', false)->where('no_purchase_reason', $slice['reason'])))
            ->when($sat && filled($slice['level'] ?? null), fn (Builder $q) => $q->whereHas('result', fn (Builder $r) => $r->where($sat, (int) $slice['level'])));
    }

    /** A Y-m-d day from the address, or null when it is not a real one (a hand-edited link). */
    private static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $day = Carbon::createFromFormat('!Y-m-d', $value);

        return $day && $day->toDateString() === $value ? $day : null;
    }

    /** The satisfaction column to count by: only one of the two the reports know. */
    private static function satisfaction(mixed $value): ?string
    {
        return in_array($value, ['overall_satisfaction', 'agent_satisfaction'], true) ? $value : null;
    }

    /** @return array<string, scalar|null> */
    private function slice(): array
    {
        return [
            'period' => $this->period, 'agent' => $this->agent, 'show' => $this->show, 'source' => $this->source,
            'type' => $this->type, 'reason' => $this->reason, 'sat' => $this->sat, 'level' => $this->level,
            'from' => $this->from, 'to' => $this->to,
        ];
    }

    /** What the list holds, in words: «خریدها · ۳۰ روز اخیر · کارشناس: …». */
    public function getSubheading(): string
    {
        $parts = [match (true) {
            filled($this->reason) => 'خرید نکرده: '.(NoPurchaseReason::tryFrom($this->reason)?->getLabel() ?? $this->reason),
            static::satisfaction($this->sat) && filled($this->level) => ($this->sat === 'agent_satisfaction' ? 'رضایت از کارشناس: ' : 'رضایت کلی: ').(RecordFollowUpAction::SATISFACTION[$this->level] ?? $this->level),
            $this->show === 'purchases' => 'خریدها',
            $this->show === 'results' => 'نتیجه های ثبت شده',
            default => 'تماس های ورودی',
        }];

        if ($from = static::day($this->from)) {
            $last = static::day($this->to)?->subDay() ?? today();
            $parts[] = Persian::date($from).($from->isSameDay($last) ? '' : ' تا '.Persian::date($last));
        } else {
            $parts[] = Dashboard::PERIODS[$this->period ?? Dashboard::DEFAULT_PERIOD] ?? Dashboard::PERIODS[Dashboard::DEFAULT_PERIOD];
        }

        if (filled($this->agent)) {
            $parts[] = 'کارشناس: '.(SalesAgent::find($this->agent)?->name ?? '—');
        }
        if (filled($this->source)) {
            $parts[] = 'نحوه آشنایی: '.(AcquisitionSource::tryFrom($this->source)?->getLabel() ?? $this->source);
        }
        if (filled($this->type)) {
            $parts[] = 'مشتری '.(CustomerType::tryFrom($this->type)?->getLabel() ?? $this->type);
        }

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('بازگشت به گزارش ها')
                ->icon(Heroicon::OutlinedArrowUturnRight)
                ->color('gray')
                ->url(Dashboard::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        $satisfaction = fn ($state): string => $state ? RecordFollowUpAction::SATISFACTION[$state] ?? (string) $state : '—';

        return $table
            ->query(fn (): Builder => static::query($this->slice())->with(['customer', 'salesAgent', 'receiver', 'result']))
            ->heading(fn (): string => Persian::digits(static::query($this->slice())->count()).' تماس')
            ->columns([
                TextColumn::make('created_at')
                    ->label('تاریخ تماس')
                    ->formatStateUsing(fn ($state): string => Persian::date($state))
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('مشتری')
                    ->description(fn (Call $record): string => collect([Persian::digits($record->customer?->numbers()), $record->customer?->company])->filter()->join(' · '))
                    // the same search as the calls list: name, company, mobile or landline
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $digits = Customer::digitsOnly($search);
                        $phone = Customer::normalizePhone($search);

                        return $query->whereHas('customer', fn (Builder $customer) => $customer
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%")
                            ->when($phone !== null, fn (Builder $c) => $c->orWhere('phone', 'like', "%{$phone}%"))
                            ->when($digits !== null, fn (Builder $c) => $c->orWhere('landline', 'like', "%{$digits}%")));
                    }),
                // labels in their own colours, as everywhere else in the panel
                TextColumn::make('customer.type')
                    ->label('نوع مشتری')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('salesAgent.name')
                    ->label('کارشناس فروش')
                    ->placeholder('—'),
                TextColumn::make('request')
                    ->label('درخواست')
                    ->limit(40)
                    ->tooltip(fn (Call $record): string => $record->request)
                    ->wrap(),
                TextColumn::make('source')
                    ->label('نحوه آشنایی')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('receiver.name')
                    ->label('ثبت کننده')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge(),
                IconColumn::make('result.purchased')
                    ->label('خرید کرد؟')
                    ->boolean()
                    ->placeholder('—'),
                TextColumn::make('result.no_purchase_reason')
                    ->label('دلیل خرید نکردن')
                    ->placeholder('—'),
                TextColumn::make('result.agent_satisfaction')
                    ->label('رضایت از کارشناس')
                    ->formatStateUsing($satisfaction)
                    ->badge()
                    ->color(fn (?int $state): string => RecordFollowUpAction::SATISFACTION_COLORS[$state] ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('result.overall_satisfaction')
                    ->label('رضایت کلی')
                    ->formatStateUsing($satisfaction)
                    ->badge()
                    ->color(fn (?int $state): string => RecordFollowUpAction::SATISFACTION_COLORS[$state] ?? 'gray')
                    ->placeholder('—'),
                TextColumn::make('notes')
                    ->label('توضیحات')
                    ->limit(30)
                    ->tooltip(fn (Call $record): ?string => $record->notes)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            // a row opens the call, with its whole history of follow-ups
            ->recordUrl(fn (Call $record): string => CallResource::getUrl('edit', ['record' => $record]))
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedPhone)
            ->emptyStateHeading('تماسی با این مشخصات نیست');
    }
}
