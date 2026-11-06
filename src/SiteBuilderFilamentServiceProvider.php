<?php

declare(strict_types=1);

namespace SiteBuilder\Filament;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use SiteBuilder\Core\Installation\SetupRegistry;
use SiteBuilder\Filament\Composition\PickerCatalog;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Support\CompiledCss;

final class SiteBuilderFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(SetupRegistry::class, fn ($registry) => $registry->register($this->app->make(Installation\FilamentSetupContributor::class)));
        $this->app->singleton(ComponentFormRegistry::class);
        $this->app->singleton(PickerCatalog::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'site-builder-filament');
        FilamentAsset::register([
            CompiledCss::make('site-builder', __DIR__.'/../resources/dist/site-builder.css'),
            Js::make('site-builder', __DIR__.'/../resources/dist/site-builder.js')->module()->loadedOnRequest(),
            AlpineComponent::make('siteBuilderEditor', __DIR__.'/../resources/dist/site-builder.js'),
            AlpineComponent::make('sitePartEditor', __DIR__.'/../resources/dist/site-part-editor.js'),
        ], package: 'gregsphotography/site-builder-filament');
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/site-builder-filament')], 'site-builder-filament-views');
            $this->publishes([__DIR__.'/../resources/dist' => public_path('vendor/site-builder-filament')], ['site-builder-filament-assets', 'site-builder-assets-update']);
        }
    }
}
