<?php

namespace App\Models;

use App\Enums\AcquisitionSource;
use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable(['customer_id', 'sales_agent_id', 'received_by', 'request', 'source', 'notes', 'status', 'follow_up_on', 'unanswered_attempts'])]
class Call extends Model
{
    use HasFactory;

    /** After this many unanswered follow-up calls the customer is marked unreachable. */
    public const MAX_UNANSWERED_ATTEMPTS = 3;

    protected $attributes = [
        'status' => 'awaiting',
        'unanswered_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'source' => AcquisitionSource::class,
            'follow_up_on' => 'date',
            'unanswered_attempts' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /** The follow-up that reached the customer (the last one, if a second was scheduled). */
    public function result(): HasOne
    {
        return $this->hasOne(FollowUp::class)->where('answered', true)->latestOfMany();
    }

    /** Calls the secretary should make today, including any left over from earlier days. */
    public function scopeDueBy(Builder $query, Carbon $day): void
    {
        $query->where('status', CallStatus::AwaitingFollowUp)->whereDate('follow_up_on', '<=', $day);
    }

    /**
     * The first working day at least $days after $from. Friday is the weekend, so it is skipped.
     */
    public static function workingDayAfter(int $days, ?Carbon $from = null): Carbon
    {
        $day = ($from ?? today())->copy()->addDays(max(1, $days));

        while ($day->isFriday()) {
            $day->addDay();
        }

        return $day;
    }

    /**
     * Records one follow-up attempt and moves the call on.
     *
     * - No answer: tried again the next working day, until MAX_UNANSWERED_ATTEMPTS, then unreachable.
     * - Answered: the result is kept; the call is done, unless $callAgainInDays asks for another
     *   follow-up (say the customer is still deciding), which puts it back in the queue for that day.
     *
     * @param  array{answered: bool, purchased?: ?bool, no_purchase_reason?: ?string, agent_satisfaction?: ?int, overall_satisfaction?: ?int, notes?: ?string}  $data
     */
    public function recordFollowUp(array $data, ?User $by = null, ?int $callAgainInDays = null): FollowUp
    {
        return DB::transaction(function () use ($data, $by, $callAgainInDays) {
            $answered = (bool) $data['answered'];
            $purchased = $answered ? (bool) ($data['purchased'] ?? false) : null;

            $followUp = $this->followUps()->create([
                'user_id' => $by?->id,
                'answered' => $answered,
                'purchased' => $purchased,
                'no_purchase_reason' => $answered && ! $purchased ? ($data['no_purchase_reason'] ?? null) : null,
                'agent_satisfaction' => $answered ? ($data['agent_satisfaction'] ?? null) : null,
                'overall_satisfaction' => $answered ? ($data['overall_satisfaction'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! $answered) {
                $this->unanswered_attempts++;

                if ($this->unanswered_attempts >= self::MAX_UNANSWERED_ATTEMPTS) {
                    $this->status = CallStatus::Unreachable;
                } else {
                    $this->follow_up_on = self::workingDayAfter(1);
                }
            } elseif ($callAgainInDays) {
                $this->status = CallStatus::AwaitingFollowUp;
                $this->follow_up_on = self::workingDayAfter($callAgainInDays);
            } else {
                $this->status = CallStatus::Done;
            }

            $this->save();

            return $followUp;
        });
    }
}
