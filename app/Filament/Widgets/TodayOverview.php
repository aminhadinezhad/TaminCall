<?php

namespace App\Filament\Widgets;

use App\Enums\CallStatus;
use App\Filament\Pages\ReportCalls;
use App\Filament\Resources\Calls\CallResource;
use App\Models\Call;
use App\Support\Persian;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The secretary's day at a glance: who to call back today, what is overdue, what came in today.
 * Shown to everyone and not affected by the report filters.
 */
class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return 'امروز · '.Persian::dayName(today());
    }

    protected function getStats(): array
    {
        $dueToday = Call::query()->where('status', CallStatus::AwaitingFollowUp)->whereDate('follow_up_on', today())->count();
        $overdue = Call::query()->where('status', CallStatus::AwaitingFollowUp)->whereDate('follow_up_on', '<', today())->count();
        $receivedToday = Call::query()->whereDate('created_at', today())->count();

        return [
            Stat::make('پیگیری های امروز', Persian::digits($dueToday))
                ->description('مشتریانی که امروز باید با آن ها تماس گرفت')
                ->descriptionIcon(Heroicon::OutlinedPhoneArrowUpRight)
                ->color('primary')
                ->url(CallResource::getUrl('index', ['tab' => 'today'])),

            Stat::make('عقب افتاده', Persian::digits($overdue))
                ->description($overdue > 0 ? 'پیگیری هایی که از روزهای قبل مانده' : 'هیچ پیگیری عقب افتاده ای نیست')
                ->descriptionIcon($overdue > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(CallResource::getUrl('index', ['tab' => 'today'])),

            Stat::make('تماس های امروز', Persian::digits($receivedToday))
                ->description('تماس های ورودی ثبت شده امروز')
                ->descriptionIcon(Heroicon::OutlinedPhoneArrowDownLeft)
                ->url($this->receivedTodayUrl()),
        ];
    }

    /**
     * Today's calls: for a manager exactly those, on the report details page (as today's point on
     * the trend chart opens); a secretary, who can not open that page, keeps the calls list.
     */
    private function receivedTodayUrl(): string
    {
        if (! ReportCalls::canAccess()) {
            return CallResource::getUrl('index', ['tab' => 'all']);
        }

        return ReportCalls::link(['from' => today()->toDateString(), 'to' => today()->addDay()->toDateString()]);
    }
}
