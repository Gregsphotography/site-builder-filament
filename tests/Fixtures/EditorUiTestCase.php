<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests\Fixtures;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Components\ComponentsServiceProvider;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;

abstract class EditorUiTestCase extends DatabaseTestCase
{
    protected array $permissions = [];

    protected function getPackageProviders($app): array
    {
        $components = class_exists(ComponentsServiceProvider::class) ? [ComponentsServiceProvider::class] : [];

        return [...parent::getPackageProviders($app), ...$components, BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, LivewireServiceProvider::class, SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class, SchemasServiceProvider::class, InfolistsServiceProvider::class, NotificationsServiceProvider::class, TablesServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class, EditorPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ComponentsServiceProvider::class)) {
            $this->markTestSkipped('The optional standard library supplies these actual-render integration examples.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('l', 32)), 'site-builder.locales' => ['en' => [], 'de' => []], 'site-builder.site_parts.preview_layout' => 'components.business']);
        $this->artisan('vendor:publish', ['--tag' => 'site-builder-components-assets', '--force' => true])->assertExitCode(0);
        Filament::setCurrentPanel(Filament::getPanel('package-test'));
        $this->actingAs(new GenericUser(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Nested editor', 'email' => 'nested@example.test']));
        Gate::before(function ($actor, string $ability, array $arguments): bool {
            $target = $arguments[0] ?? null;
            $key = $ability.':'.($target instanceof Page ? $target->id : (is_object($target) && isset($target->id) ? $target->id : ''));

            return $this->permissions[$key] ?? $this->permissions[$ability] ?? true;
        });
    }

    protected function tearDown(): void
    {
        Livewire::flushState();
        parent::tearDown();
    }

    protected function installOptional(string $folder): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Core\SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/'.$folder), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    protected function page(string $slug = 'tree-page', ?Document $document = null): Page
    {
        $document ??= new Document([new Node('existing', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Existing page content'], 'de' => (object) ['heading' => 'Deutsch unverändert']]))]);
        $page = Page::factory()->create(['title' => 'Page '.$slug, 'layout' => 'components.business', 'draft_content' => $document]);
        $page->slugs()->create(['locale' => 'en', 'slug' => $slug]);

        return $page;
    }
}
