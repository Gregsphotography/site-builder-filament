<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Albums;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAlbums extends ListRecords
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create album')];
    }
}
