<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Installation\SetupAuthorization;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Filament\Support\OptionalComponents;

/** Explicitly enabled local-library screen. Host media adapters continue to take priority. */
final class MediaLibrary extends Page
{
    protected static ?string $navigationLabel = 'Media library';

    protected static ?string $title = 'Media library';

    protected string $view = 'site-builder-filament::pages.media-library';

    #[Locked]
    public ?string $selectedAsset = null;

    #[Locked]
    public int $assetVersion = 0;

    public array $data = [];

    public static function canAccess(): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && (app(SetupAuthorization::class)->media($actor, 'view') ?? Gate::forUser($actor)->allows('viewAny', ContentPage::class))
            && in_array(self::class, Filament::getCurrentPanel()->getPages(), true)
            && app(OptionalComponents::class)->media()?->available() === true;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill(['kind' => 'image', 'locale' => app(LocaleCatalog::class)->default()]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $locales = app(LocaleCatalog::class)->all();

        return $schema->components([
            Select::make('kind')->label('Media type')->options(['image' => 'Image', 'video' => 'Video (MP4 or WebM)', 'track' => 'Captions (WebVTT)'])->default('image')->required()->live()
                ->afterStateUpdated(function (): void {
                    $this->data['upload'] = null;
                })
                ->visible(fn (): bool => $this->selectedAsset === null),
            FileUpload::make('upload')->key(fn (): string => 'upload-'.($this->data['kind'] ?? 'image'))->label('File')->acceptedFileTypes(fn (): array => match ($this->data['kind'] ?? 'image') {
                'video' => ['video/mp4', 'video/webm'], 'track' => ['text/vtt', 'text/plain', 'application/octet-stream'], default => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
            })->maxSize(fn (): int => (int) config('site-builder-components.media.'.match ($this->data['kind'] ?? 'image') {
                'video' => 'video_max_kilobytes', 'track' => 'track_max_kilobytes', default => 'max_kilobytes'
            }, match ($this->data['kind'] ?? 'image') {
                'video' => 102400, 'track' => 1024, default => 10240
            }))
                ->helperText(fn (): string => 'Maximum on this server: '.number_format((app(OptionalComponents::class)->media()->uploadReadiness($this->data['kind'] ?? 'image')['effective_kilobytes']) / 1024, 1).' MiB. Videos must already be browser-ready.')
                ->storeFiles(false)->required(fn (): bool => $this->selectedAsset === null)->visible(fn (): bool => $this->selectedAsset === null),
            TextInput::make('name')->maxLength(255)->visible(fn (): bool => $this->selectedAsset === null),
            Select::make('locale')->label('Alternative text language')->options(array_combine($locales, $locales))->required()
                ->live()->afterStateUpdated(fn (): mixed => $this->fillAssetAlt())->visible(fn (): bool => ($this->data['kind'] ?? 'image') === 'image'),
            TextInput::make('alt')->label('Alternative text')->maxLength(1000)->helperText('Describe the image in this language. Decorative use can be selected separately on a page.')->visible(fn (): bool => ($this->data['kind'] ?? 'image') === 'image'),
        ])->statePath('data')->disabled(fn (): bool => ! $this->canManage());
    }

    public function save(): void
    {
        $this->authorizeManage();
        $data = $this->form->getState();
        if (($data['kind'] ?? 'image') === 'image' && ! app(LocaleCatalog::class)->has($data['locale'] ?? '')) {
            throw ValidationException::withMessages(['data.locale' => 'Choose a configured language.']);
        }
        $media = app(OptionalComponents::class)->media();
        if ($this->selectedAsset !== null) {
            try {
                $media->updateAlt($this->selectedAsset, [$data['locale'] => $data['alt'] ?? ''], $this->assetVersion);
            } catch (WriteConflict) {
                throw ValidationException::withMessages(['data.alt' => 'This image was changed by another editor. Your text is retained. Copy it, then click Edit alternative text on the image to reload before retrying.']);
            }
        } else {
            if (! ($data['upload'] ?? null) instanceof UploadedFile) {
                throw ValidationException::withMessages(['data.upload' => 'Choose a file to upload.']);
            }
            match ($data['kind'] ?? 'image') {
                'image' => $media->store($data['upload'], [$data['locale'] => $data['alt'] ?? ''], $data['name'] ?: null),
                'video' => $media->storeVideo($data['upload'], $data['name'] ?: null),
                'track' => $media->storeTrack($data['upload'], $data['name'] ?: null),
                default => throw ValidationException::withMessages(['data.kind' => 'Choose an available media type.']),
            };
        }
        $this->selectedAsset = null;
        $this->assetVersion = 0;
        $this->form->fill(['kind' => 'image', 'locale' => app(LocaleCatalog::class)->default()]);
        Notification::make()->title('Media saved')->success()->send();
    }

    public function editAsset(string $id): void
    {
        $this->authorizeManage('update');
        $asset = app(OptionalComponents::class)->media()->entries('all')->firstWhere('id', $id) ?? abort(404);
        $this->selectedAsset = (string) $asset->id;
        $this->assetVersion = (int) $asset->write_version;
        $locale = app(LocaleCatalog::class)->default();
        $this->form->fill(['locale' => $locale, 'alt' => $asset->alt[$locale] ?? '']);
    }

    public function newUpload(): void
    {
        $this->authorizeManage('upload');
        $this->selectedAsset = null;
        $this->assetVersion = 0;
        $this->form->fill(['kind' => 'image', 'locale' => app(LocaleCatalog::class)->default()]);
    }

    #[Unexposed]
    public function assets(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(OptionalComponents::class)->media()->entries('all')->map(fn ($asset): array => [
            'id' => (string) $asset->id, 'name' => $asset->name, 'url' => Storage::disk($asset->disk)->url($asset->path),
            'width' => $asset->width, 'height' => $asset->height, 'alt' => $asset->alt[app(LocaleCatalog::class)->default()] ?? '',
            'mime' => $asset->mime, 'kind' => str_starts_with($asset->mime, 'image/') ? 'image' : (str_starts_with($asset->mime, 'video/') ? 'video' : 'track'),
        ])->all();
    }

    #[Unexposed]
    public function canManage(): bool
    {
        return $this->canMedia($this->selectedAsset === null ? 'upload' : 'update');
    }

    #[Unexposed]
    public function canMedia(string $ability): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && (app(SetupAuthorization::class)->media($actor, $ability) ?? Gate::forUser($actor)->allows('create', ContentPage::class));
    }

    private function authorizeManage(?string $ability = null): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless($this->canMedia($ability ?? ($this->selectedAsset === null ? 'upload' : 'update')), 403);
    }

    private function fillAssetAlt(): void
    {
        if ($this->selectedAsset === null) {
            return;
        }
        $this->authorizeManage();
        $asset = app(OptionalComponents::class)->media()->entries('all')->firstWhere('id', $this->selectedAsset) ?? abort(404);
        $locale = $this->data['locale'] ?? '';
        $this->data['alt'] = app(LocaleCatalog::class)->has($locale) ? ($asset->alt[$locale] ?? '') : '';
    }
}
