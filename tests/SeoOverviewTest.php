<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Livewire\Livewire;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\SeoAssessment;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Filament\Pages\SeoOverview;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\Resources\SiteParts\CreateSitePart;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class SeoOverviewTest extends EditorUiTestCase
{
    public function test_report_links_published_findings_to_fields_and_respects_visibility(): void
    {
        $first = $this->page('first');
        $second = $this->page('second');
        foreach ([$first, $second] as $page) {
            $page->forceFill(['title' => 'Shared title', 'status' => 'published', 'published_content' => $page->draft_content, 'seo' => ['en' => ['meta_description' => 'Same description']]])->save();
        }
        $hidden = $this->page('hidden-from-editor');
        $hidden->forceFill(['title' => 'Invisible', 'status' => 'published', 'published_content' => $hidden->draft_content, 'seo' => ['en' => ['meta_title' => 'Only visible once']]])->save();
        $third = $this->page('third');
        $third->forceFill(['title' => 'Only visible once', 'status' => 'published', 'published_content' => $third->draft_content])->save();
        $this->permissions['view:'.$hidden->id] = false;

        $screen = Livewire::test(SeoOverview::class)->assertOk()->assertDontSee('Invisible')
            ->set('language', 'en')->set('finding', 'duplicate_title')
            ->assertSee('2 page translations')->assertSee('Duplicate search title')->assertSee('Duplicate search description')
            ->assertSee('Shared title')->assertDontSee('Only visible once');
        $url = PageResource::getUrl('edit', ['record' => $first->id, 'locale' => 'en', 'panel' => 'settings', 'field' => 'meta_title']);
        $screen->assertSee(e($url), false);
        $this->permissions['view:'.$second->id] = false;
        $screen->call('$refresh')->assertSee('No pages match these filters.');
        $this->permissions['viewAny'] = false;
        $screen->call('$refresh')->assertForbidden();
    }

    public function test_draft_and_live_snapshots_have_independent_urls_and_fallback_findings(): void
    {
        $englishOnly = new JsonObject(['en' => ['heading' => 'Default heading']]);
        $published = new Document([
            new Node('intro', 'standard.text', translations: $englishOnly),
            new Node('hidden', 'standard.text', translations: $englishOnly, visibility: 'hidden'),
            new Node('blank', 'standard.text', translations: new JsonObject(['en' => ['heading' => 'Do not fall back'], 'de' => ['heading' => '']])),
        ]);
        $page = $this->page('live', new Document);
        $page->slugs()->create(['locale' => 'de', 'slug' => 'publiziert']);
        $settings = $page->publishedSettings();
        $settings->title = 'Staged title';
        $settings->slugs = (object) ['en' => 'staged', 'de' => 'entwurf'];
        $settings->seo = (object) ['de' => (object) ['meta_description' => 'Draft description', 'og_image' => '/draft.jpg']];
        $page->forceFill(['status' => 'published', 'published_content' => $published, 'draft_settings' => $settings, 'seo' => ['de' => ['noindex' => true]]])->save();

        $draft = $this->page('old-draft');
        $draft->forceFill(['draft_settings' => $settings])->save();
        [$live, $unpublished] = app(SeoAssessment::class)->rows([$page->fresh(), $draft->fresh()], 'de');
        $this->assertSame($page->title, $live['title']);
        $this->assertStringEndsWith('/de/publiziert', $live['url']);
        $this->assertSame(['missing_description', 'missing_image', 'noindex', 'translation_fallback'], array_column($live['issues'], 'code'));
        $this->assertSame(['panel' => 'sections', 'node' => 'intro', 'field' => 'heading'], $live['issues'][3]['target']);
        $this->assertSame('Staged title', $unpublished['title']);
        $this->assertStringEndsWith('/de/entwurf', $unpublished['url']);
        $this->assertSame([], $unpublished['issues']);

        $screen = Livewire::test(SeoOverview::class)->set('language', 'de')->set('finding', 'translation_fallback');
        $screen->assertSee('1 page translation')->assertDontSee('Staged title')
            ->assertSee(e(PageResource::getUrl('edit', ['record' => $page->id, 'locale' => 'de', 'panel' => 'sections', 'node' => 'intro', 'field' => 'heading'])), false);
        $screen->set('finding', '')->set('publication', 'draft')->assertSee('Staged title')->assertDontSee('publiziert');
    }

    public function test_noindex_unrouted_and_draft_entries_do_not_raise_duplicates_for_indexable_pages(): void
    {
        foreach (['live', 'unrouted', 'excluded', 'draft'] as $slug) {
            $page = $this->page($slug);
            $page->forceFill(['status' => $slug === 'draft' ? 'draft' : 'published', 'published_content' => $page->draft_content, 'title' => 'Common', 'seo' => ['en' => ['meta_description' => 'Common description', 'noindex' => $slug === 'excluded']]])->save();
            if ($slug === 'unrouted') {
                $page->slugs()->delete();
            }
        }
        $screen = Livewire::test(SeoOverview::class)->set('language', 'en')->set('finding', 'duplicate_title')->assertSee('0 page translations');
        $screen->set('finding', 'duplicate_description')->assertSee('0 page translations');
        $screen->set('finding', 'missing_url')->assertSee('1 page translation')->assertSee('Missing URL for this language');
        $screen->set('finding', 'noindex')->assertSee('1 page translation')->assertSee('Excluded from indexing');
    }

    public function test_shared_fallback_links_to_the_authorized_resource_field_in_the_correct_language(): void
    {
        $this->installOptional('site-parts-migrations');
        Livewire::test(CreateSitePart::class)->set('data.name', 'Shared banner')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')
            ->set('formData.heading', 'Shared heading')->call('publish')->assertSet('state', 'saved');
        $page = $this->page();
        Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSitePart', $part->id, $part->fresh()->current_revision_id)
            ->set('sitePartConfirmed', true)->call('applySitePart')->call('publish')->assertSet('state', 'saved');
        $params = ['locale' => 'de', 'node' => $resource->get('selectedId'), 'field' => 'heading'];
        $url = SitePartResource::getUrl('edit', ['record' => $part->id] + $params);
        $report = Livewire::test(SeoOverview::class)->set('language', 'de')->set('finding', 'translation_fallback')
            ->assertSee(e($url), false);
        Livewire::withQueryParams($params)->test(SitePartEditor::class, ['record' => $part->id])
            ->assertSet('locale', 'de')->assertSet('selectedId', $params['node'])->assertSet('initialField', 'formData.heading');
        $this->permissions['view:'.$part->id] = false;
        $report->call('$refresh')->assertDontSee(e($url), false);
    }
}
