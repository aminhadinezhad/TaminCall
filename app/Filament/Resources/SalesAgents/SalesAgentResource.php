<?php

namespace App\Filament\Resources\SalesAgents;

use App\Filament\Resources\SalesAgents\Pages\ManageSalesAgents;
use App\Models\SalesAgent;
use App\Support\Persian;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The sales staff customers are referred to. Managers only. An agent who leaves is switched off
 * rather than deleted, so their past calls and figures stay in the reports.
 */
class SalesAgentResource extends Resource
{
    protected static ?string $model = SalesAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'مدیریت';

    protected static ?string $modelLabel = 'کارشناس فروش';

    protected static ?string $pluralModelLabel = 'کارشناسان فروش';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    /** Only an agent nobody was referred to; one with calls is switched off instead. */
    public static function canDelete($record): bool
    {
        return (auth()->user()?->isManager() ?? false) && ! $record->calls()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('نام')->required()->maxLength(255),
            TextInput::make('phone')->label('شماره داخلی / موبایل')->maxLength(20),
            Toggle::make('is_active')
                ->label('فعال')
                ->helperText('کارشناس غیرفعال در فرم ارجاع دیده نمی شود، ولی آمار گذشته اش می ماند.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام')->searchable(),
                TextColumn::make('phone')->label('شماره')->placeholder('—'),
                TextColumn::make('calls_count')->label('تعداد ارجاع')->counts('calls')->formatStateUsing(fn ($state): string => Persian::digits($state))->sortable(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (SalesAgent $record): bool => ! $record->calls()->exists()),
            ])
            // an empty list says what to do next
            ->emptyStateIcon(Heroicon::OutlinedBriefcase)
            ->emptyStateHeading('هنوز کارشناسی ثبت نشده')
            ->emptyStateDescription(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalesAgents::route('/'),
        ];
    }
}
