<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Models\SalesAgent;
use App\Support\Persian;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Each sales agent's figures for the period: referrals, customers reached, purchases, purchase rate
 * and average satisfaction with the agent.
 */
class AgentPerformanceTable extends TableWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function table(Table $table): Table
    {
        $from = $this->periodStart();
        $lastResult = 'f.answered = 1 and f.id = (select max(f2.id) from follow_ups f2 where f2.call_id = f.call_id and f2.answered = 1)';

        $calls = fn () => DB::table('calls')
            ->whereColumn('calls.sales_agent_id', 'sales_agents.id')
            ->where('calls.created_at', '>=', $from);
        $results = fn () => DB::table('follow_ups as f')
            ->join('calls', 'calls.id', '=', 'f.call_id')
            ->whereColumn('calls.sales_agent_id', 'sales_agents.id')
            ->where('calls.created_at', '>=', $from)
            ->whereRaw($lastResult);

        $percent = fn ($part, $whole): string => $whole > 0 ? Persian::digits(round($part / $whole * 100)).'٪' : '—';

        return $table
            ->heading('عملکرد کارشناسان فروش')
            ->query(fn (): Builder => SalesAgent::query()
                ->when($this->salesAgentId(), fn (Builder $q, int $id) => $q->whereKey($id))
                ->select('sales_agents.*')
                ->selectSub($calls()->selectRaw('count(*)'), 'referrals')
                ->selectSub($results()->selectRaw('count(*)'), 'reached')
                ->selectSub($results()->where('f.purchased', true)->selectRaw('count(*)'), 'purchased')
                ->selectSub($results()->selectRaw('avg(f.agent_satisfaction)'), 'satisfaction'))
            ->defaultSort('referrals', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('کارشناس'),
                TextColumn::make('referrals')->label('ارجاع')->formatStateUsing(fn ($state): string => Persian::digits($state))->sortable(),
                TextColumn::make('reached')->label('پیگیری شده')->formatStateUsing(fn ($state): string => Persian::digits($state))->sortable(),
                TextColumn::make('purchased')->label('خرید')->formatStateUsing(fn ($state): string => Persian::digits($state))->sortable(),
                TextColumn::make('conversion')
                    ->label('نرخ خرید')
                    ->state(fn (SalesAgent $record): string => $percent($record->purchased, $record->reached))
                    ->weight('bold'),
                TextColumn::make('satisfaction')
                    ->label('رضایت از کارشناس')
                    ->formatStateUsing(fn ($state): string => $state ? Persian::digits(number_format((float) $state, 1)).' از ۵' : '—')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->emptyStateHeading('هنوز کارشناسی ثبت نشده')
            ->emptyStateDescription(null);
    }
}
