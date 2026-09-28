<?php

namespace App\Filament\Resources\Calls\Actions;

use App\Enums\CallStatus;
use App\Enums\NoPurchaseReason;
use App\Filament\Forms\Components\StarRating;
use App\Models\Call;
use App\Support\Persian;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The happy call: the secretary rings the customer back and records what happened. Shared by the
 * calls table and the call's own page.
 */
class RecordFollowUpAction
{
    public const SATISFACTION = [
        1 => 'خیلی ناراضی',
        2 => 'ناراضی',
        3 => 'معمولی',
        4 => 'راضی',
        5 => 'خیلی راضی',
    ];

    public const SATISFACTION_COLORS = [
        1 => 'danger',
        2 => 'danger',
        3 => 'gray',
        4 => 'success',
        5 => 'success',
    ];

    /** Another follow-up after this one, in days (0: none needed). */
    public const CALL_AGAIN = [
        0 => 'نیازی نیست',
        1 => 'فردا',
        3 => 'سه روز بعد',
        7 => 'یک هفته بعد',
    ];

    public static function make(): Action
    {
        return Action::make('recordFollowUp')
            ->label('ثبت نتیجه')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('primary')
            ->visible(fn (Call $record): bool => $record->status === CallStatus::AwaitingFollowUp)
            ->modalHeading(fn (Call $record): string => 'پیگیری با '.$record->customer->name)
            ->modalDescription(fn (Call $record): string => 'شماره: '.(Persian::digits($record->customer->numbers()) ?: '—')
                .' · کارشناس: '.($record->salesAgent?->name ?? '—')
                .' · درخواست: '.str($record->request)->limit(80))
            ->modalSubmitActionLabel('ثبت')
            ->modalWidth('2xl')
            ->schema([
                ToggleButtons::make('answered')
                    ->label('مشتری پاسخ داد؟')
                    ->boolean('پاسخ داد', 'پاسخ نداد')
                    ->grouped()
                    ->required()
                    ->live(),

                ToggleButtons::make('purchased')
                    ->label('خرید کرد؟')
                    ->boolean('بله، خرید کرد', 'خیر')
                    ->grouped()
                    ->required(fn (Get $get): bool => (bool) $get('answered'))
                    ->visible(fn (Get $get): bool => (bool) $get('answered'))
                    ->live(),

                Select::make('no_purchase_reason')
                    ->label('چرا خرید نکرد؟')
                    ->options(NoPurchaseReason::class)
                    ->required(fn (Get $get): bool => $get('answered') && $get('purchased') === false)
                    ->visible(fn (Get $get): bool => $get('answered') && $get('purchased') === false),

                StarRating::make('agent_satisfaction')
                    ->label('رضایت از برخورد کارشناس فروش')
                    ->levels(self::SATISFACTION)
                    ->required(fn (Get $get): bool => (bool) $get('answered'))
                    ->visible(fn (Get $get): bool => (bool) $get('answered')),

                StarRating::make('overall_satisfaction')
                    ->label('رضایت کلی از تامین فلات')
                    ->levels(self::SATISFACTION)
                    ->required(fn (Get $get): bool => (bool) $get('answered'))
                    ->visible(fn (Get $get): bool => (bool) $get('answered')),

                Textarea::make('notes')
                    ->label('توضیحات')
                    ->placeholder('هر نکته ای که مشتری گفت')
                    ->rows(3),

                ToggleButtons::make('call_again_in')
                    ->label('پیگیری دوباره')
                    ->helperText('مثلاً اگر مشتری هنوز تصمیم نگرفته است.')
                    ->options(self::CALL_AGAIN)
                    ->default(0)
                    ->inline()
                    ->visible(fn (Get $get): bool => (bool) $get('answered')),
            ])
            ->action(function (Call $record, array $data): void {
                $record->recordFollowUp($data, auth()->user(), (int) ($data['call_again_in'] ?? 0) ?: null);
                $record->refresh();

                $message = match (true) {
                    ! $data['answered'] && $record->status === CallStatus::Unreachable => 'مشتری بعد از '.Persian::digits(Call::MAX_UNANSWERED_ATTEMPTS).' بار تماس پاسخ نداد و پرونده بسته شد.',
                    ! $data['answered'] => 'پاسخ نداد؛ '.Persian::dayName($record->follow_up_on).' دوباره در فهرست پیگیری است.',
                    $record->status === CallStatus::AwaitingFollowUp => 'نتیجه ثبت شد؛ پیگیری بعدی '.Persian::dayName($record->follow_up_on).'.',
                    default => 'نتیجه تماس ثبت شد.',
                };

                Notification::make()->title($message)->success()->send();
            });
    }
}
