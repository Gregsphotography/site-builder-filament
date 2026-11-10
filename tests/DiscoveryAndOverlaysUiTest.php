<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Livewire\Livewire;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\OverlaySettings;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Discovery\DiscoveryMetadata;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Filament\Pages\PopupLibrary;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class DiscoveryAndOverlaysUiTest extends EditorUiTestCase
{
    public function test_discovery_fields_are_drafts_until_published_and_keep_other_locales(): void
    {
        $this->installOptional('discovery-migrations');
        $page = $this->page();
        $page->published_content = $page->draft_content;
        $page->status = 'published';
        $page->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $editor->set('discoveryData.summary', 'A published summary after approval')->set('discoveryData.tags', ['News', 'Studio'])->set('discoveryData.date', '2026-11-10')->call('saveDiscoverySettings')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertSame('A published summary after approval', DiscoveryMetadata::values($page->fresh()->draft_content, 'en')['summary']);
        $this->assertSame([], DiscoveryMetadata::values($page->fresh()->published_content, 'en'));
        $editor->call('changeLocale', 'de')->set('discoveryData.summary', 'Eine deutsche Zusammenfassung')->call('saveDiscoverySettings')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertSame(['News', 'Studio'], DiscoveryMetadata::values($page->fresh()->draft_content, 'en')['tags']);
        $editor->call('publish')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertSame('Eine deutsche Zusammenfassung', DiscoveryMetadata::values($page->fresh()->published_content, 'de')['summary']);
    }

    public function test_native_button_picker_pins_popup_revision_and_preserves_it_during_unrelated_edits(): void
    {
        $this->installOptional('site-parts-migrations');
        $actor = Filament::auth()->user();
        $part = app(SitePartCommands::class)->create($actor, 'overlay', ['name' => 'Reusable information'])->part;
        $lease = app(SitePartLocks::class)->acquire($actor, $part->id);
        $part = app(SitePartCommands::class)->publish($actor, $part->id, $lease->token, 0, app(CanonicalJson::class)->digest($part->draft_payload))->part;
        $page = $this->page('popup-actions', new Document([new Node('action', 'standard.cta', translations: new JsonObject(['en' => ['heading' => 'Get in touch', 'label' => 'Details', 'url' => '/contact']]))]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $editor->set('formData.overlay', $part->current_revision_id)->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $stored = $page->fresh()->draft_content->nodes[0]->props->get('overlay');
        $this->assertSame($part->current_revision_id, $stored->revisionId);
        $editor->set('formData.heading', 'Another heading')->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertEquals($stored, $page->fresh()->draft_content->nodes[0]->props->get('overlay'));
        $this->assertSame('/contact', $page->fresh()->draft_content->nodes[0]->translations->get('en')->url);
    }

    public function test_popup_editor_saves_presentation_into_draft_and_library_lists_it(): void
    {
        $this->installOptional('site-parts-migrations');
        $part = app(SitePartCommands::class)->create(Filament::auth()->user(), 'overlay', ['name' => 'A useful drawer'])->part;
        Livewire::test(SitePartEditor::class, ['record' => $part->id])->set('settingsData.presentation', 'drawer-right')->set('settingsData.size', 'large')->set('settingsData.overlay_title', 'Useful details')->call('saveSettings')->assertSet('state', 'saved')->assertHasNoErrors();
        $settings = OverlaySettings::values($part->fresh()->draft_payload->document, 'en');
        $this->assertSame(['presentation' => 'drawer-right', 'size' => 'large', 'title' => 'Useful details'], $settings);
        Livewire::test(PopupLibrary::class)->assertSee('A useful drawer')->assertSee('Draft — publish before attaching');
    }

    public function test_hotspot_positions_use_percent_controls_and_exact_integer_storage_without_losing_unknown_fields(): void
    {
        $document = new Document([new Node('hotspots', 'standard.image-hotspots', props: new JsonObject(['image' => '/image.jpg']), translations: new JsonObject(['en' => ['label' => 'Details', 'alt' => 'A landscape', 'items' => [['x' => 5000, 'y' => 6000, 'title' => 'The first point', 'body' => '<p>Details</p>', 'opaque' => ['retain' => true]]]]]))]);
        $page = $this->page('hotspots', $document);
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('Horizontal position (%)')->assertSee('Click the image or drag the marker.');
        $rows = $editor->get('formData.items');
        $key = array_key_first($rows);
        $this->assertEquals(50, $rows[$key]['x']);
        $editor->set('formData.items.'.$key.'.x', 42.75)->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $item = $page->fresh()->draft_content->nodes[0]->translations->get('en')->items[0];
        $this->assertSame(4275, $item->x);
        $this->assertSame(6000, $item->y);
        $this->assertTrue($item->opaque->retain);
    }

    public function test_switching_carousel_modes_preserves_authored_items_and_published_source_choices(): void
    {
        $defaults = app(ComponentRegistry::class)->resolve('standard.carousel')->defaults();
        $page = $this->page('carousel-modes', new Document([new Node('carousel', 'standard.carousel', props: $defaults->props, translations: $defaults->translations)]));
        $original = $page->draft_content->nodes[0]->translations->get('en')->items;
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $editor->set('formData.content_mode', 'dynamic')->set('formData.source', 'builder.pages')->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertEquals($original, $page->fresh()->draft_content->nodes[0]->translations->get('en')->items);
        $editor->set('formData.content_mode', 'manual')->call('saveSelected')->assertSet('state', 'saved')->assertHasNoErrors();
        $this->assertSame('builder.pages', $page->fresh()->draft_content->nodes[0]->props->get('source'));
        $this->assertEquals($original, $page->fresh()->draft_content->nodes[0]->translations->get('en')->items);
    }
}
