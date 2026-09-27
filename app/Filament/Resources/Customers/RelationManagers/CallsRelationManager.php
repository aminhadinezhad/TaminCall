<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Calls\CallResource;
use App\Models\Call;
use App\Support\Persian;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's calls, newest first; each opens the call's own page.
 */
class CallsRelationManager extends RelationManager
{
    protected static string $relationship = 'calls';

    protected static ?string $title = 'تماس های این مشتری';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['salesAgent', 'result']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('تاریخ')->formatStateUsing(fn ($state): string => Persian::date($state)),
                TextColumn::make('request')->label('درخواست')->limit(50)->tooltip(fn (Call $record): string => $record->request),
                TextColumn::make('salesAgent.name')->label('کارشناس')->placeholder('—'),
                TextColumn::make('status')->label('وضعیت')->badge(),
                IconColumn::make('result.purchased')->label('خرید کرد؟')->boolean()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('جزئیات')
                    ->url(fn (Call $record): string => CallResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPhone)
            ->emptyStateHeading('برای این مشتری تماسی ثبت نشده')
            ->emptyStateDescription('تماس های بعدی این مشتری اینجا می آیند.');
    }
}
