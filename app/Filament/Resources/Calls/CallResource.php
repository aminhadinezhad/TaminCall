<?php

namespace App\Filament\Resources\Calls;

use App\Filament\Resources\Calls\Pages\CreateCall;
use App\Filament\Resources\Calls\Pages\EditCall;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Calls\RelationManagers\FollowUpsRelationManager;
use App\Filament\Resources\Calls\Schemas\CallForm;
use App\Filament\Resources\Calls\Tables\CallsTable;
use App\Models\Call;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CallResource extends Resource
{
    protected static ?string $model = Call::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'تماس ها';

    protected static ?string $modelLabel = 'تماس';

    protected static ?string $pluralModelLabel = 'تماس ها';

    protected static ?string $navigationLabel = 'تماس ها و پیگیری';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CallForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FollowUpsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalls::route('/'),
            'create' => CreateCall::route('/create'),
            'edit' => EditCall::route('/{record}/edit'),
        ];
    }

    /** Calls due today (and overdue ones), on the menu item. */
    public static function getNavigationBadge(): ?string
    {
        $due = Call::query()->dueBy(today())->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'پیگیری های امروز';
    }

    /**
     * A call is never deleted on its own, by anyone: it goes, for good, with its customer.
     * Checked here as well as by the missing button, so no request can do it either.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
