<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use SiteBuilder\Components\Examples\StandardExamples;
use SiteBuilder\Components\Media\MediaLibrary;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Filament\Contracts\BatchMediaPicker;
use SiteBuilder\Filament\Contracts\MediaPicker;
use SiteBuilder\Filament\Forms\BatchMediaSelection;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Support\ComponentMediaPicker;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class GalleryUiTest extends EditorUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installOptional('history-migrations');
        $this->installOptional('site-parts-migrations');
    }

    public function test_native_gallery_reorder_and_locale_edits_preserve_opaque_fields_missing_references_and_empty_locale(): void
    {
        $page = $this->page('gallery-native', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $rows = $editor->get('formData.items');
        $keys = array_keys($rows);
        $rows[$keys[1]]['caption'] = 'Edited caption';
        $rows[$keys[1]]['decorative'] = true;
        $editor->set('formData.items', [$keys[1] => $rows[$keys[1]], $keys[0] => $rows[$keys[0]]])->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $node = $page->fresh()->draft_content->nodes[0];
        $this->assertSame('second', $node->translations->get('en')->items[0]->future);
        $this->assertSame('Edited caption', $node->translations->get('en')->items[0]->caption);
        $this->assertTrue($node->translations->get('en')->items[0]->decorative);
        $this->assertSame('site-builder-media/unavailable.jpg', $node->translations->get('en')->items[1]->full_image);
        $this->assertSame('{}', json_encode($node->translations->get('en')->items[1]->opaque->object));
        $this->assertSame([], $node->translations->get('en')->items[1]->opaque->list);
        $this->assertNull($node->translations->get('en')->items[1]->opaque->nullable);
        $editor->call('changeLocale', 'de')->assertSet('formData.items', []);
        $editor->call('changeLocale', 'en')->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame('first', $page->fresh()->draft_content->nodes[0]->translations->get('en')->items[0]->future);
    }

    public function test_partial_batch_requires_confirmation_preserves_order_and_is_one_undoable_draft_write(): void
    {
        $this->picker();
        $page = $this->page('gallery-batch', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('mediaBatchReferences', ['/two.jpg', '/denied.jpg', '/one.jpg'])
            ->call('reviewMediaBatch')->assertSet('state', 'saved')->assertCount('mediaBatchReview.selection.accepted', 2)->assertSee('Selection 2');
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->call('cancelMediaBatch')->assertSet('mediaBatchReview', []);
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->set('mediaBatchReferences', ['/two.jpg', '/denied.jpg', '/one.jpg'])->call('reviewMediaBatch')->set('mediaBatchConfirmed', true)->call('acceptMediaBatch');
        $this->assertSame('saved', $editor->get('state'), $editor->get('message'));
        $editor->assertHasNoErrors()->assertSet('draftHistoryState.undoCount', 1);
        $items = $page->fresh()->draft_content->nodes[0]->translations->get('en')->items;
        $this->assertSame(['/two.jpg', '/one.jpg'], array_map(fn ($item) => $item->image, array_slice($items, 2)));
        $this->assertSame(100, $items[2]->width);
        $this->assertSame(80, $items[2]->height);
        $this->assertSame(1, $page->fresh()->write_version);
        $this->assertNull($page->fresh()->published_content);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertCount(2, $page->fresh()->draft_content->nodes[0]->translations->get('en')->items);
    }

    public function test_source_revocation_local_changes_and_locale_switch_invalidate_review_without_appending(): void
    {
        $picker = $this->picker();
        $page = $this->page('gallery-stale', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('mediaBatchReferences', ['/one.jpg'])->call('reviewMediaBatch');
        $picker->denied = true;
        $editor->set('mediaBatchConfirmed', true)->call('acceptMediaBatch')->assertSet('state', 'conflict');
        $this->assertSame(0, $page->fresh()->write_version);
        $picker->denied = false;
        $editor->call('reloadSaved')->set('mediaBatchReferences', ['/one.jpg'])->call('reviewMediaBatch')->set('formData.heading', 'A local edit')->set('mediaBatchConfirmed', true)->call('acceptMediaBatch')->assertSet('state', 'conflict');
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->call('reloadSaved')->set('mediaBatchReferences', ['/one.jpg'])->call('reviewMediaBatch')->call('changeLocale', 'de')->assertSet('mediaBatchReview', []);
    }

    public function test_site_part_batch_uses_real_resource_history_and_testimonial_form_retains_hidden_items(): void
    {
        $this->picker();
        $part = app(SitePartCommands::class)->create(Filament::auth()->user(), 'section', ['name' => 'Gallery section'], SitePartPayload::document('section', $this->document()))->part;
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->set('mediaBatchReferences', ['/one.jpg'])->call('reviewMediaBatch')->set('mediaBatchConfirmed', true)->call('acceptMediaBatch')->assertSet('state', 'saved')->assertSet('draftHistoryState.undoCount', 1);
        $this->assertCount(3, $part->fresh()->draft_payload->document->nodes[0]->translations->get('en')->items);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertCount(2, $part->fresh()->draft_payload->document->nodes[0]->translations->get('en')->items);
        $quote = app(StandardExamples::class)->document('standard.testimonials', 'en', 'single');
        $page = $this->page('quote-native', $quote);
        $native = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('every stored item');
        $rows = $native->get('formData.items');
        $key = array_key_first($rows);
        $native->set('formData.items.'.$key.'.quote', 'An edited plain quotation')->call('saveSelected')->assertSet('state', 'saved');
        $this->assertCount(3, $page->fresh()->draft_content->nodes[0]->translations->get('en')->items);
    }

    public function test_baseline_custom_picker_remains_usable_without_batch_and_oversized_batch_refuses_before_write(): void
    {
        app()->instance(MediaPicker::class, new class implements MediaPicker
        {
            public function options(string $locale): array
            {
                return ['/one.jpg' => 'One'];
            }
        });
        $page = $this->page('gallery-legacy-picker', $this->document());
        Livewire::test(PageEditor::class, ['record' => $page->id])->assertDontSee('Select multiple gallery images')->call('reviewMediaBatch')->assertSet('state', 'invalid');
        $this->assertSame(0, $page->fresh()->write_version);
        $this->picker();
        $other = $this->page('gallery-bounds', $this->document());
        Livewire::test(PageEditor::class, ['record' => $other->id])->set('mediaBatchReferences', array_fill(0, 47, '/one.jpg'))->call('reviewMediaBatch')->assertSet('state', 'invalid');
        $this->assertSame(0, $other->fresh()->write_version);
    }

    public function test_gallery_clipboard_and_saved_copy_preserve_references_locales_unknown_shapes_and_fresh_ids(): void
    {
        $this->installOptional('clipboard-migrations');
        $this->installOptional('saved-sections-migrations');
        $doc = $this->document();
        $items = $doc->nodes[0]->translations->get('en')->items;
        $items[0]->full_image = null;
        $node = $doc->nodes[0];
        $doc = new Document([new Node($node->id, $node->type, props: $node->props, translations: new JsonObject(['en' => (object) ['label' => 'Gallery', 'items' => $items], 'de' => (object) ['items' => []]]), extensions: $node->extensions)]);
        $source = $this->page('gallery-copy', $doc);
        $editor = Livewire::test(PageEditor::class, ['record' => $source->id])->call('openClipboard', 'copy');
        $text = $editor->get('copiedSectionText');
        $editor->call('closeClipboard');
        $target = $this->page('gallery-destination');
        $destination = Livewire::test(PageEditor::class, ['record' => $target->id])->call('openClipboard', 'paste')->set('clipboardText', $text)->call('reviewClipboard')->assertSet('clipboardReferences.blocking', false)->call('pasteClipboard')->assertSet('state', 'saved');
        $copy = $target->fresh()->draft_content->nodes[1];
        $this->assertNotSame($node->id, $copy->id);
        $this->assertTrue(app(CanonicalJson::class)->equals($doc->nodes[0]->translations, $copy->translations));
        $editor->set('captureData.name', 'Reusable gallery')->call('captureSavedSection')->assertSet('state', 'saved');
        $saved = SavedSection::query()->sole();
        $snapshot = app(SavedSectionCommands::class)->snapshot(Filament::auth()->user(), $saved->id, $saved->current_version);
        app(CompositionCommands::class)->insert(Filament::auth()->user(), $target->id, $destination->get('leaseToken'), $destination->get('writeVersion'), new SourceSelection('saved-section', $saved->id, $snapshot->version, $snapshot->digest, 'en'), InsertPosition::end());
        $savedCopy = $target->fresh()->draft_content->nodes[2];
        $this->assertNotSame($copy->id, $savedCopy->id);
        $this->assertSame('{}', json_encode($savedCopy->translations->get('en')->items[0]->opaque->object));
        $this->assertSame([], $savedCopy->translations->get('de')->items);
    }

    private function document(): Document
    {
        return new Document([new Node('gallery', 'standard.gallery', props: new JsonObject(['columns' => 'two', 'lightbox' => true]), translations: new JsonObject(['en' => (object) ['label' => 'Gallery', 'items' => [(object) ['image' => StandardExamples::asset('landscape'), 'alt' => 'First image', 'full_image' => 'site-builder-media/unavailable.jpg', 'future' => 'first', 'opaque' => (object) ['object' => (object) [], 'list' => [], 'nullable' => null]], (object) ['image' => StandardExamples::asset('workspace'), 'alt' => 'Second image', 'future' => 'second']]], 'de' => (object) ['items' => []]]))]);
    }

    public function test_owned_batch_adapter_rechecks_files_public_visibility_permission_order_and_localized_metadata(): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Components\ComponentsServiceProvider::class))->getFileName(), 2).'/database/migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        config(['filesystems.disks.public.visibility' => 'public']);
        Storage::fake('public');
        $library = app(MediaLibrary::class);
        $first = $library->store(UploadedFile::fake()->image('first.png', 160, 100), ['en' => 'First', 'de' => 'Erstes']);
        $second = $library->store(UploadedFile::fake()->image('second.png', 200, 120), ['en' => 'Second']);
        $picker = new ComponentMediaPicker($library);
        $result = $picker->selectMany(Filament::auth()->user(), 'de', [$second->path, '/not-owned.jpg', $first->path]);
        $this->assertSame([0, 2], array_column($result->accepted, 'index'));
        $this->assertSame(['Second', 'Erstes'], array_column($result->accepted, 'alt'));
        $this->assertSame([200, 160], array_column($result->accepted, 'width'));
        $this->assertArrayHasKey(1, $result->failures);
        Storage::disk('public')->delete($second->path);
        $this->assertSame([], $picker->selectMany(Filament::auth()->user(), 'en', [$second->path])->accepted);
        $this->permissions['viewAny'] = false;
        $this->assertSame([], $picker->selectMany(Filament::auth()->user(), 'en', [$first->path])->accepted);
        $this->permissions['viewAny'] = true;
        config(['filesystems.disks.public.visibility' => 'private']);
        $this->assertSame([], $picker->selectMany(Filament::auth()->user(), 'en', [$first->path])->accepted);
    }

    private function picker(): object
    {
        $picker = new class implements BatchMediaPicker
        {
            public bool $denied = false;

            public function options(string $locale): array
            {
                return ['/one.jpg' => 'One', '/two.jpg' => 'Two'];
            }

            public function selectMany(Authenticatable $actor, string $locale, array $references): BatchMediaSelection
            {
                $accepted = $failures = [];
                foreach ($references as $index => $ref) {
                    if ($this->denied || $ref === '/denied.jpg') {
                        $failures[$index] = 'Unavailable selection';
                    } else {
                        $accepted[] = ['index' => $index, 'reference' => $ref, 'alt' => 'Selected '.$ref, 'width' => 100, 'height' => 80];
                    }
                }

                return new BatchMediaSelection($accepted, $failures);
            }
        };
        app()->instance(MediaPicker::class, $picker);

        return $picker;
    }
}
