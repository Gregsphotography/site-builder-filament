<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Components\ComponentsServiceProvider;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\Tests\Fixtures\EditorPanelProvider;

class DraftHistoryUiTest extends DatabaseTestCase
{
    private array $permissions = [];

    protected function getPackageProviders($app): array
    {
        $components = class_exists(ComponentsServiceProvider::class) ? [ComponentsServiceProvider::class] : [];

        return [...parent::getPackageProviders($app), ...$components, BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, LivewireServiceProvider::class, SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class, SchemasServiceProvider::class, InfolistsServiceProvider::class, NotificationsServiceProvider::class, TablesServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class, EditorPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'site-builder.locales' => ['en' => [], 'de' => []]]);
        Filament::setCurrentPanel(Filament::getPanel('package-test'));
        $this->actingAs(new GenericUser(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'History editor', 'email' => 'history@example.test']));
        Gate::before(fn ($actor, string $ability, array $arguments): bool => $this->permissions[$ability.':'.($arguments[0] instanceof Page ? $arguments[0]->id : '')] ?? $this->permissions[$ability] ?? true);
        view()->addNamespace('test-blocks', __DIR__.'/Support/Core/Fixtures/views');
        app(LayoutRegistry::class)->register('test', 'test-blocks::layout');
        app(ComponentRegistry::class)->register(RenderBlock::class);
        app(ComponentFormRegistry::class)->register(new class implements ComponentForm
        {
            public function type(): string
            {
                return 'test.block';
            }

            public function label(): string
            {
                return 'History block';
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
                return [TextInput::make('heading')->required()->maxLength(255)];
            }
        });
    }

    protected function tearDown(): void
    {
        Livewire::flushState();
        parent::tearDown();
    }

    public function test_optional_stores_do_not_disable_ordinary_editing(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSet('draftHistoryState.available', false)
            ->assertSee('optional history store')->assertSee('optional clipboard store')->set('formData.heading', 'Works without stores')->call('saveSelected')->assertSet('state', 'saved');
        $editor->call('openClipboard', 'paste')->assertSet('state', 'invalid')->assertSet('clipboardOpen', false);
        $this->assertSame('Works without stores', $page->fresh()->draft_content->nodes[0]->translations->get('en')->heading);
    }

    public function test_acknowledged_typing_groups_branch_and_shared_metadata_survive_undo(): void
    {
        $this->installHistory();
        $page = $this->page();
        $group = (string) Str::uuid();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSet('draftHistoryState.undoCount', 0)
            ->set('formData.heading', 'First typing')->call('autosaveSelected', $group)->assertSet('draftHistoryState.undoCount', 1)
            ->set('formData.heading', 'Second typing')->call('autosaveSelected', $group)->assertSet('draftHistoryState.undoCount', 1)
            ->call('saveSelected')->set('formData.heading', 'New typing group')->call('autosaveSelected', $group)->assertSet('draftHistoryState.undoCount', 2)
            ->call('undoDraft', 57)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 57 && $ack['version'] === 4)
            ->assertSet('formData.heading', 'Second typing')->assertSet('draftHistoryState.redoCount', 1)
            ->set('settingsData.title', 'Immediate shared title')->call('saveSettings')->assertSet('draftHistoryState.undoCount', 1)->assertSet('draftHistoryState.redoCount', 1)
            ->call('redoDraft')->assertSet('formData.heading', 'New typing group');
        $this->assertSame('Immediate shared title', $page->fresh()->title);
        $editor->call('undoDraft')->call('hideSection', 'known', true)->assertSet('draftHistoryState.redoCount', 0)->assertSet('draftHistoryState.undoCount', 2)
            ->call('undoDraft')->call('undoDraft')->assertSet('formData.heading', 'Original')->assertSet('draftHistoryState.undoCount', 0);
        $this->assertSame('Immediate shared title', $page->fresh()->title);
        $this->assertSame('Deutsch', $page->fresh()->draft_content->nodes[0]->translations->get('de')->heading);
        $this->assertNull($page->fresh()->published_content);
    }

    public function test_pending_validation_and_external_conflicts_retain_input_and_do_not_travel_history(): void
    {
        $this->installHistory();
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('formData.heading', 'Accepted')->call('saveSelected')
            ->set('formData.heading', '')->call('undoDraft')->assertSet('state', 'invalid')->assertSet('formData.heading', '')->assertSet('draftHistoryState.undoCount', 1);
        $this->assertSame('Accepted', $page->fresh()->draft_content->nodes[0]->translations->get('en')->heading);
        $editor->set('formData.heading', 'Pending before undo')->call('undoDraft')->assertSet('state', 'saved')->assertSet('formData.heading', 'Accepted');
        app(PageCommands::class)->saveMetadata(auth()->user(), $page->id, $editor->get('leaseToken'), $editor->get('writeVersion'), ['title' => 'External title']);
        $digest = app(CanonicalJson::class)->digest($page->fresh()->draft_content);
        $editor->set('formData.heading', 'Retained after conflict')->call('undoDraft')->assertSet('state', 'conflict')->assertSet('formData.heading', 'Retained after conflict');
        $this->assertSame($digest, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
        $this->assertSame('External title', $page->fresh()->title);
    }

    public function test_removed_unknown_subtree_is_restored_exactly_and_publication_reload_restore_are_boundaries(): void
    {
        $this->installHistory();
        $page = $this->page();
        $unknown = new Node('future-node', 'future.widget', 7, new JsonObject(['opaque' => (object) ['object' => (object) [], 'list' => [], 'null' => null]]), children: [new Node('future-child', 'future.child')]);
        $page->forceFill(['draft_content' => new Document([...$page->draft_content->nodes, $unknown])])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('removeSection', 'future-node')->assertSet('draftHistoryState.undoCount', 1)
            ->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame(app(CanonicalJson::class)->encode($unknown), app(CanonicalJson::class)->encode($page->fresh()->draft_content->nodes[1]));
        $editor->call('redoDraft')->call('publish')->assertSet('draftHistoryState.undoCount', 0)->assertSet('draftHistoryState.redoCount', 0);
        $revision = $page->fresh()->revisions()->sole();
        $editor->set('formData.heading', 'Later draft')->call('saveSelected')->assertSet('draftHistoryState.undoCount', 1)
            ->call('restoreRevision', $revision->id)->assertSet('draftHistoryState.undoCount', 0)->assertSet('formData.heading', 'Original');
        $epoch = $editor->get('draftHistoryContextData')['epoch'];
        $editor->set('formData.heading', 'Another draft')->call('saveSelected')->call('reloadSaved')->assertSet('draftHistoryState.undoCount', 0);
        $this->assertNotSame($epoch, $editor->get('draftHistoryContextData')['epoch']);
        $this->assertSame('Original', $page->fresh()->published_content->nodes[0]->translations->get('en')->heading);
    }

    public function test_design_mutations_use_the_same_document_journal(): void
    {
        if (! class_exists(ComponentsServiceProvider::class)) {
            $this->markTestSkipped('Optional design theme integration.');
        }
        $this->installHistory();
        $this->artisan('vendor:publish', ['--tag' => 'site-builder-components-assets', '--force' => true])->assertExitCode(0);
        $page = $this->page();
        $page->forceFill(['layout' => 'components.business', 'draft_content' => new Document([new Node('known', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Designed']]))])])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('designData.align', 'center')->call('saveDesign')->assertSet('draftHistoryState.undoCount', 1)
            ->call('undoDraft')->assertSet('designData.align', null)->assertSet('draftHistoryState.redoCount', 1)->call('redoDraft')->assertSet('designData.align', 'center');
        $this->assertNull($page->fresh()->published_content);
    }

    public function test_evicted_steps_and_expired_history_are_visible_without_overwriting_pending_input(): void
    {
        $this->installHistory();
        config(['site-builder.history.transitions' => 2]);
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        foreach (['First', 'Second', 'Third'] as $heading) {
            $editor->set('formData.heading', $heading)->call('saveSelected')->assertSet('state', 'saved');
        }
        $editor->assertSet('draftHistoryState.undoCount', 2)->assertSet('draftHistoryState.evicted', 1);
        DB::table('site_builder_draft_histories')->update(['expires_at' => now()->subSecond()]);
        $version = $page->fresh()->write_version;
        $editor->set('formData.heading', 'Retained after expiry')->call('undoDraft')->assertSet('state', 'conflict')->assertSet('formData.heading', 'Retained after expiry');
        $this->assertSame($version, $page->fresh()->write_version);
        $this->assertSame('Third', $page->fresh()->draft_content->nodes[0]->translations->get('en')->heading);
        $editor->call('reloadSaved')->assertSet('draftHistoryState.undoCount', 0)->assertSet('state', 'saved');
    }

    public function test_repeater_autosaves_are_discrete_even_with_the_same_typing_group(): void
    {
        if (! class_exists(ComponentsServiceProvider::class)) {
            $this->markTestSkipped('Optional native standard repeater integration.');
        }
        $this->installHistory();
        $this->artisan('vendor:publish', ['--tag' => 'site-builder-components-assets', '--force' => true])->assertExitCode(0);
        $page = $this->page();
        $page->forceFill(['layout' => 'components.business', 'draft_content' => new Document([new Node('known', 'standard.faq', translations: new JsonObject(['en' => (object) ['heading' => 'Questions', 'items' => [(object) ['question' => 'One?', 'answer' => '<p>First.</p>']]], 'de' => (object) ['items' => [(object) ['question' => 'Deutsch?', 'answer' => '<p>Bleibt.</p>']]]]))])])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $key = array_key_first($editor->get('formData.items'));
        $group = (string) Str::uuid();
        $editor->set('formData.items.'.$key.'.question', 'Two?')->call('autosaveSelected', $group)->assertSet('draftHistoryState.undoCount', 1)
            ->set('formData.items.'.$key.'.question', 'Three?')->call('autosaveSelected', $group)->assertSet('draftHistoryState.undoCount', 2)
            ->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame('Two?', $page->fresh()->draft_content->nodes[0]->translations->get('en')->items[0]->question);
        $this->assertSame('Deutsch?', $page->fresh()->draft_content->nodes[0]->translations->get('de')->items[0]->question);
    }

    private function installHistory(): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    private function page(): Page
    {
        $page = Page::factory()->create(['title' => 'History page', 'layout' => 'test', 'draft_content' => new Document([
            new Node('known', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Original'], 'de' => (object) ['heading' => 'Deutsch']])),
        ])]);
        $page->slugs()->create(['locale' => 'en', 'slug' => 'history-page']);

        return $page;
    }
}
