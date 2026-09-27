<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Dashboard;
use App\Models\Call;
use App\Models\FollowUp;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The dashboard's period and sales-agent filters, turned into queries every report widget shares.
 *
 * All figures are about the calls received in the period. A call's "result" is its last answered
 * follow-up, so a customer called twice (undecided, then bought) counts once, as a purchase.
 */
trait ReadsReportFilters
{
    use InteractsWithPageFilters;

    protected static function managerOnly(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    protected function periodDays(): int
    {
        $days = (int) ($this->pageFilters['period'] ?? Dashboard::DEFAULT_PERIOD);

        return array_key_exists($days, Dashboard::PERIODS) ? $days : Dashboard::DEFAULT_PERIOD;
    }

    /** First day of the period; the period ends today. */
    protected function periodStart(): Carbon
    {
        return today()->subDays($this->periodDays() - 1);
    }

    protected function salesAgentId(): ?int
    {
        return filled($this->pageFilters['sales_agent_id'] ?? null) ? (int) $this->pageFilters['sales_agent_id'] : null;
    }

    /** Calls received in the period, for the chosen agent. */
    protected function callsQuery(): Builder
    {
        return Call::query()
            ->where('calls.created_at', '>=', $this->periodStart())
            ->when($this->salesAgentId(), fn (Builder $q, int $id) => $q->where('calls.sales_agent_id', $id));
    }

    /** The result (last answered follow-up) of each call in callsQuery(). */
    protected function resultsQuery(): Builder
    {
        return FollowUp::query()
            ->join('calls', 'calls.id', '=', 'follow_ups.call_id')
            ->where('follow_ups.answered', true)
            ->whereRaw('follow_ups.id = (select max(f2.id) from follow_ups f2 where f2.call_id = follow_ups.call_id and f2.answered = 1)')
            ->where('calls.created_at', '>=', $this->periodStart())
            ->when($this->salesAgentId(), fn (Builder $q, int $id) => $q->where('calls.sales_agent_id', $id));
    }
}
