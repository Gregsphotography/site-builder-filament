<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\SupportServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Tests\TestCase;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Contracts\MediaPicker;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Pages\ComponentLibrary;
use SiteBuilder\Filament\Pages\MediaLibrary;
use SiteBuilder\Filament\Pages\PopupLibrary;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\SiteBuilderPlugin;
use SiteBuilder\Filament\Support\OptionalComponents;

class StandardFormsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class, SupportServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class];
    }

    public function test_standard_forms_exist_only_for_installed_definitions_and_custom_forms_take_priority(): void
    {
        $registry = app(ComponentFormRegistry::class);
        $this->assertNull($registry->resolve('standard.hero'));
        $this->assertSame([], $registry->all());
        app(ComponentRegistry::class)->register($this->definition('standard.hero'));
        $standard = $registry->resolve('standard.hero');
        $this->assertSame('Hero', $standard->label());
        $this->assertSame(['standard.hero'], array_keys($registry->all()));
        $custom = new class implements ComponentForm
        {
            public function type(): string
            {
                return 'standard.hero';
            }

            public function label(): string
            {
                return 'Host hero';
            }

            public function icon(): string
            {
                return 'heroicon-o-star';
            }

            public function category(): string
            {
                return 'Host';
            }

            public function schema(EditorContext $context): array
            {
                return [];
            }
        };
        $registry->register($custom);
        $this->assertSame($custom, $registry->resolve('standard.hero'));
        $this->assertSame($custom, $registry->all()['standard.hero']);
    }

    public function test_declared_standard_fields_have_typed_editors_and_media_remains_optional(): void
    {
        app(ComponentRegistry::class)->register($this->definition('standard.cards'));
        $form = app(ComponentFormRegistry::class)->resolve('standard.cards');
        $fields = Schema::make()->components($form->schema(new EditorContext('en')))->getComponents();
        $this->assertSame([TextInput::class, RichEditor::class, Select::class, Select::class, Select::class, Toggle::class, Repeater::class], array_map(fn ($field): string => $field::class, $fields));
        $this->assertTrue($fields[3]->isDisabled());
        $picker = new class implements MediaPicker
        {
            public function options(string $locale): array
            {
                return ['/photo.jpg' => $locale.' photo'];
            }
        };
        $fields = Schema::make()->components($form->schema(new EditorContext('de', readOnly: false, mediaPicker: $picker)))->getComponents();
        $this->assertFalse($fields[3]->isDisabled());
        $this->assertSame(['/photo.jpg' => 'de photo'], $fields[3]->getOptions());
        $fields = Schema::make()->components($form->schema(new EditorContext('en', readOnly: false, mediaPicker: $picker, originalValues: ['image' => '/legacy.svg', 'items' => [['heading' => 'Existing', 'page' => 42]]])))->getComponents();
        $this->assertSame(['/legacy.svg' => 'Existing image', '/photo.jpg' => 'en photo'], $fields[3]->getOptions());
    }

    public function test_optional_service_bridge_is_absent_by_default_and_host_media_picker_wins(): void
    {
        $optional = app(OptionalComponents::class);
        $this->assertNull($optional->media());
        $this->assertNull($optional->picker());
        $this->assertSame([], $optional->templateOptions('en'));
        $this->app->singleton('SiteBuilder\\Components\\Media\\MediaLibrary', fn () => new class
        {
            public function available(): bool
            {
                return true;
            }

            public function options(string $locale): array
            {
                return ['/owned.jpg' => 'Library'];
            }
        });
        $this->assertSame(['/owned.jpg' => 'Library'], $optional->picker()->options('en'));
        $host = new class implements MediaPicker
        {
            public function options(string $locale): array
            {
                return ['/host.jpg' => 'Host'];
            }
        };
        $this->app->instance(MediaPicker::class, $host);
        $this->assertSame($host, $optional->picker());
        $this->app->singleton('SiteBuilder\\Components\\Templates\\TemplateCatalog', fn () => new class
        {
            public function options(string $locale): array
            {
                return ['standard.home' => 'Home'];
            }

            public function instantiate(string $id, string $locale): Document
            {
                return new Document;
            }
        });
        $this->assertSame(['standard.home' => 'Home'], $optional->templateOptions('en'));
        $this->assertInstanceOf(Document::class, $optional->template('standard.home', 'en'));
    }

    public function test_media_library_is_explicit_and_access_uses_the_current_panel_guard(): void
    {
        $this->app['config']->set('auth.guards.studio', ['driver' => 'session', 'provider' => 'users']);
        $panel = Panel::make()->id('studio')->authGuard('studio');
        Filament::setCurrentPanel($panel);
        $actor = new GenericUser(['id' => '01JOPAQUE-STUDIO-EDITOR']);
        Auth::guard('studio')->setUser($actor);
        Auth::guard('web')->setUser(new GenericUser(['id' => 'other-host-account']));
        Gate::before(fn ($user): ?bool => $user->getAuthIdentifier() === $actor->getAuthIdentifier() ? true : null);
        $this->assertTrue(PageResource::canCreate());
        $this->assertTrue(PageResource::canViewAny());
        $this->assertTrue(ComponentLibrary::canAccess());
        $this->assertFalse(MediaLibrary::canAccess());
        SiteBuilderPlugin::make()->mediaLibrary()->register($panel);
        $this->assertSame([ComponentLibrary::class, PopupLibrary::class, MediaLibrary::class], $panel->getPages());
        $this->app->singleton('SiteBuilder\\Components\\Media\\MediaLibrary', fn () => new class
        {
            public function available(): bool
            {
                return true;
            }
        });
        $this->assertTrue(MediaLibrary::canAccess());
        Auth::guard('studio')->forgetUser();
        $this->assertFalse(PageResource::canCreate());
        $this->assertFalse(MediaLibrary::canAccess());
    }

    private function definition(string $type): ComponentDefinition
    {
        return new readonly class($type) implements ComponentDefinition
        {
            public function __construct(private string $key) {}

            public function type(): string
            {
                return $this->key;
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
                return [
                    'heading' => PropertyDefinition::text(translated: true),
                    'body' => PropertyDefinition::html(translated: true),
                    'variant' => PropertyDefinition::enum(['cards', 'logos']),
                    'image' => PropertyDefinition::media(),
                    'page' => PropertyDefinition::page(translated: true),
                    'decorative' => new PropertyDefinition('boolean'),
                    'items' => PropertyDefinition::list(PropertyDefinition::object(['heading' => PropertyDefinition::text(), 'page' => PropertyDefinition::page()]), translated: true),
                ];
            }

            public function allowedChildren(): ChildPolicy
            {
                return ChildPolicy::none();
            }

            public function view(): string
            {
                return 'test.standard';
            }
        };
    }
}
