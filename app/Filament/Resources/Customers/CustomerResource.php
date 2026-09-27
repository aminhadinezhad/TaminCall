<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Calls\Schemas\CallForm;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\CallsRelationManager;
use App\Models\Customer;
use App\Support\Persian;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'تماس ها';

    protected static ?string $modelLabel = 'مشتری';

    protected static ?string $pluralModelLabel = 'مشتریان';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->components(CallForm::customerFields()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('نام')->searchable()->sortable(),
                TextColumn::make('phone')->label('شماره')->formatStateUsing(fn ($state): string => Persian::digits($state))->copyable()->searchable(),
                // the badge takes its colour from the enum, the same colour as the slice in the report
                TextColumn::make('type')->label('نوع مشتری')->badge()->placeholder('—'),
                TextColumn::make('company')->label('شرکت / سازمان')->placeholder('—')->searchable(),
                TextColumn::make('calls_count')->label('تعداد تماس')->counts('calls')->formatStateUsing(fn ($state): string => Persian::digits($state))->sortable(),
                TextColumn::make('created_at')->label('اولین تماس')->formatStateUsing(fn ($state): string => Persian::date($state))->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()->label('پرونده'),
                self::deleteAction(DeleteAction::make()),
            ])
            // an empty list says why it is empty and what to do next
            ->emptyStateIcon(fn (HasTable $livewire): Heroicon => filled($livewire->getTableSearch())
                ? Heroicon::OutlinedMagnifyingGlass
                : Heroicon::OutlinedUsers)
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch())
                ? 'نتیجه ای پیدا نشد'
                : 'هنوز مشتری ای ثبت نشده')
            // only a search with no match adds a hint under the heading
            ->emptyStateDescription(fn (HasTable $livewire): ?string => filled($livewire->getTableSearch())
                ? 'با نام، شرکت یا شماره ی دیگری جستجو کنید.'
                : null);
    }

    /**
     * Deleting a customer is for good: they go with all their calls and follow-ups (see Customer).
     * The button (in the list and in the customer's file) says so before it is pressed.
     */
    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->visible(fn (): bool => auth()->user()->isManager())
            ->modalHeading(fn (Customer $record): string => 'حذف '.$record->name)
            ->modalDescription('این مشتری با همه تماس ها و پیگیری هایش برای همیشه پاک می شود و برگرداندنی نیست.')
            ->modalSubmitActionLabel('حذف برای همیشه');
    }

    /**
     * Deleting customers is a manager's job. Checked here as well as on the buttons, so no request
     * can do it either.
     */
    public static function canDelete($record): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isManager() ?? false;
    }

    public static function getRelations(): array
    {
        return [
            CallsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'company'];
    }
}
