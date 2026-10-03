<?php

namespace App\Filament\Widgets;

use App\Enums\AcquisitionSource;
use App\Filament\Widgets\Concerns\ChartStyle;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Support\Persian;
use Filament\Widgets\ChartWidget;

/**
 * How the period's callers found Tamin Falat, as a share of all calls, with the source that
 * converted best underneath. Every source keeps its own colour and is named with its percentage.
 */
class AcquisitionSourceChart extends ChartWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 6;

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function getHeading(): string
    {
        return 'نحوه آشنایی مشتریان';
    }

    public function getDescription(): ?string
    {
        // purchases over referrals; a source needs three referrals and a purchase to be named
        $best = collect($this->figures())
            ->filter(fn (array $f): bool => $f['referred'] >= 3 && $f['purchased'] > 0)
            ->sortByDesc(fn (array $f): float => $f['purchased'] / $f['referred'])
            ->first();

        return $best
            ? 'بیشترین نرخ خرید: '.$best['label'].' ('.Persian::digits(round($best['purchased'] / $best['referred'] * 100)).'٪ از مشتریان ارجاع شده)'
            : 'سهم هر روش از تماس های این بازه';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $figures = $this->figures();
        $total = array_sum(array_column($figures, 'calls'));

        return [
            'datasets' => [ChartStyle::doughnutDataset(
                array_column($figures, 'calls'),
                array_column($figures, 'color'),
            )],
            'labels' => array_map(
                fn (array $f): string => $f['label'].' · '.($total > 0 ? Persian::digits(round($f['calls'] / $total * 100)).'٪' : '۰٪'),
                $figures,
            ),
        ];
    }

    protected function getOptions(): array
    {
        return ChartStyle::doughnutOptions();
    }

    /**
     * Per source, in the enum's fixed order: calls, the referred ones among them, and purchases.
     *
     * @return list<array{label: string, color: string, calls: int, referred: int, purchased: int}>
     */
    private function figures(): array
    {
        $calls = $this->callsQuery()
            ->whereNotNull('calls.source')
            ->selectRaw('calls.source as source, count(*) as total')
            ->groupBy('calls.source')
            ->pluck('total', 'source');

        $referred = $this->referralsQuery()
            ->whereNotNull('calls.source')
            ->selectRaw('calls.source as source, count(*) as total')
            ->groupBy('calls.source')
            ->pluck('total', 'source');

        $bought = $this->purchasesQuery()
            ->whereNotNull('calls.source')
            ->selectRaw('calls.source as source, count(*) as total')
            ->groupBy('calls.source')
            ->pluck('total', 'source');

        $figures = [];
        foreach (AcquisitionSource::cases() as $source) {
            $figures[] = [
                'label' => $source->getLabel(),
                'color' => $source->chartColor(),
                'calls' => (int) ($calls[$source->value] ?? 0),
                'referred' => (int) ($referred[$source->value] ?? 0),
                'purchased' => (int) ($bought[$source->value] ?? 0),
            ];
        }

        return $figures;
    }
}
