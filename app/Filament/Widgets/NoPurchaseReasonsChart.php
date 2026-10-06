<?php

namespace App\Filament\Widgets;

use App\Enums\NoPurchaseReason;
use App\Filament\Widgets\Concerns\ChartStyle;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * Why reached customers did not buy, longest bar first. One series, so one colour; the reason
 * names sit on the axis.
 */
class NoPurchaseReasonsChart extends ChartWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function getHeading(): string
    {
        return 'دلایل خرید نکردن';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** A bar: the customers who did not buy for that reason. */
    public function openDetails(int $dataset, int $index): void
    {
        $reason = $this->counts()->keys()->get($index);

        if ($reason) {
            $this->redirect($this->reportLink(['reason' => $reason]));
        }
    }

    /** Reasons and how many gave each, most first: the bars, in order. */
    private function counts(): Collection
    {
        return $this->resultsQuery()
            ->where('follow_ups.purchased', false)
            ->whereNotNull('follow_ups.no_purchase_reason')
            ->selectRaw('follow_ups.no_purchase_reason as reason, count(*) as total')
            ->groupBy('follow_ups.no_purchase_reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason');
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'datasets' => [[
                'label' => 'تعداد مشتری',
                'data' => $counts->values()->all(),
                'backgroundColor' => ChartStyle::BLUE,
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 22,
            ]],
            'labels' => $counts->keys()->map(fn (string $reason): string => NoPurchaseReason::from($reason)->getLabel())->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return ChartStyle::clickable(ChartStyle::options([
            'indexAxis' => 'y',
            // the bars lie down, so the one under the pointer is found by its row, not its column
            'interaction' => ['mode' => 'index', 'axis' => 'y', 'intersect' => false],
            'plugins' => ['legend' => ['display' => false]],
            // right to left: reason names on the right, bars growing leftward from them
            'scales' => [
                'x' => ['reverse' => true, 'grid' => ['display' => true, 'color' => ChartStyle::GRID], 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y' => ['position' => 'right', 'grid' => ['display' => false]],
            ],
        ]));
    }
}
