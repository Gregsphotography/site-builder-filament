<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Albums;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Components\Albums\AlbumLibrary;

final class CreateAlbum extends CreateRecord
{
    use WritesAlbums;

    protected static string $resource = AlbumResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->writeAlbum(fn () => app(AlbumLibrary::class)->create($data));
    }

    protected function getRedirectUrl(): string
    {
        return AlbumResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Album draft created';
    }
}
