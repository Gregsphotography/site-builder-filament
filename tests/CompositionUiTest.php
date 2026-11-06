<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

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
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\StartingPointDescriptor;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\Tests\Fixtures\EditorPanelProvider;

class CompositionUiTest extends DatabaseTestCase
{
    private array $permissions = [];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class, SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class, SchemasServiceProvider::class, InfolistsServiceProvider::class, NotificationsServiceProvider::class, TablesServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class, EditorPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'site-builder.locales' => ['en' => [], 'de' => []]]);
        Filament::setCurrentPanel(Filament::getPanel('package-test'));
        $this->actingAs(new GenericUser(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Package editor', 'email' => 'editor@example.test']));
        Gate::before(function ($actor, string $ability, array $arguments): bool {
            $target = $arguments[0] ?? null;

            return ($target instanceof SavedSection || $target === SavedSection::class) ? ($this->permissions['saved.'.$ability] ?? $this->permissions[$ability] ?? true) : ($this->permissions[$ability] ?? true);
        });
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
                return 'Host block';
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
        app(StartingPointRegistry::class)->register(new StartingPointDescriptor('template:test.pair', 1, 'Two local sections', 'Page templates', 'A truthful registered local example.', ['pair', 'local'], [], 'code-template'), fn (): Document => new Document([
            new Node('source-one', 'test.block', props: new JsonObject(['opaque' => (object) ['object' => (object) [], 'list' => [], 'null' => null]]), translations: new JsonObject(['en' => (object) ['heading' => 'Source first'], 'de' => (object) ['heading' => 'Quelle']]), children: [new Node('source-child', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Source child']]))]),
            new Node('source-two', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Source second']])),
        ]));
    }

    protected function tearDown(): void
    {
        Livewire::flushState();
        parent::tearDown();
    }

    public function test_host_fallback_is_truthful_and_registered_preview_uses_actual_layout_without_a_scratch_page(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('openPicker', 'after', 'existing')
            ->assertSee('Preview unavailable for this component')->assertSee('Two local sections')->assertSee('optional saved-section store is not installed');
        $editor->call('reviewStartingPoint', 'template:test.pair')->assertSet('state', 'saved');
        $this->get($editor->get('pickerPreviewUrl'))->assertOk()->assertSee('Host layout')->assertSee('Source first')->assertSee('Source child')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertDontSee('site-builder-preview-context')->assertSee('preview.js');
        $this->assertSame(1, Page::query()->count());
        $this->assertSame(0, $page->fresh()->write_version);
        $this->assertSame('Existing', $page->fresh()->draft_content->nodes[0]->translations->toObject()->en->heading);
    }

    public function test_insertion_flushes_native_fields_uses_stable_anchor_and_returns_acknowledged_fresh_ids(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('formData.heading', 'Pending accepted first')
            ->call('openPicker', 'after', 'existing')->call('reviewStartingPoint', 'template:test.pair')->call('applyStartingPoint', false, 47)
            ->assertReturned(fn (array $response): bool => $response['ok'] && $response['requestId'] === 47 && $response['version'] === 2 && count($response['insertedIds']) === 2)
            ->assertSet('pickerOpen', false)->assertSet('state', 'saved');
        $first = $page->fresh()->draft_content;
        $this->assertSame('Pending accepted first', $first->nodes[0]->translations->toObject()->en->heading);
        $this->assertSame($first->nodes[1]->id, $editor->get('selectedId'));
        $this->assertSame('Quelle', $first->nodes[1]->translations->toObject()->de->heading);
        $this->assertInstanceOf(\stdClass::class, $first->nodes[1]->props->toObject()->opaque->object);
        $this->assertSame([], $first->nodes[1]->props->toObject()->opaque->list);
        $this->assertNull($first->nodes[1]->props->toObject()->opaque->null);
        $copiedIds = [$first->nodes[1]->id, $first->nodes[1]->children[0]->id, $first->nodes[2]->id];
        $this->assertSame([], array_intersect($copiedIds, ['source-one', 'source-child', 'source-two']));
        $editor->call('openPicker', 'before', 'existing')->call('reviewStartingPoint', 'template:test.pair')->call('applyStartingPoint');
        $second = $page->fresh()->draft_content;
        $newIds = [$second->nodes[0]->id, $second->nodes[0]->children[0]->id, $second->nodes[1]->id];
        $this->assertSame([], array_intersect($copiedIds, $newIds));
        $this->assertSame('existing', $second->nodes[2]->id);
        $this->assertNull($page->fresh()->published_content);
        $this->assertSame('Page title', $page->fresh()->title);
    }

    public function test_invalid_native_input_variant_and_anchor_cannot_insert_or_discard_input(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('formData.heading', '')
            ->call('openPicker')->assertSet('state', 'invalid')->assertSet('pickerOpen', false)->assertSet('formData.heading', '');
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
        $editor->set('formData.heading', 'Accepted')->call('openPicker')->call('reviewStartingPoint', 'template:test.pair', 'forged')
            ->assertSet('state', 'invalid')->assertSet('pickerReview', []);
        $editor->call('openPicker', 'before', 'missing-anchor')->assertSet('state', 'invalid');
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
    }

    public function test_replacement_requires_explicit_current_review_and_keeps_live_content_and_metadata(): void
    {
        $page = $this->page();
        $page->forceFill(['status' => 'published', 'published_content' => $page->draft_content, 'published_at' => now()])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('openPicker')->call('reviewStartingPoint', 'template:test.pair')
            ->call('applyStartingPoint', true)->assertSet('state', 'invalid');
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
        $editor->call('prepareStartingPointReplacement')->assertSet('replacementReview.sectionCount', 1)
            ->set('replaceConfirmed', true)->call('applyStartingPoint', true)->assertSet('state', 'saved');
        $this->assertCount(2, $page->fresh()->draft_content->nodes);
        $this->assertSame('existing', $page->fresh()->published_content->nodes[0]->id);
        $this->assertSame('Page title', $page->fresh()->title);
        $this->assertSame('test', $page->fresh()->layout);
    }

    public function test_stale_destination_and_revoked_permission_reject_reviewed_application(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('openPicker')->call('reviewStartingPoint', 'template:test.pair');
        app(PageCommands::class)->saveMetadata(auth()->user(), $page->id, $editor->get('leaseToken'), 0, ['title' => 'Accepted newer metadata']);
        $editor->call('applyStartingPoint')->assertSet('state', 'conflict')->assertSet('pickerOpen', true);
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
        $editor->call('reloadSaved')->call('openPicker')->call('reviewStartingPoint', 'template:test.pair');
        $this->permissions['update'] = false;
        $editor->call('applyStartingPoint')->assertForbidden();
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
    }

    public function test_reference_choices_require_explicit_acknowledgement_and_reject_unknown_occurrences(): void
    {
        app(StartingPointRegistry::class)->register(new StartingPointDescriptor('component:with-media', 1, 'Host media example'), fn (): Document => new Document([
            new Node('source-media', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Image', 'image' => '/host-owned.jpg']])),
        ]));
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('openPicker')->call('reviewStartingPoint', 'component:with-media')
            ->assertSet('pickerReferences.blocking', true)->call('applyStartingPoint')->assertSet('state', 'invalid');
        $reference = $editor->get('pickerReferences')['occurrences'][0];
        $editor->set('pickerReferenceValues.'.$reference['key'].'.value', '/mapped.jpg')->set('pickerReferenceValues.'.$reference['key'].'.keepUnverified', true)
            ->call('reviewStartingPointReferences')->assertSet('pickerReferences.blocking', false);
        $editor->set('pickerReferenceValues.forged.value', '/bad.jpg')->call('applyStartingPoint')->assertSet('state', 'invalid');
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
        $editor->set('pickerReferenceValues', [$reference['key'] => ['value' => '/mapped.jpg', 'keepUnverified' => true]])
            ->call('applyStartingPoint')->assertSet('state', 'saved');
        $this->assertSame('/mapped.jpg', $page->fresh()->draft_content->nodes[1]->translations->toObject()->en->image);
    }

    public function test_saved_section_capture_use_metadata_recapture_and_archive_keep_existing_copies_independent(): void
    {
        $this->installSavedSections();
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('formData.heading', 'Captured pending input')
            ->set('captureData.name', 'My saved section')->call('captureSavedSection')->assertSet('state', 'saved');
        $saved = SavedSection::query()->sole();
        $this->assertSame('private', $saved->visibility);
        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $saved->owner_id);
        $this->assertSame(GenericUser::class, $saved->owner_type);
        $this->assertSame(0, $saved->write_version);
        $this->assertSame(1, $saved->current_version);
        $this->assertSame(1, $page->fresh()->write_version);
        $editor->call('openPicker')->call('reviewStartingPoint', 'saved:'.$saved->id)->assertSet('pickerReview.version', '1')
            ->call('applyStartingPoint')->assertSet('state', 'saved');
        $copiedId = $editor->get('selectedId');
        $this->assertNotSame('existing', $copiedId);
        $this->assertSame('Captured pending input', $page->fresh()->draft_content->nodes[1]->translations->toObject()->en->heading);
        $editor->call('openPicker')->call('reviewStartingPoint', 'saved:'.$saved->id)
            ->set('savedSectionData.name', 'Renamed saved section')->call('updateSavedSection')->assertSet('state', 'saved');
        $this->assertSame(2, $saved->fresh()->current_version);
        $this->assertSame('Renamed saved section', $saved->fresh()->name);
        $editor->call('selectNode', 'existing')->set('formData.heading', 'A later source edit')->call('reviewStartingPoint', 'saved:'.$saved->id)
            ->call('updateSavedSection', true)->assertSet('state', 'saved');
        $this->assertSame(3, $saved->fresh()->current_version);
        $this->assertSame('Captured pending input', $page->fresh()->draft_content->nodes[1]->translations->toObject()->en->heading);
        $editor->call('reviewStartingPoint', 'saved:'.$saved->id)->call('archiveSavedSection')->assertSet('state', 'saved');
        $this->assertNotNull($saved->fresh()->archived_at);
        $this->assertCount(2, $page->fresh()->draft_content->nodes);
        $this->assertSame($copiedId, $page->fresh()->draft_content->nodes[1]->id);
    }

    public function test_saved_source_changes_and_policy_revocation_cannot_apply_a_stale_review(): void
    {
        $this->installSavedSections();
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('captureData.name', 'Pinned source')->call('captureSavedSection');
        $saved = SavedSection::query()->sole();
        $editor->call('openPicker')->call('reviewStartingPoint', 'saved:'.$saved->id);
        $preview = $editor->get('pickerPreviewUrl');
        app(SavedSectionCommands::class)->update(auth()->user(), $saved->id, 0, ['name' => 'A newer resource version']);
        $this->get($preview)->assertStatus(409)->assertSee('starting point changed or is unavailable');
        $editor->call('applyStartingPoint')->assertSet('state', 'conflict')->assertSet('pickerOpen', true);
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
        $editor->call('reloadSaved')->call('reviewStartingPoint', 'saved:'.$saved->id)->assertSet('pickerReview.version', '2');
        $this->permissions['use'] = false;
        $editor->call('applyStartingPoint')->assertForbidden();
        $this->assertCount(1, $page->fresh()->draft_content->nodes);
    }

    public function test_saved_library_search_and_cursor_pages_can_reach_entries_beyond_the_first_page(): void
    {
        $this->installSavedSections();
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $commands = app(SavedSectionCommands::class);
        for ($index = 1; $index <= 32; $index++) {
            $commands->capture(auth()->user(), $page->id, $editor->get('leaseToken'), 0, 'existing', ['name' => 'Entry '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $needle = $commands->capture(auth()->user(), $page->id, $editor->get('leaseToken'), 0, 'existing', ['name' => 'Needle beyond page', 'category' => 'Special']);
        $editor->call('openPicker')->assertSee('Entry 01')->assertDontSee('Needle beyond page');
        $this->assertNotNull($editor->get('pickerSavedNextCursor'));
        $editor->call('savedSectionsPage')->assertSee('Needle beyond page')->assertDontSee('Entry 01');
        $editor->call('savedSectionsPage', false)->assertSee('Entry 01')->assertDontSee('Needle beyond page');
        $editor->set('pickerSavedSearch', 'Needle')->call('searchSavedSections')->assertSee('Needle beyond page')->assertDontSee('Entry 01')
            ->call('reviewStartingPoint', 'saved:'.$needle->id)->assertSet('pickerReview.id', $needle->id);
        $editor->set('pickerSavedSearch', 'Entry')->call('searchSavedSections')->assertSet('pickerReview.id', $needle->id);
        $editor->call('reviewStartingPoint', 'saved:'.$needle->id)->assertSet('state', 'saved');
        $this->permissions['saved.view'] = false;
        $editor->call('searchSavedSections')->assertDontSee('Entry 01');
        $this->assertNull($editor->get('pickerSavedNextCursor'));
    }

    private function installSavedSections(): void
    {
        $this->artisan('migrate', ['--path' => dirname((new \ReflectionClass(SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/saved-sections-migrations', '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    private function page(): Page
    {
        return Page::factory()->create(['title' => 'Page title', 'layout' => 'test', 'draft_content' => new Document([
            new Node('existing', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Existing']])),
        ])]);
    }
}
