<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Livewire\Livewire;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class TreeUiTest extends EditorUiTestCase
{
    public function test_tabs_and_accordion_have_guarded_child_creation_and_editable_nested_content(): void
    {
        foreach (['standard.tabs' => ['standard.tab-panel', 'Add tab panel'], 'standard.accordion' => ['standard.accordion-item', 'Add disclosure item']] as $type => [$childType, $label]) {
            $page = $this->page('panels-'.str_replace('.', '-', $type));
            $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('addTreeSection', $type)->assertSet('state', 'saved')->assertSee($label);
            $root = $page->fresh()->draft_content->nodes[1];
            $editor->call('addTreeSection', $childType, $root->id)->assertSet('state', 'saved');
            $this->assertCount(3, $page->fresh()->draft_content->nodes[1]->children);
            $panel = $page->fresh()->draft_content->nodes[1]->children[2];
            $editor->call('addTreeSection', 'standard.heading', $panel->id)->assertSet('state', 'saved');
            $this->assertSame('standard.heading', $page->fresh()->draft_content->nodes[1]->children[2]->children[1]->type);
            $version = $page->fresh()->write_version;
            $editor->call('addTreeSection', $childType)->assertSet('state', 'invalid');
            $this->assertSame($version, $page->fresh()->write_version);
        }
    }

    public function test_nested_native_fields_insert_preview_and_moves_preserve_identity_locales_and_ancestor_design(): void
    {
        $this->installOptional('history-migrations');
        $page = $this->page(document: new Document([
            new Node('container', 'standard.container', children: [new Node('child', 'standard.text', props: new JsonObject(['opaque' => (object) ['object' => (object) [], 'list' => []]]), translations: new JsonObject(['en' => (object) ['heading' => 'Nested original'], 'de' => (object) ['heading' => 'Deutsch bleibt']]))], extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'base' => (object) ['space' => 'xl', 'align' => 'center']]]])),
            new Node('sibling', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Root sibling']])),
        ], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'standard.base', 'version' => 1, 'preset' => 'business']]]])));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('selectNode', 'child')->assertSet('selectedId', 'child')->set('formData.heading', 'Nested accepted')->call('saveSelected')->assertSet('state', 'saved');
        $child = $page->fresh()->draft_content->nodes[0]->children[0];
        $this->assertSame('Nested accepted', $child->translations->get('en')->heading);
        $this->assertSame('Deutsch bleibt', $child->translations->get('de')->heading);
        $this->assertInstanceOf(\stdClass::class, $child->props->get('opaque')->object);
        $editor->call('openTreePicker', 'container', 'after', 'child')->call('reviewStartingPoint', 'component:standard.image')->assertSet('pickerReferences.blocking', false);
        $this->get($editor->get('pickerPreviewUrl'))->assertOk()->assertSee('sb-d-space-xl')->assertSee('sb-d-align-center')->assertDontSee('Root sibling');
        $editor->call('applyStartingPoint', false, 601)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 601 && count($ack['insertedIds']) === 1);
        $document = $page->fresh()->draft_content;
        $this->assertCount(2, $document->nodes);
        $this->assertCount(2, $document->nodes[0]->children);
        $inserted = $document->nodes[0]->children[1]->id;
        $this->assertSame($inserted, $editor->get('selectedId'));
        $editor->call('moveSectionOut', $inserted)->assertSet('state', 'saved');
        $this->assertSame($inserted, $page->fresh()->draft_content->nodes[1]->id);
        $editor->call('moveSectionIn', $inserted)->assertSet('state', 'saved');
        $this->assertSame($inserted, $page->fresh()->draft_content->nodes[0]->children[1]->id);
        $version = $page->fresh()->write_version;
        $editor->call('moveTreeSection', 'container', 'child', 'end')->assertSet('state', 'invalid');
        $this->assertSame($version, $page->fresh()->write_version);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame($inserted, $page->fresh()->draft_content->nodes[1]->id);
    }

    public function test_columns_have_guarded_fresh_defaults_and_child_only_choices_never_escape_their_parent(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('addTreeSection', 'standard.columns')->assertSet('state', 'saved');
        $columns = $page->fresh()->draft_content->nodes[1];
        $this->assertCount(2, $columns->children);
        $this->assertCount(1, $columns->children[0]->children);
        $ids = array_keys(app(TreeResolver::class)->nodes($page->fresh()->draft_content));
        $this->assertCount(count($ids), array_unique($ids));
        $editor->call('openTreePicker', $columns->id)->assertSee('does not fit the selected parent');
        $editor->call('addTreeSection', 'standard.column', $columns->id)->assertSet('state', 'saved')->call('addTreeSection', 'standard.column', $columns->id)->assertSet('state', 'saved');
        $this->assertCount(4, $page->fresh()->draft_content->nodes[1]->children);
        $version = $page->fresh()->write_version;
        $editor->call('addTreeSection', 'standard.column', $columns->id)->assertSet('state', 'invalid')->call('moveSectionOut', $columns->children[0]->id)->assertSet('state', 'invalid');
        $this->assertSame($version, $page->fresh()->write_version);
        $editor->call('closePicker')->call('addTreeSection', 'standard.column')->assertSet('state', 'invalid');
        $this->assertSame($version, $page->fresh()->write_version);
    }

    public function test_structured_clipboard_reviews_and_pastes_into_an_explicit_child_slot(): void
    {
        $this->installOptional('clipboard-migrations');
        $this->installOptional('history-migrations');
        $source = $this->page('nested-copy-source', new Document([new Node('source-container', 'standard.container', children: [new Node('source-child', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Copied nested content'], 'de' => (object) ['heading' => 'Kopie Deutsch']]))])]));
        $copy = Livewire::test(PageEditor::class, ['record' => $source->id])->call('selectNode', 'source-child')->call('openClipboard', 'copy')->assertSet('state', 'saved');
        $destination = $this->page('nested-copy-destination', new Document([new Node('destination-container', 'standard.container', children: [new Node('destination-child', 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Existing child']]))])]));
        $editor = Livewire::test(PageEditor::class, ['record' => $destination->id])->call('openTreeClipboard', 'paste', 'destination-container', 'after', 'destination-child')->set('clipboardText', $copy->get('copiedSectionText'))->call('reviewClipboard')->assertSet('clipboardReferences.blocking', false)
            ->set('clipboardPlacement', 'start')->call('pasteClipboard')->assertSet('state', 'invalid')->call('reviewClipboard')->call('pasteClipboard')->assertSet('state', 'saved');
        $document = $destination->fresh()->draft_content;
        $this->assertCount(1, $document->nodes);
        $this->assertCount(2, $document->nodes[0]->children);
        $inserted = $document->nodes[0]->children[0];
        $this->assertSame('Copied nested content', $inserted->translations->get('en')->heading);
        $this->assertSame('Kopie Deutsch', $inserted->translations->get('de')->heading);
        $this->assertNotSame('source-child', $inserted->id);
        $editor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertCount(1, $destination->fresh()->draft_content->nodes[0]->children);
    }

    public function test_navigation_keeps_all_rows_but_actions_follow_only_the_accepted_selection(): void
    {
        $page = $this->page(document: new Document([new Node('first', 'standard.text'), new Node('second', 'standard.text'), new Node('third', 'standard.text')]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $this->assertSame(1, substr_count($editor->html(), 'class="sb-section-tools"'));
        foreach (['first', 'second', 'third'] as $id) {
            $editor->assertSee('data-node-id="'.$id.'"', false);
        }
        $editor->call('selectNode', 'second')->assertSet('selectedId', 'second');
        $this->assertSame(1, substr_count($editor->html(), 'class="sb-section-tools"'));
        $editor->assertSee('Structure tools for 2. Text')->assertDontSee('Structure tools for 1. Text');
        $editor->call('moveSectionIn', 'second')->assertSet('state', 'invalid');
        $this->assertSame(0, $page->fresh()->write_version);
    }

    public function test_direct_parent_choice_moves_a_column_leaf_to_root_without_an_invalid_intermediate_slot(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('addTreeSection', 'standard.columns');
        $columns = $page->fresh()->draft_content->nodes[1];
        $leaf = $columns->children[0]->children[0];
        $editor->call('selectNode', $leaf->id)->set('treeMoveParentId', '')->assertOk()->assertSee('Move to end of chosen parent')->call('moveToParent', 901)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 901 && $ack['focusNodeId'] === $leaf->id);
        $this->assertSame($leaf->id, $page->fresh()->draft_content->nodes[2]->id);
        $this->assertCount(0, $page->fresh()->draft_content->nodes[1]->children[0]->children);
        $this->assertCount(2, $page->fresh()->draft_content->nodes[1]->children);
        $editor->set('treeMoveParentId', $columns->children[1]->id)->call('moveToParent')->assertSet('state', 'saved');
        $this->assertSame($leaf->id, $page->fresh()->draft_content->nodes[1]->children[1]->children[1]->id);
    }
}
