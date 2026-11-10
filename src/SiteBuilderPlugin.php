<?php

declare(strict_types=1);

namespace SiteBuilder\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use SiteBuilder\Filament\Http\PickerPreview;
use SiteBuilder\Filament\Http\ReleaseLease;
use SiteBuilder\Filament\Http\ReleaseSitePartLease;
use SiteBuilder\Filament\Http\SitePartPreview;
use SiteBuilder\Filament\Pages\ComponentLibrary;
use SiteBuilder\Filament\Pages\MediaLibrary;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;

final class SiteBuilderPlugin implements Plugin
{
    private array $additionalPages = [];

    private bool $withMediaLibrary = false;

    private bool $withForms = false;

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'site-builder';
    }

    /** Host-owned media/navigation/settings screens are opt-in. @param list<class-string> $pages */
    public function pages(array $pages): self
    {
        $this->additionalPages = array_values(array_unique($pages));

        return $this;
    }

    /** Enable the component pack's optional local image library. */
    public function mediaLibrary(bool $enabled = true): self
    {
        $this->withMediaLibrary = $enabled;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([PageResource::class, SitePartResource::class])->pages([ComponentLibrary::class, ...($this->withMediaLibrary ? [MediaLibrary::class] : []), ...($this->withForms ? [Pages\FormProfiles::class, Pages\FormInbox::class] : []), ...$this->additionalPages]);
        $panel->authenticatedRoutes(function (): void {
            Route::get('/site-builder/site-parts/{part}/preview', SitePartPreview::class)->middleware('signed')->name('site-builder.part-preview');
            Route::post('/site-builder/site-parts/{part}/release', ReleaseSitePartLease::class)->name('site-builder.part-release');
            Route::post('/site-builder/leases/{page}/release', ReleaseLease::class)->name('site-builder.release');
            Route::get('/site-builder/picker/{page}', PickerPreview::class)->middleware('signed')->name('site-builder.picker-preview');
        });
    }

    public function boot(Panel $panel): void {}

    public function forms(bool $enabled = true): self
    {
        $this->withForms = $enabled;

        return $this;
    }
}
