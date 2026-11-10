<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use SiteBuilder\Filament\SiteBuilderPlugin;

final class EditorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('package-test')->path('package-test')->plugins([SiteBuilderPlugin::make()->forms()]);
    }
}
