<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Livewire\Livewire;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Filament\Contracts\MediaPicker;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class EditorialSettingsTest extends EditorUiTestCase
{
    public function test_localized_settings_links_open_the_field_and_changes_travel_with_the_publication(): void
    {
        $page = $this->page('launch');
        $page->slugs()->create(['locale' => 'de', 'slug' => 'start']);
        $page->forceFill(['seo' => (object) ['en' => (object) ['meta_title' => 'Launch']]])->save();
        app()->instance(MediaPicker::class, new class implements MediaPicker
        {
            public function options(string $locale): array
            {
                return ['/garden.jpg' => 'Garden photograph'];
            }
        });

        $editor = Livewire::withQueryParams(['locale' => 'de', 'panel' => 'settings', 'field' => 'meta_description'])
            ->test(PageEditor::class, ['record' => $page->id])
            ->assertSet('locale', 'de')->assertSet('initialPanel', 'settings')->assertSet('initialField', 'settingsData.meta_description')
            ->call('publish')->assertSet('state', 'saved');
        $oldRevision = $page->revisions()->firstOrFail();
        $editor->set('settingsData.meta_title', 'Neu im Garten')->set('settingsData.meta_description', 'Was im Garten wächst.')
            ->set('settingsData.social_image_choice', '/garden.jpg')->assertSet('settingsData.og_image', '/garden.jpg')
            ->set('settingsData.slug', 'garten')->call('saveSettings')->assertHasNoErrors()->assertSet('state', 'saved')
            ->assertSee('Search preview')->assertSee('Social preview')->assertSee('Neu im Garten')->assertSee('Was im Garten wächst.')
            ->assertSee('src="http://localhost/garden.jpg"', false);
        $this->get('/de/start')->assertOk()->assertDontSee('Neu im Garten');
        $this->get('/de/garten')->assertNotFound();
        $editor->call('changeLocale', 'en')->assertSet('settingsData.meta_title', 'Launch')
            ->set('settingsData.meta_description', 'English description')->call('saveSettings')
            ->call('changeLocale', 'de')->assertSet('settingsData.meta_title', 'Neu im Garten')->call('publish')->assertHasNoErrors();
        $this->get('/de/garten')->assertOk()->assertSee('Neu im Garten')->assertSee('property="og:image"', false);
        $this->get('/de/start')->assertStatus(301);
        $editor->call('restoreRevision', $oldRevision->id)->assertSet('settingsData.slug', 'start')
            ->assertSet('settingsData.meta_description', null)->assertSet('settingsData.og_image', null);
        $this->get('/de/garten')->assertOk();
    }

    public function test_content_link_opens_the_translated_node_and_invalid_targets_fall_back_safely(): void
    {
        $page = $this->page();
        Livewire::withQueryParams(['locale' => 'de', 'panel' => 'sections', 'node' => 'existing', 'field' => 'heading'])
            ->test(PageEditor::class, ['record' => $page->id])->assertSet('selectedId', 'existing')
            ->assertSet('initialField', 'formData.heading')->assertSet('locale', 'de');
        Livewire::withQueryParams(['locale' => ['de'], 'panel' => 'other', 'field' => ['slug']])
            ->test(PageEditor::class, ['record' => $page->id])->assertSet('initialPanel', 'sections')
            ->assertSet('initialField', null)->assertSet('locale', 'en');
    }

    public function test_content_only_history_retains_pending_search_settings_in_the_draft(): void
    {
        $page = $this->page();
        $legacy = PageRevision::factory()->create(['page_id' => $page->id, 'content' => $page->draft_content]);
        Livewire::test(PageEditor::class, ['record' => $page->id])
            ->set('settingsData.meta_description', 'Pending search copy')->call('restoreRevision', $legacy->id)
            ->assertHasNoErrors()->assertSet('settingsData.meta_description', 'Pending search copy');
        $this->assertSame('Pending search copy', data_get($page->fresh()->draftView()->seo, 'en.meta_description'));
    }
}
