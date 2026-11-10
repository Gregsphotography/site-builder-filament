<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;

final class PopupLibrary extends Page
{
    protected static ?string $navigationLabel = 'Popups and drawers';

    protected static ?string $slug = 'popups';

    protected string $view = 'site-builder-filament::pages.popup-library';

    public string $search = '';

    #[Locked]
    public ?string $cursor = null;

    public static function canAccess(): bool
    {
        return SitePartResource::canViewAny();
    }

    public function updatedSearch(): void
    {
        $this->cursor = null;
    }

    public function nextPage(): void
    {
        $this->cursor = $this->entries()['nextCursor'];
    }

    public function firstPage(): void
    {
        $this->cursor = null;
    }

    public function entries(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(SitePartCatalog::class)->searchPage(Filament::auth()->user(), $this->search, 'overlay', $this->cursor);
    }
}
