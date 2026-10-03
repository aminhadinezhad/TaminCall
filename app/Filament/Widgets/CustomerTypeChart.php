<?php

namespace App\Filament\Widgets;

use App\Enums\CustomerType;
use App\Filament\Widgets\Concerns\ChartStyle;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Support\Persian;
use Filament\Widgets\ChartWidget;

/**
 * Individual vs legal customers among the period's calls, with each group's purchase rate
 * underneath, so the two can be compared.
 */
class CustomerTypeChart extends ChartWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 7;

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function getHeading(): string
    {
        return 'نوع مشتریان';
    }

    public function getDescription(): ?string
    {
        // purchases over referrals, as everywhere in the reports
        $parts = [];
        foreach ($this->figures() as $f) {
            if ($f['referred'] > 0) {
                $parts[] = $f['label'].' '.Persian::digits(round($f['purchased'] / $f['referred'] * 100)).'٪';
            }
        }

        return $parts ? 'نرخ خرید: '.implode(' · ', $parts) : 'سهم مشتریان حقیقی و حقوقی از تماس های این بازه';
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
                fn (array $f): string => $f['label'].' · '.($total > 0 ? Persian::digits(round($f['calls'] / $total * 100)).'٪' : '۰٪')
                    .' ('.Persian::digits($f['calls']).' تماس)',
                $figures,
            ),
        ];
    }

    protected function getOptions(): array
    {
        return ChartStyle::doughnutOptions();
    }

    /**
     * Per customer type: calls, the referred ones among them, and purchases.
     *
     * @return list<array{label: string, color: string, calls: int, referred: int, purchased: int}>
     */
    private function figures(): array
    {
        $byType = fn ($query) => $query
            ->join('customers', 'customers.id', '=', 'calls.customer_id')
            ->whereNotNull('customers.type')
            ->selectRaw('customers.type as type, count(*) as total')
            ->groupBy('customers.type')
            ->pluck('total', 'type');

        $calls = $byType($this->callsQuery());
        $referred = $byType($this->referralsQuery());
        $bought = $byType($this->purchasesQuery());

        $figures = [];
        foreach (CustomerType::cases() as $type) {
            $figures[] = [
                'label' => $type->getLabel(),
                'color' => $type->chartColor(),
                'calls' => (int) ($calls[$type->value] ?? 0),
                'referred' => (int) ($referred[$type->value] ?? 0),
                'purchased' => (int) ($bought[$type->value] ?? 0),
            ];
        }

        return $figures;
    }
}
