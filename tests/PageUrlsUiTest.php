<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Filament\Resources\Pages\CreatePage;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class PageUrlsUiTest extends EditorUiTestCase
{
    public function test_creation_shows_url_errors_and_can_retry_without_leaving_orphan_pages(): void
    {
        $this->page('taken');
        $create = Livewire::test(CreatePage::class)->set('data.title', 'New page');
        foreach (['taken', 'en/about', 'Hello World'] as $slug) {
            $create->set('data.slug', $slug)->call('create')->assertHasErrors('data.slug')
                ->assertSet('data.title', 'New page')->assertSet('data.slug', $slug);
            $this->assertSame(1, Page::query()->count());
        }
        $create->set('data.slug', '0')->call('create')->assertHasNoErrors()->assertRedirect();
        $this->assertSame('0', Page::query()->where('title', 'New page')->firstOrFail()->slugs()->sole()->slug);
    }

    public function test_invalid_settings_keep_input_lease_and_database_then_allow_correction(): void
    {
        $this->page('taken');
        $page = $this->page('original');
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('settingsData.title', 'Retained title');
        foreach (['taken', 'en/about', 'Hello World'] as $slug) {
            $editor->set('settingsData.slug', $slug)->call('saveSettings')->assertHasErrors('settingsData.slug')
                ->assertSet('state', 'invalid')->assertSet('readOnly', false)
                ->assertSet('settingsData.title', 'Retained title')->assertSet('settingsData.slug', $slug);
            $this->assertSame('Page original', $page->fresh()->title);
            $this->assertSame('original', $page->slugs()->sole()->slug);
            $this->assertSame(0, $page->fresh()->write_version);
            $this->assertSame(0, $page->redirects()->count());
        }
        $editor->set('settingsData.title', 'Page original')->set('settingsData.slug', 'original')
            ->call('saveSettings')->assertHasNoErrors()->assertSet('state', 'saved')->assertSet('message', '');
        $this->assertSame(0, $page->fresh()->write_version);
        $editor->set('settingsData.title', 'Retained title');
        $editor->set('settingsData.slug', '0')->call('saveSettings')->assertHasNoErrors()->assertSet('state', 'saved')->assertSet('readOnly', false);
        $this->assertSame('0', $page->slugs()->sole()->slug);
        $this->assertSame('Retained title', $page->fresh()->title);
        $editor->set('settingsData.slug', '')->call('saveSettings')->assertHasNoErrors()->assertSet('state', 'saved');
        $this->assertSame(0, $page->slugs()->count());
    }

    public function test_read_only_history_explains_access_even_when_the_store_is_installed(): void
    {
        $this->installOptional('history-migrations');
        $page = $this->page();
        app(PageLocks::class)->acquire(Filament::auth()->user(), $page->id, (string) Str::uuid());
        Livewire::test(PageEditor::class, ['record' => $page->id])->assertSet('readOnly', true)
            ->assertSet('draftHistoryState.available', false)
            ->assertSet('draftHistoryUnavailableMessage', 'Document undo is unavailable while this page is read only. Request editing access to start a history session.');
    }
}
