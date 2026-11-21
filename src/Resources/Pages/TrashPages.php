<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Core\Persistence\PageTrash;

final class TrashPages extends Page
{
    protected static string $resource = PageResource::class;

    protected static ?string $title = 'Page trash';

    protected string $view = 'site-builder-filament::pages.trash';

    public int $pageNumber = 1;

    public function restorePage(int $id, int $version): void
    {
        abort_unless(PageResource::canViewAny(), 403);
        try {
            app(PageTrash::class)->restore(Filament::auth()->user(), $id, $version);
        } catch (WriteConflict|ModelNotFoundException) {
            Notification::make()->title('The page changed. Check the current trash list.')->warning()->send();

            return;
        }
        Notification::make()->title('Page restored to draft')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(PageResource::canViewAny(), 403);
        $gate = Gate::forUser(Filament::auth()->user());
        $entries = ContentPage::trash()->latest('updated_at')->orderByDesc('id')->cursor()
            ->filter(fn (ContentPage $page): bool => $gate->allows('view', $page))
            ->skip((max(1, $this->pageNumber) - 1) * 20)->take(21)->collect()->values();

        return ['entries' => $entries->take(20), 'more' => $entries->count() > 20, 'gate' => $gate];
    }
}
