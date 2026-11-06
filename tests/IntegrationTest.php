<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Composer\InstalledVersions;
use Filament\FilamentServiceProvider;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\SupportServiceProvider;
use InvalidArgumentException;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Tests\TestCase;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Pages\ComponentLibrary;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\SiteBuilderPlugin;

class IntegrationTest extends TestCase
{
    public function test_integrated_installation_has_no_atelier_dependency(): void
    {
        $this->assertFalse(InstalledVersions::isInstalled('safi/filament-atelier'));
        $this->assertFalse(class_exists('Safi\\Atelier\\Models\\Page'));
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class, SupportServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class];
    }

    public function test_form_registration_is_separate_from_core_and_requires_a_registered_definition(): void
    {
        app(ComponentRegistry::class)->register(new class implements ComponentDefinition
        {
            public function type(): string
            {
                return 'test.hero';
            }

            public function version(): int
            {
                return 1;
            }

            public function defaults(): PropertyValues
            {
                return new PropertyValues;
            }

            public function properties(): array
            {
                return ['heading' => PropertyDefinition::text(translated: true)];
            }

            public function allowedChildren(): ChildPolicy
            {
                return ChildPolicy::none();
            }

            public function view(): string
            {
                return 'test.hero';
            }
        });
        $form = $this->form('test.hero');
        $forms = app(ComponentFormRegistry::class)->register($form);
        $this->assertSame($form, $forms->resolve('test.hero'));
        $this->assertInstanceOf(TextInput::class, $form->schema(new EditorContext('en'))[0]);
        $this->expectException(InvalidArgumentException::class);
        $forms->register($form);
    }

    public function test_a_form_for_an_unregistered_core_component_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ComponentFormRegistry::class)->register($this->form('missing.component'));
    }

    public function test_plugin_uses_public_filament_registration_without_duplicate_screens(): void
    {
        $panel = Panel::make()->id('foundation');
        $plugin = SiteBuilderPlugin::make();
        $plugin->register($panel);
        $plugin->register($panel);
        $this->assertSame([PageResource::class, SitePartResource::class], $panel->getResources());
        $this->assertSame([ComponentLibrary::class], $panel->getPages());
        $this->assertFalse(PageResource::canCreate());
        $this->assertFalse(PageResource::canViewAny());
        $this->assertFalse(SitePartResource::canViewAny());
        $this->assertFalse(SitePartResource::canCreate());
    }

    public function test_compiled_package_assets_are_registered_from_distributed_files(): void
    {
        $this->assertStringContainsString('gregsphotography/site-builder-filament/site-builder.css', FilamentAsset::getStyleHref('site-builder', package: 'gregsphotography/site-builder-filament'));
        $this->assertStringContainsString('gregsphotography/site-builder-filament/site-builder.js', FilamentAsset::getScriptSrc('site-builder', package: 'gregsphotography/site-builder-filament'));
        $this->assertFileExists(__DIR__.'/../resources/dist/site-builder.css');
        $this->assertFileExists(__DIR__.'/../resources/dist/site-builder.js');
        $this->assertFileExists(__DIR__.'/../resources/dist/site-part-editor.js');
    }

    private function form(string $type): ComponentForm
    {
        return new readonly class($type) implements ComponentForm
        {
            public function __construct(private string $key) {}

            public function type(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Hero';
            }

            public function icon(): string
            {
                return 'heroicon-o-square-3-stack-3d';
            }

            public function category(): string
            {
                return 'Test';
            }

            public function schema(EditorContext $context): array
            {
                return [TextInput::make('heading')->disabled($context->readOnly)];
            }
        };
    }
}
