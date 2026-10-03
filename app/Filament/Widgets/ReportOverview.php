<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\ReadsReportFilters;
use App\Support\Persian;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The period's headline figures, for managers.
 */
class ReportOverview extends StatsOverviewWidget
{
    use ReadsReportFilters;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::managerOnly();
    }

    protected function getHeading(): ?string
    {
        return 'گزارش '.Dashboard::PERIODS[$this->periodDays()];
    }

    protected function getStats(): array
    {
        $calls = $this->callsQuery()->count();
        $results = $this->resultsQuery();
        $reached = (clone $results)->count();
        $purchased = (clone $results)->where('follow_ups.purchased', true)->count();
        $satisfaction = (clone $results)->avg('follow_ups.overall_satisfaction');

        $percent = fn (int $part, int $whole): string => $whole > 0 ? Persian::digits(round($part / $whole * 100)).'٪' : '—';

        return [
            Stat::make('تماس های ورودی', Persian::digits($calls))
                ->description('مشتریانی که به فروش ارجاع شدند'),

            Stat::make('نتیجه ی ثبت شده', Persian::digits($reached))
                ->description($calls > 0 ? $percent($reached, $calls).' از تماس ها پیگیری و نتیجه ثبت شد' : 'هنوز تماسی نیست'),

            Stat::make('نرخ خرید', $percent($purchased, $reached))
                ->description(Persian::digits($purchased).' خرید از '.Persian::digits($reached).' مشتری پیگیری شده')
                ->color('success'),

            Stat::make('میانگین رضایت کلی', $satisfaction ? Persian::digits(number_format($satisfaction, 1)).' از ۵' : '—')
                ->description('از ۱ (خیلی ناراضی) تا ۵ (خیلی راضی)'),
        ];
    }
}
