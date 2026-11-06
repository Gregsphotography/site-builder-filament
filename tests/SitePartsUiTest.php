<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\SiteParts\CreateSitePart;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class SitePartsUiTest extends EditorUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installOptional('history-migrations');
        $this->installOptional('site-parts-migrations');
    }

    public function test_native_empty_root_choice_renders_and_moves_nested_resource_content_after_flushing_input(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Native root destination')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.columns')->assertSet('state', 'saved');
        $columns = $part->fresh()->draft_payload->document->nodes[0];
        $leaf = $columns->children[0]->children[0];
        $editor->call('selectNode', $leaf->id)->set('formData.heading', 'Pending nested resource content')->set('treeMoveParentId', '')->assertOk()
            ->call('moveToParent', 902)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 902 && $ack['focusNodeId'] === $leaf->id)->assertSet('state', 'saved');
        $document = $part->fresh()->draft_payload->document;
        $this->assertSame($leaf->id, $document->nodes[1]->id);
        $this->assertSame('Pending nested resource content', $document->nodes[1]->translations->get('en')->heading);
        $this->assertCount(2, $document->nodes[0]->children);
        $this->assertCount(0, $document->nodes[0]->children[0]->children);
    }

    public function test_resource_editor_is_independent_and_page_uses_only_explicit_immutable_pins(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Shared introduction')->set('data.kind', 'section')->call('create')->assertHasNoErrors();
        $part = SitePart::query()->sole();
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->assertSet('state', 'saved')
            ->set('formData.heading', 'Private resource introduction')->call('saveSelected')->assertSet('draftHistoryState.undoCount', 2);
        $this->assertSame(0, Page::query()->count());
        $this->get($editor->get('previewUrl'))->assertOk()->assertSee('Private resource introduction')->assertDontSee('site-builder-preview-context')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $editor->call('undoDraft')->assertSet('state', 'saved')->call('redoDraft')->assertSet('formData.heading', 'Private resource introduction')->call('publish')->assertSet('state', 'saved')->assertSet('draftHistoryState.undoCount', 0);
        $first = $part->fresh()->revisions()->sole();
        $page = $this->page('pinned-page');
        $pageEditor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('searchSiteParts')->call('reviewSitePart', $part->id, $first->id)->assertSet('sitePartReview.report.compatible', true)
            ->call('applySitePart')->assertSet('state', 'invalid')->set('sitePartConfirmed', true)->call('applySitePart')->assertSet('state', 'saved');
        $reference = $page->fresh()->draft_content->nodes[1];
        $this->assertSame('core.global-reference', $reference->type);
        $this->assertSame($first->id, $reference->props->get('revision')->revisionId);
        $pageEditor->call('publish')->assertSet('state', 'saved');
        $this->get('/pinned-page')->assertOk()->assertSee('Private resource introduction');
        $editor->set('formData.heading', 'New resource revision')->call('saveSelected')->call('publish')->assertSet('state', 'saved');
        $second = $part->fresh()->revisions()->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->get('/pinned-page')->assertOk()->assertSee('Private resource introduction')->assertDontSee('New resource revision');
        $pageEditor->call('selectNode', $reference->id)->call('reviewSitePart', $part->id, $second->id)->set('sitePartConfirmed', true)->call('applySitePart')->assertSet('state', 'saved');
        $this->assertSame($second->id, $page->fresh()->draft_content->nodes[1]->props->get('revision')->revisionId);
        $this->get($pageEditor->get('previewUrl'))->assertOk()->assertSee('New resource revision');
        $this->get('/pinned-page')->assertOk()->assertDontSee('New resource revision');
        $pageEditor->call('undoDraft')->assertSet('state', 'saved');
        $this->assertSame($first->id, $page->fresh()->draft_content->nodes[1]->props->get('revision')->revisionId);
        $pageEditor->call('selectNode', $part->fresh()->draft_payload->document->nodes[0]->id)->assertSet('state', 'invalid');
    }

    public function test_site_design_uses_resource_history_and_actual_authenticated_preview_without_a_scratch_page(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Business design')->set('data.kind', 'design')->set('data.theme', 'standard.base@1:business')->call('create')->assertHasNoErrors();
        $part = SitePart::query()->sole();
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->set('designData.width', 'narrow')->call('saveDesign')->assertSet('state', 'saved')->assertSet('draftHistoryState.undoCount', 1);
        $this->assertSame('narrow', $part->fresh()->draft_payload->design->get('base')->width);
        $editor->call('undoDraft')->assertSet('designData.width', null)->call('redoDraft')->assertSet('designData.width', 'narrow')->call('publish')->assertSet('state', 'saved');
        $url = $editor->get('previewUrl');
        $this->get($url)->assertOk()->assertSee('sb-d-width-narrow')->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDontSee('site-builder-preview-context');
        $this->get($url.'&revision=forged')->assertForbidden();
        $this->assertSame(0, Page::query()->count());
    }

    public function test_impact_review_stages_only_selected_authorized_drafts_after_its_own_confirmed_leases(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Published banner')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->set('formData.heading', 'Original banner')->call('saveSelected')->call('publish')->assertSet('state', 'saved');
        $old = $part->fresh()->revisions()->sole();
        $pages = [];
        foreach (['stage-one', 'protected-stage'] as $slug) {
            $page = $this->page($slug);
            $pageEditor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSitePart', $part->id, $old->id)->set('sitePartConfirmed', true)->call('applySitePart')->call('publish')->assertSet('state', 'saved');
            app(PageLocks::class)->release(auth()->user(), $page->id, $pageEditor->get('leaseToken'));
            $pages[] = $page;
        }
        $resource->set('formData.heading', 'Updated banner')->call('saveSelected')->call('publish')->assertSet('state', 'saved');
        $new = $part->fresh()->revisions()->firstOrFail();
        $this->permissions['view:'.$pages[1]->id] = false;
        $resource->call('reviewRevisionImpact', $old->id)->assertSet('impactReview.protectedUses', true);
        $report = $resource->get('impactReview');
        $this->assertSame([$pages[0]->id], array_column($report['targets'], 'pageId'));
        $this->assertStringNotContainsString('protected-stage', json_encode($report));
        $resource->set('stageNewRevision', $new->id)->set('stageSelectedPageIds', [$pages[0]->id])->call('prepareStage')->assertSet('state', 'saved')
            ->call('applyStage')->assertSet('state', 'invalid')->set('stageConfirmed', true)->call('applyStage', 701)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 701 && $ack['stagedPageIds'] === [$pages[0]->id])->assertSet('stageLeases', []);
        $this->assertSame($new->id, $pages[0]->fresh()->draft_content->nodes[1]->props->get('revision')->revisionId);
        $this->assertSame($old->id, $pages[0]->fresh()->published_content->nodes[1]->props->get('revision')->revisionId);
        $this->assertSame($old->id, $pages[1]->fresh()->draft_content->nodes[1]->props->get('revision')->revisionId);
        $this->assertSame(0, $pages[0]->editLock()->count());
    }

    public function test_resource_stale_version_reload_replaces_its_matching_live_lease_and_preserves_invalid_input(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Conflict resource')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->set('formData.heading', 'Accepted resource')->call('saveSelected');
        $oldToken = $editor->get('leaseToken');
        app(SitePartCommands::class)->saveMetadata(auth()->user(), $part->id, $oldToken, $editor->get('writeVersion'), ['name' => 'Concurrent resource name']);
        $editor->set('formData.heading', 'Retained resource input')->call('saveSelected')->assertSet('state', 'conflict')->assertSet('formData.heading', 'Retained resource input')
            ->call('reloadSaved')->assertSet('state', 'saved')->assertSet('partName', 'Concurrent resource name')->assertSet('formData.heading', 'Accepted resource')->assertSet('draftHistoryState.undoCount', 0);
        $this->assertNotSame($oldToken, $editor->get('leaseToken'));
        $editor->set('formData.heading', '')->call('saveSelected')->assertSet('state', 'saved');
        $this->permissions['update:'.$part->id] = false;
        $version = $part->fresh()->write_version;
        $editor->set('formData.heading', 'Forbidden input')->call('saveSelected')->assertForbidden();
        $this->assertSame($version, $part->fresh()->write_version);
    }

    public function test_header_preview_selection_uses_binding_enum_and_never_mounts_source_child_edit_addresses(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Site header')->set('data.kind', 'header')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->set('formData.heading', 'Immutable header content')->call('saveSelected')->call('publish')->assertSet('state', 'saved');
        $revision = $part->fresh()->revisions()->sole();
        $sourceNode = $part->fresh()->draft_payload->document->nodes[0]->id;
        $page = $this->page('header-page');
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('sitePartSlot', 'header')->call('searchSiteParts')->call('reviewSitePart', $part->id, $revision->id)->assertSet('sitePartReview.report.compatible', true)->set('sitePartConfirmed', true)->call('applySitePart')->assertSet('state', 'saved');
        $this->get($editor->get('previewUrl'))->assertOk()->assertSee('data-site-builder-binding="header"', false)->assertSee('Immutable header content')->assertDontSee('data-site-builder-node="'.$sourceNode.'"', false);
        $version = $page->fresh()->write_version;
        $editor->call('selectSitePartBinding', 'header', 801)->assertReturned(fn (array $ack): bool => $ack['ok'] && $ack['requestId'] === 801)->assertSet('selectedSitePartBinding', 'header')->assertSet('sitePartSlot', 'header')
            ->call('selectSitePartBinding', 'arbitrary-resource-id')->assertSet('state', 'invalid');
        $this->assertSame($version, $page->fresh()->write_version);
        $editor->call('selectNode', 'existing')->assertSet('selectedSitePartBinding', null);
    }

    public function test_one_stale_stage_target_rolls_back_the_batch_and_reload_releases_only_its_owned_page_leases(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Atomic staged resource')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->set('formData.heading', 'Old stage content')->call('saveSelected')->call('publish');
        $old = $part->fresh()->revisions()->sole();
        $pages = [];
        foreach (['atomic-one', 'atomic-two'] as $slug) {
            $page = $this->page($slug);
            $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSitePart', $part->id, $old->id)->set('sitePartConfirmed', true)->call('applySitePart')->assertSet('state', 'saved');
            app(PageLocks::class)->release(auth()->user(), $page->id, $editor->get('leaseToken'));
            $pages[] = $page;
        }
        $resource->set('formData.heading', 'New stage content')->call('saveSelected')->call('publish');
        $new = $part->fresh()->revisions()->firstOrFail();
        $resource->call('reviewRevisionImpact', $old->id)->set('stageSelectedPageIds', array_map(fn (Page $page): int => $page->id, $pages))->set('stageNewRevision', $new->id)->call('prepareStage')->assertSet('state', 'saved');
        $leases = $resource->get('stageLeases');
        app(PageCommands::class)->saveMetadata(auth()->user(), $pages[1]->id, $leases[$pages[1]->id], $pages[1]->fresh()->write_version, ['title' => 'Accepted competing title']);
        $firstVersion = $pages[0]->fresh()->write_version;
        $resource->set('stageConfirmed', true)->call('applyStage')->assertSet('state', 'conflict')->assertSet('stageSelectedPageIds', array_map(fn (Page $page): int => $page->id, $pages));
        $this->assertSame($firstVersion, $pages[0]->fresh()->write_version);
        foreach ($pages as $page) {
            $this->assertSame($old->id, $page->fresh()->draft_content->nodes[1]->props->get('revision')->revisionId);
        }
        $resource->call('reloadSaved')->assertSet('state', 'saved')->assertSet('stageLeases', []);
        foreach ($pages as $page) {
            $this->assertSame(0, $page->editLock()->count());
        }
        $this->assertSame('Accepted competing title', $pages[1]->fresh()->title);
    }

    public function test_unknown_site_part_payload_is_read_only_and_keeps_the_original_recovery_bytes(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Future payload')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $raw = '{"format":"laravel-site-builder/site-part","version":99,"kind":"section","future":{"object":{},"list":[]}}';
        DB::table('site_builder_site_parts')->where('id', $part->id)->update(['draft_payload' => $raw]);
        Livewire::test(SitePartEditor::class, ['record' => $part->id])->assertSet('unsupportedPayload', true)->assertSet('readOnly', true)->assertSet('payloadJson', $raw)->assertSet('previewUrl', '')->set('formData.heading', 'Not admitted')->call('saveSelected')->assertSet('state', 'conflict');
        $this->assertSame($raw, DB::table('site_builder_site_parts')->where('id', $part->id)->value('draft_payload'));
    }

    public function test_resource_pagehide_releases_only_its_own_reviewed_page_leases(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Release resource')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $editor = Livewire::test(SitePartEditor::class, ['record' => $part->id]);
        $own = $this->page('own-stage-lock');
        $foreign = $this->page('other-stage-lock');
        $ownLease = app(PageLocks::class)->acquire(auth()->user(), $own->id);
        $other = new GenericUser(['id' => 'another-owned-editor']);
        $foreignLease = app(PageLocks::class)->acquire($other, $foreign->id);
        $url = Filament::getCurrentPanel()->route('site-builder.part-release', ['part' => $part->id]);
        $this->post($url, ['token' => $editor->get('leaseToken'), 'targets' => [['pageId' => $own->id, 'token' => $ownLease->token], ['pageId' => $foreign->id, 'token' => $foreignLease->token]]])->assertNoContent();
        $this->assertSame(0, $own->editLock()->count());
        $this->assertSame(1, $foreign->editLock()->count());
        $this->assertSame(0, $part->editLock()->count());
        $this->assertSame(0, $own->fresh()->write_version);
    }

    public function test_withdrawal_requires_review_and_distinct_authority_and_an_unused_resource_can_be_deleted(): void
    {
        Livewire::test(CreateSitePart::class)->set('data.name', 'Withdrawal source')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')->set('formData.heading', 'Published withdrawal content')->call('saveSelected')->call('publish');
        $revision = $part->fresh()->revisions()->sole();
        $page = $this->page('withdrawal-page');
        Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSitePart', $part->id, $revision->id)->set('sitePartConfirmed', true)->call('applySitePart')->call('publish')->assertSet('state', 'saved');
        $resource->set('withdrawConfirmed', true)->set('withdrawReason', 'Review correction')->call('withdrawRevision', $revision->id)->assertSet('state', 'invalid');
        $this->assertNull($revision->fresh()->withdrawn_at);
        $resource->call('reviewRevisionImpact', $revision->id)->set('withdrawConfirmed', true)->set('withdrawReason', 'Review correction');
        $token = $resource->get('leaseToken');
        $this->permissions['withdraw'] = false;
        $resource->call('withdrawRevision', $revision->id)->assertForbidden();
        $this->assertNull($revision->fresh()->withdrawn_at);
        $this->permissions['withdraw'] = true;
        app(SitePartLocks::class)->release(auth()->user(), $part->id, $token);
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('reviewRevisionImpact', $revision->id)->set('withdrawConfirmed', true)->set('withdrawReason', 'Reviewed correction');
        $resource->call('withdrawRevision', $revision->id)->assertSet('state', 'saved')->assertSet('draftHistoryState.undoCount', 0);
        $this->assertNotNull($revision->fresh()->withdrawn_at);
        $this->get('/withdrawal-page')->assertStatus(503)->assertDontSee('Published withdrawal content');
        Livewire::test(CreateSitePart::class)->set('data.name', 'Unused removable resource')->set('data.kind', 'footer')->call('create');
        $unused = SitePart::query()->where('name', 'Unused removable resource')->sole();
        Livewire::test(SitePartEditor::class, ['record' => $unused->id])->set('deleteConfirmed', true)->call('deletePart')->assertRedirect(SitePartResource::getUrl('index'));
        $this->assertDatabaseMissing('site_builder_site_parts', ['id' => $unused->id]);
        $this->assertDatabaseHas('site_builder_site_parts', ['id' => $part->id]);
    }
}
