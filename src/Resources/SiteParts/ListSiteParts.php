<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListSiteParts extends ListRecords
{
    protected static string $resource = SitePartResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Create site part')->url(SitePartResource::getUrl('create'))->visible(SitePartResource::canCreate())];
    }
}
