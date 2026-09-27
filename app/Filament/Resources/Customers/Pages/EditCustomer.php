<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return 'پرونده '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            CustomerResource::deleteAction(DeleteAction::make()),
        ];
    }
}
