<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Livewire\Livewire;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\NavigationProvider;
use SiteBuilder\Core\Contracts\SiteMetadataProvider;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class SnapshotUiTest extends EditorUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installOptional('history-migrations');
        $this->installOptional('site-parts-migrations');
        config(['site-builder-components.snapshots.menus' => ['primary' => 'Main navigation']]);
        app()->instance(NavigationProvider::class, new class implements NavigationProvider
        {
            public function items(string $location, string $locale): array
            {
                return [['label' => 'Copied menu entry', 'url' => '/copied']];
            }
        });
    }

    public function test_page_source_review_cancel_confirm_and_undo_use_native_editor_without_implicit_publication(): void
    {
        $page = $this->page('snapshot-native', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('Copy from a source')
            ->call('loadSnapshotSources')->assertSet('state', 'saved')->assertCount('snapshotChoices', 1)
            ->call('reviewSnapshotSource', 'standard.menus', 'primary')->assertSet('state', 'saved')->assertSee('complete menu list');
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->call('cancelSnapshotSource')->assertSet('snapshotReview', []);
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->call('reviewSnapshotSource', 'standard.menus', 'primary')->set('snapshotConfirmed', true)->call('applySnapshotSource')->assertSet('state', 'saved')->assertSet('snapshotReview', []);
        $saved = $page->fresh();
        $this->assertSame(1, $saved->write_version);
        $this->assertSame('draft', $saved->status);
        $this->assertNull($saved->published_content);
        $this->assertSame('Copied menu entry', $saved->draft_content->nodes[0]->translations->get('en')->items[0]->label);
        $this->assertSame('opaque', $saved->draft_content->nodes[0]->translations->get('en')->items[0]->future);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame('Original', $page->fresh()->draft_content->nodes[0]->translations->get('en')->items[0]->label);
    }

    public function test_resource_source_copy_uses_resource_history_and_locale_changes_clear_review(): void
    {
        $part = app(SitePartCommands::class)->create(Filament::auth()->user(), 'header', ['name' => 'Editable header'], SitePartPayload::document('header', $this->document()))->part;
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('loadSnapshotSources')->assertCount('snapshotChoices', 1)
            ->call('reviewSnapshotSource', 'standard.menus', 'primary')->call('changeLocale', 'de')->assertSet('snapshotReview', [])
            ->call('changeLocale', 'en')->call('reviewSnapshotSource', 'standard.menus', 'primary')->set('snapshotConfirmed', true)->call('applySnapshotSource')->assertSet('state', 'saved');
        $this->assertSame('Copied menu entry', $part->fresh()->draft_payload->document->nodes[0]->translations->get('en')->items[0]->label);
        $this->assertNull($part->fresh()->current_revision_id);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame('Original', $part->fresh()->draft_payload->document->nodes[0]->translations->get('en')->items[0]->label);
    }

    public function test_local_edit_after_review_requires_a_new_review_and_denied_catalog_does_not_write(): void
    {
        $page = $this->page('snapshot-stale', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSnapshotSource', 'standard.menus', 'primary')
            ->set('formData.label', 'Locally edited landmark')->set('snapshotConfirmed', true)->call('applySnapshotSource')->assertSet('state', 'conflict');
        $this->assertSame('Original', $page->fresh()->draft_content->nodes[0]->translations->get('en')->items[0]->label);
        $this->assertSame('Locally edited landmark', $page->fresh()->draft_content->nodes[0]->translations->get('en')->label);
        $editor->call('reloadSaved')->assertSet('snapshotReview', []);
        $this->permissions['copySiteBuilderMenu'] = false;
        $editor->call('loadSnapshotSources')->assertSet('snapshotChoices', []);
        $this->assertSame(1, $page->fresh()->write_version);
    }

    public function test_native_nested_navigation_form_preserves_unmounted_fields_when_reordered_and_edits_only_one_locale(): void
    {
        $page = $this->page('snapshot-form', $this->document());
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $items = $editor->get('formData.items');
        $key = array_key_first($items);
        $items[$key]['label'] = 'Edited native label';
        $keys = array_keys($items);
        $editor->set('formData.items', [$keys[1] => $items[$keys[1]], $keys[0] => $items[$keys[0]]])->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $savedItems = $page->fresh()->draft_content->nodes[0]->translations->get('en')->items;
        $this->assertSame('Second', $savedItems[0]->label);
        $this->assertSame('second opaque', $savedItems[0]->future);
        $item = $savedItems[1];
        $this->assertSame('Edited native label', $item->label);
        $this->assertSame('opaque', $item->future);
        $this->assertSame('Child', $item->children[0]->label);
        $editor->call('changeLocale', 'de')->assertSet('formData.label', 'Deutsch');
        $this->assertSame([], $page->fresh()->draft_content->nodes[0]->translations->get('de')->items);
    }

    public function test_installed_header_pattern_inserts_independent_nested_nodes_and_undoes_as_one_resource_action(): void
    {
        $part = app(SitePartCommands::class)->create(Filament::auth()->user(), 'header', ['name' => 'Pattern header'])->part;
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->assertSee('Insert Editable header')
            ->call('insertInstalledPattern', 'pattern:standard.header')->assertSet('state', 'saved');
        $document = $part->fresh()->draft_payload->document;
        $this->assertSame('standard.container', $document->nodes[0]->type);
        $this->assertSame(['standard.brand', 'standard.navigation'], array_map(fn ($node) => $node->type, $document->nodes[0]->children));
        $this->assertSame('pattern:standard.header', $document->extensions->get('site-builder.copy')->entries[0]->sourceId);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame([], $part->fresh()->draft_payload->document->nodes);
    }

    private function document(): Document
    {
        return new Document([new Node('nav', 'standard.navigation', props: new JsonObject(['orientation' => 'horizontal']), translations: new JsonObject(['en' => (object) ['label' => 'Original menu', 'items' => [(object) ['label' => 'Original', 'url' => '/original', 'page' => null, 'future' => 'opaque', 'children' => [(object) ['label' => 'Child', 'url' => '/child', 'page' => null, 'children' => []]]], (object) ['label' => 'Second', 'url' => '/second', 'page' => null, 'future' => 'second opaque', 'children' => []]]], 'de' => (object) ['label' => 'Deutsch', 'items' => []]]))]);
    }

    public function test_unverified_host_logo_requires_explicit_reference_acknowledgement_before_copy(): void
    {
        config(['site-builder-components.snapshots.metadata' => ['name' => 'name', 'logo' => 'logo']]);
        app()->instance(SiteMetadataProvider::class, new class implements SiteMetadataProvider
        {
            public function metadata(string $locale): JsonObject
            {
                return new JsonObject(['name' => 'Copied identity', 'logo' => '/host-unverified-logo.webp']);
            }
        });
        $page = $this->page('brand-reference', new Document([new Node('brand', 'standard.brand', props: new JsonObject(['variant' => 'both']), translations: new JsonObject(['en' => (object) ['name' => 'Old identity']]))]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSnapshotSource', 'standard.metadata', 'site')->assertSet('snapshotReview.references.blocking', true);
        $entry = $editor->get('snapshotReview.references.occurrences')[0];
        $this->assertSame('unverified', $entry['status']);
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->set('snapshotKeepUnverified.'.$entry['key'], true)->call('reviewSnapshotMappings')->assertSet('snapshotReview.references.blocking', false)->assertSee('(unset)')
            ->set('snapshotConfirmed', true)->call('applySnapshotSource')->assertSet('state', 'saved');
        $this->assertSame('/host-unverified-logo.webp', $page->fresh()->draft_content->nodes[0]->translations->get('en')->logo);
        $this->assertSame('Copied identity', $page->fresh()->draft_content->nodes[0]->translations->get('en')->name);
    }

    public function test_navigation_survives_structured_clipboard_and_independent_saved_section_insertion(): void
    {
        $this->installOptional('clipboard-migrations');
        $this->installOptional('saved-sections-migrations');
        $source = $this->page('portable-navigation', $this->document());
        $sourceEditor = Livewire::test(PageEditor::class, ['record' => $source->id])->call('openClipboard', 'copy')->assertSet('state', 'saved');
        $text = $sourceEditor->get('copiedSectionText');
        $sourceEditor->call('closeClipboard');
        $destination = $this->page('portable-destination');
        $editor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openClipboard', 'paste')
            ->set('clipboardText', $text)->call('reviewClipboard')->assertSet('clipboardReferences.blocking', false)
            ->call('pasteClipboard')->assertSet('state', 'saved')->assertSet('draftHistoryState.undoCount', 1);
        $copy = $destination->fresh()->draft_content->nodes[1];
        $this->assertSame('standard.navigation', $copy->type);
        $this->assertNotSame('nav', $copy->id);
        $this->assertSame('opaque', $copy->translations->get('en')->items[0]->future);
        $this->assertSame('Child', $copy->translations->get('en')->items[0]->children[0]->label);
        $this->assertSame([], $copy->translations->get('de')->items);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertCount(1, $destination->fresh()->draft_content->nodes);
        $sourceEditor->set('captureData.name', 'Portable navigation')->call('captureSavedSection')->assertSet('state', 'saved');
        $saved = SavedSection::query()->sole();
        $snapshot = app(SavedSectionCommands::class)->snapshot(Filament::auth()->user(), $saved->id, $saved->current_version);
        app(CompositionCommands::class)->insert(Filament::auth()->user(), $destination->id, $editor->get('leaseToken'), $editor->get('writeVersion'),
            new SourceSelection('saved-section', $saved->id, $snapshot->version, $snapshot->digest, 'en'), InsertPosition::end());
        $savedCopy = $destination->fresh()->draft_content->nodes[1];
        $this->assertNotSame('nav', $savedCopy->id);
        $this->assertNotSame($copy->id, $savedCopy->id);
        $this->assertTrue(app(CanonicalJson::class)->equals($copy->translations, $savedCopy->translations));
    }
}
