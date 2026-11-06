<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;

final class ComponentLibrary extends Page
{
    protected static ?string $navigationLabel = 'Component library';

    protected string $view = 'site-builder-filament::pages.component-library';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->user() !== null && Gate::forUser(Filament::auth()->user())->allows('viewAny', ContentPage::class);
    }

    public function getTitle(): string
    {
        return 'Component library';
    }

    public function componentForms(): array
    {
        return app(ComponentFormRegistry::class)->all();
    }
}
