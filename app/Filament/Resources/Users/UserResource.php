<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
 * Who can sign in. Managers only. Accounts are switched off, not deleted, so the calls they recorded
 * keep their name.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'مدیریت';

    protected static ?string $modelLabel = 'کاربر';

    protected static ?string $pluralModelLabel = 'کاربران';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    /** Accounts are switched off, never deleted, so the calls they recorded keep their name. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('نام')->required()->maxLength(255),
            TextInput::make('email')->label('ایمیل (برای ورود)')->email()->required()->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('password')
                ->label('رمز عبور')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'برای تغییر ندادن، خالی بگذارید.' : null),
            // a manager cannot lock themselves out: neither by taking their own role away nor by
            // switching their own account off. Another manager can still do both.
            Select::make('role')
                ->label('نقش')
                ->options(UserRole::class)
                ->default(UserRole::Secretary)
                ->required()
                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                ->helperText(fn (?User $record): ?string => $record?->is(auth()->user()) ? 'نقش خودتان را نمی توانید عوض کنید.' : null),
            Toggle::make('is_active')
                ->label('فعال')
                ->default(true)
                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام')->searchable(),
                TextColumn::make('email')->label('ایمیل'),
                TextColumn::make('role')->label('نقش')->badge(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
