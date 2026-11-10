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
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Components\ComponentsServiceProvider;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\Tests\Fixtures\EditorPanelProvider;

class ClipboardUiTest extends DatabaseTestCase
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

    public function test_structured_copy_flushes_native_input_and_paste_is_one_undoable_independent_transition(): void
    {
        $this->installHistory();
        $this->installClipboard();
        $source = $this->page();
        $sourceEditor = Livewire::test(PageEditor::class, ['record' => $source->id])->set('formData.heading', 'Pending copied draft')->call('openClipboard', 'copy')
            ->assertSet('state', 'saved')->assertSet('clipboardOpen', true)->assertSee('Select text for manual copy')->assertSee('Download structured copy');
        $text = $sourceEditor->get('copiedSectionText');
        $payload = json_decode($text);
        $this->assertSame('laravel-site-builder/clipboard', $payload->format);
        $this->assertSame('Pending copied draft', $payload->nodes[0]->translations->en->heading);
        $this->assertSame('Deutsch', $payload->nodes[0]->translations->de->heading);
        foreach (array_filter([$sourceEditor->get('leaseToken'), $sourceEditor->get('channel'), $sourceEditor->get('tabId'), csrf_token()]) as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertObjectNotHasProperty('history', $payload);
        $sourceEditor->call('closeClipboard')->set('formData.heading', 'Newer source draft')->call('saveSelected');
        $destination = $this->page('destination');
        $destinationEditor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openClipboard', 'paste', 'after', 'known')
            ->set('clipboardText', $text)->call('reviewClipboard')->assertSet('clipboardReferences.blocking', false)->call('pasteClipboard', 83)
            ->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 83 && count($ack['insertedIds']) === 1)
            ->assertSet('clipboardOpen', false)->assertSet('clipboardText', '')->assertSet('draftHistoryState.undoCount', 1);
        $copied = $destination->fresh()->draft_content->nodes[1];
        $this->assertNotSame('known', $copied->id);
        $this->assertSame('Pending copied draft', $copied->translations->get('en')->heading);
        $this->assertSame('Deutsch', $copied->translations->get('de')->heading);
        $this->assertSame('Newer source draft', $source->fresh()->draft_content->nodes[0]->translations->get('en')->heading);
        $this->assertNull($destination->fresh()->published_content);
        $destinationEditor->call('undoDraft')->assertSet('draftHistoryState.redoCount', 1);
        $this->assertCount(1, $destination->fresh()->draft_content->nodes);
        $destinationEditor->call('redoDraft');
        $this->assertSame($copied->id, $destination->fresh()->draft_content->nodes[1]->id);
    }

    public function test_paste_rejects_changed_text_and_rechecks_source_policy_without_losing_recovery_input(): void
    {
        $this->installClipboard();
        $source = $this->page();
        $sourceEditor = Livewire::test(PageEditor::class, ['record' => $source->id])->call('openClipboard', 'copy');
        $text = $sourceEditor->get('copiedSectionText');
        $destination = $this->page('destination');
        $editor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openClipboard')->set('clipboardText', $text)->call('reviewClipboard');
        $tampered = str_replace('Original', 'Forged content', $text);
        $editor->set('clipboardText', $tampered)->call('pasteClipboard')->assertSet('state', 'invalid')->assertSet('clipboardText', $tampered)
            ->call('reviewClipboard')->assertSet('state', 'invalid');
        $this->assertSame(0, $destination->fresh()->write_version);
        $editor->set('clipboardText', $text)->call('reviewClipboard')->assertSet('state', 'saved');
        $this->permissions['preview:'.$source->id] = false;
        $editor->call('pasteClipboard')->assertForbidden();
        $this->assertSame(0, $destination->fresh()->write_version);
        $this->assertCount(1, $destination->fresh()->draft_content->nodes);
    }

    public function test_expired_snapshot_stale_destination_and_other_actor_cannot_admit_clipboard_content(): void
    {
        $this->installClipboard();
        $source = $this->page();
        $sourceEditor = Livewire::test(PageEditor::class, ['record' => $source->id])->call('openClipboard', 'copy');
        $text = $sourceEditor->get('copiedSectionText');
        $destination = $this->page('destination');
        $editor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openClipboard')->set('clipboardText', $text)->call('reviewClipboard');
        app(PageCommands::class)->saveMetadata(auth()->user(), $destination->id, $editor->get('leaseToken'), 0, ['title' => 'Newer destination']);
        $editor->call('pasteClipboard')->assertSet('state', 'conflict')->assertSet('clipboardText', $text);
        $this->assertCount(1, $destination->fresh()->draft_content->nodes);
        $editor->call('reloadSaved')->assertSet('clipboardText', $text)->assertSet('clipboardReview', [])
            ->call('openClipboard')->call('pasteClipboard')->assertSet('state', 'invalid')->call('reviewClipboard')->assertSet('state', 'saved');
        DB::table('site_builder_clipboard_snapshots')->update(['expires_at' => now()->subSecond()]);
        $editor->call('pasteClipboard')->assertSet('state', 'invalid')->assertSet('clipboardText', $text);
        $this->assertCount(1, $destination->fresh()->draft_content->nodes);
        $this->actingAs(new GenericUser(['id' => 'other-editor', 'name' => 'Other editor']));
        $otherPage = $this->page('other-destination');
        $otherEditor = Livewire::test(PageEditor::class, ['record' => $otherPage->id])->call('openClipboard')->set('clipboardText', $text)->call('reviewClipboard')->assertSet('state', 'invalid');
        $this->assertSame(0, $otherPage->fresh()->write_version);
        $otherEditor->call('clearClipboardInput')->assertSet('clipboardText', '')->assertSet('clipboardReview', []);
    }

    public function test_declared_media_choices_are_reviewed_and_source_node_design_keeps_destination_defaults(): void
    {
        if (! class_exists(ComponentsServiceProvider::class)) {
            $this->markTestSkipped('Optional standard media/design integration.');
        }
        $this->installClipboard();
        $this->artisan('vendor:publish', ['--tag' => 'site-builder-components-assets', '--force' => true])->assertExitCode(0);
        $source = $this->page();
        $source->forceFill(['layout' => 'components.minimal', 'draft_content' => new Document([
            new Node('source-image', 'standard.image', props: new JsonObject(['image' => '/vendor/site-builder-components/examples/workspace.svg']), translations: new JsonObject(['en' => (object) ['alt' => 'Source alt'], 'de' => (object) ['alt' => 'Deutsch alt']]), extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'base' => (object) ['border' => 'strong']]]])),
        ], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'standard.base', 'version' => 1, 'preset' => 'minimal'], 'base' => (object) ['space' => 'sm']]]]))])->save();
        $sourceEditor = Livewire::test(PageEditor::class, ['record' => $source->id])->call('openClipboard', 'copy');
        $destination = $this->page('destination');
        $destination->forceFill(['layout' => 'components.editorial', 'draft_content' => new Document([new Node('destination', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Destination']]))], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'standard.base', 'version' => 1, 'preset' => 'editorial'], 'base' => (object) ['space' => 'xl']]]]))])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openClipboard')->set('clipboardText', $sourceEditor->get('copiedSectionText'))->call('reviewClipboard')->assertSet('clipboardReferences.blocking', false);
        $key = $editor->get('clipboardReferences')['occurrences'][0]['key'];
        $editor->set('clipboardReferenceValues.'.$key.'.value', '/vendor/site-builder-components/examples/landscape.svg')->call('pasteClipboard')->assertSet('state', 'invalid');
        $editor->call('reviewClipboard', true)->assertSet('clipboardReferences.blocking', false)->call('pasteClipboard')->assertSet('state', 'saved');
        $document = $destination->fresh()->draft_content;
        $this->assertSame('editorial', $document->extensions->get('site-builder.render')->design->theme->preset);
        $this->assertSame('xl', $document->extensions->get('site-builder.render')->design->base->space);
        $this->assertSame('strong', $document->nodes[1]->extensions->get('site-builder.render')->design->base->border);
        $this->assertSame('/vendor/site-builder-components/examples/landscape.svg', $document->nodes[1]->props->get('image'));
        $this->assertSame('Deutsch alt', $document->nodes[1]->translations->get('de')->alt);
    }

    private function installClipboard(): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Core\SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/clipboard-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    private function installHistory(): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Core\SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    private function page(string $slug = 'clipboard-page'): Page
    {
        $page = Page::factory()->create(['title' => 'History page', 'layout' => 'test', 'draft_content' => new Document([
            new Node('known', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Original'], 'de' => (object) ['heading' => 'Deutsch']])),
        ])]);
        $page->slugs()->create(['locale' => 'en', 'slug' => $slug]);

        return $page;
    }
}
