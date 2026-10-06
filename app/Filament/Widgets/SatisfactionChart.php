<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Calls\Actions\RecordFollowUpAction;
use App\Filament\Widgets\Concerns\ChartStyle;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * How satisfied reached customers were, from very unhappy to very happy, for the sales agent and for
 * Tamin Falat overall. Each level is named on the axis, so the colour only reinforces it.
 */
class SatisfactionChart extends ChartWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 5;

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public ?string $filter = 'overall_satisfaction';

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function getHeading(): string
    {
        return 'رضایت مشتریان';
    }

    protected function getFilters(): ?array
    {
        return [
            'overall_satisfaction' => 'رضایت کلی',
            'agent_satisfaction' => 'رضایت از کارشناس',
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $column = $this->filter === 'agent_satisfaction' ? 'agent_satisfaction' : 'overall_satisfaction';

        $counts = $this->resultsQuery()
            ->whereNotNull("follow_ups.{$column}")
            ->selectRaw("follow_ups.{$column} as level, count(*) as total")
            ->groupBy("follow_ups.{$column}")
            ->pluck('total', 'level');

        return [
            'datasets' => [[
                'label' => 'تعداد مشتری',
                'data' => array_map(fn (int $level): int => (int) ($counts[$level] ?? 0), [1, 2, 3, 4, 5]),
                'backgroundColor' => ChartStyle::SATISFACTION,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 40,
            ]],
            'labels' => array_values(RecordFollowUpAction::SATISFACTION),
        ];
    }

    /** A bar: the customers who gave that level, for the satisfaction shown. */
    public function openDetails(int $dataset, int $index): void
    {
        $this->redirect($this->reportLink([
            'sat' => $this->filter === 'agent_satisfaction' ? 'agent_satisfaction' : 'overall_satisfaction',
            'level' => $index + 1,
        ]));
    }

    protected function getOptions(): RawJs
    {
        return ChartStyle::clickable(ChartStyle::options([
            'plugins' => ['legend' => ['display' => false]],
        ]));
    }
}
