<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Albums;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use SiteBuilder\Components\Albums\Album;
use SiteBuilder\Components\Albums\AlbumLibrary;

final class EditAlbum extends EditRecord
{
    use WritesAlbums;

    protected static string $resource = AlbumResource::class;

    #[Locked]
    public int $writeVersion = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->writeVersion = $data['write_version'];

        return $this->getRecord()->draft;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->record = $this->writeAlbum(fn () => app(AlbumLibrary::class)->saveDraft($record, $data, $this->writeVersion));
        $this->writeVersion = $this->record->write_version;

        return $this->record;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Save draft'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('View published album')->openUrlInNewTab()
                ->url(fn (): ?string => AlbumResource::publicUrl($this->getRecord()))
                ->visible(fn (): bool => AlbumResource::publicUrl($this->getRecord()) !== null),
            Action::make('publish')->label('Publish album')->action(fn () => $this->publish())
                ->visible(fn (): bool => Gate::forUser(Filament::auth()->user())->allows('publish', $this->getRecord())),
            Action::make('unpublish')->label('Unpublish album')->color('danger')->requiresConfirmation()
                ->modalDescription('The album will disappear from its public URL and every page where it is embedded. You can publish it again from this draft.')
                ->modalSubmitActionLabel('Unpublish album')->action(fn () => $this->unpublish())
                ->visible(fn (): bool => $this->getRecord()->published_at !== null && Gate::forUser(Filament::auth()->user())->allows('publish', $this->getRecord())),
        ];
    }

    protected function unpublish(): void
    {
        $this->authorizeAccess();
        $record = $this->getRecord();
        Gate::forUser(Filament::auth()->user())->authorize('publish', $record);
        $this->record = $this->writeAlbum(fn () => app(AlbumLibrary::class)->unpublish($record, $this->writeVersion));
        $this->writeVersion = $this->record->write_version;
        Notification::make()->title('Album unpublished')->success()->send();
    }

    protected function publish(): void
    {
        $this->authorizeAccess();
        $record = $this->getRecord();
        Gate::forUser(Filament::auth()->user())->authorize('publish', $record);
        $data = $this->form->getState();
        $albums = app(AlbumLibrary::class);
        $this->record = $this->writeAlbum(fn () => $record->getConnection()->transaction(function () use ($albums, $record, $data): Album {
            $saved = $albums->saveDraft($record, $data, $this->writeVersion);

            return $albums->publish($saved, $saved->write_version);
        }));
        $this->writeVersion = $this->record->write_version;
        $this->rememberData();
        Notification::make()->title('Album published')->success()->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Album draft saved';
    }
}
