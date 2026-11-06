<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Create page')->url(PageResource::getUrl('create'))->visible(PageResource::canCreate())];
    }
}
