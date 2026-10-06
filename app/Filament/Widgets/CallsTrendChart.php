<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ChartStyle;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Support\Persian;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

/**
 * Calls received and, of those, how many ended in a purchase: per day for short periods, per week
 * for three months, per Jalali month for a year.
 */
class CallsTrendChart extends ChartWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    public function getHeading(): string
    {
        return 'روند تماس ها و خرید';
    }

    public function getDescription(): string
    {
        return 'خرید به روزِ تماس اولیه ی مشتری نسبت داده می شود.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();
        $calls = array_fill(0, count($buckets), 0);
        $purchases = array_fill(0, count($buckets), 0);

        foreach ($this->callsQuery()->pluck('calls.created_at') as $at) {
            $calls[$this->bucketIndex($buckets, Carbon::parse($at))]++;
        }

        foreach ($this->resultsQuery()->where('follow_ups.purchased', true)->pluck('calls.created_at') as $at) {
            $purchases[$this->bucketIndex($buckets, Carbon::parse($at))]++;
        }

        $line = fn (string $color): array => [
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 2,
            'pointRadius' => count($buckets) > 31 ? 0 : 3,
            'pointHoverRadius' => 5,
            'tension' => 0.25,
        ];

        return [
            'datasets' => [
                ['label' => 'تماس های ورودی', 'data' => $calls] + $line(ChartStyle::BLUE),
                ['label' => 'منجر به خرید', 'data' => $purchases] + $line(ChartStyle::ORANGE),
            ],
            'labels' => array_column($buckets, 'label'),
        ];
    }

    /** A point: the calls received in that day, week or month; on the orange line, those that ended in a purchase. */
    public function openDetails(int $dataset, int $index): void
    {
        $buckets = $this->buckets();

        if (! isset($buckets[$index])) {
            return;
        }

        $this->redirect($this->reportLink([
            'show' => $dataset === 1 ? 'purchases' : null,
            'from' => $buckets[$index]['start']->toDateString(),
            'to' => ($buckets[$index + 1]['start'] ?? today()->addDay())->toDateString(),
        ]));
    }

    protected function getOptions(): RawJs
    {
        return ChartStyle::clickable(ChartStyle::options());
    }

    /**
     * @return list<array{start: Carbon, label: string}> consecutive buckets covering the period
     */
    private function buckets(): array
    {
        $days = $this->periodDays();
        $start = $this->periodStart();
        $buckets = [];

        if ($days <= 31) {
            for ($d = $start->copy(); $d->lte(today()); $d->addDay()) {
                $buckets[] = ['start' => $d->copy(), 'label' => Persian::digits(Jalalian::fromCarbon($d)->format('m/d'))];
            }
        } elseif ($days <= 120) {
            for ($d = $start->copy(); $d->lte(today()); $d->addWeek()) {
                $buckets[] = ['start' => $d->copy(), 'label' => Persian::digits(Jalalian::fromCarbon($d)->format('m/d'))];
            }
        } else {
            $month = Jalalian::fromCarbon($start)->getFirstDayOfMonth();
            while ($month->toCarbon()->lte(today())) {
                $buckets[] = ['start' => $month->toCarbon()->max($start), 'label' => Persian::plain($month->format('%B'))];
                $month = $month->addMonths(1);
            }
        }

        return $buckets;
    }

    private function bucketIndex(array $buckets, Carbon $at): int
    {
        for ($i = count($buckets) - 1; $i > 0; $i--) {
            if ($at->gte($buckets[$i]['start'])) {
                return $i;
            }
        }

        return 0;
    }
}
