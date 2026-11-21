<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Menu;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageIncomingLinks;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\Resources\Pages\TrashPages;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

final class PageTrashUiTest extends EditorUiTestCase
{
    public function test_review_trash_and_restore_preserve_content_and_urls_without_republishing(): void
    {
        $target = $this->page('target');
        $target->forceFill(['published_content' => $target->draft_content, 'status' => 'published', 'published_at' => now()])->save();
        $target->redirects()->create(['locale' => 'en', 'from_slug' => 'previous', 'status' => 301]);
        PageRevision::factory()->create(['page_id' => $target->id]);
        $actor = Filament::auth()->user();
        $preview = app(PreviewLinks::class)->create($actor, $target->id, 'en');

        $typed = new Document([new Node('link', 'standard.cta', translations: new JsonObject(['en' => ['heading' => 'Next', 'page' => $target->id]]))]);
        $source = $this->page('referrer', $typed);
        $source->forceFill(['published_content' => $typed, 'status' => 'published'])->save();
        Menu::create(['location' => 'primary', 'items' => ['en' => [['children' => [['label' => 'Old path', 'url' => '/previous?from=menu']]]]]]);
        $rich = new Document([new Node('rich', 'standard.text', translations: new JsonObject(['en' => ['body' => '<p><a href="/target#details">Details</a></p>']]))]);
        $this->page('rich-text-link', $rich);
        $private = $this->page('hidden-page', $typed);
        $this->permissions['view:'.$private->id] = false;
        $this->page('external-page', new Document([new Node('link', 'standard.cta', translations: new JsonObject(['en' => ['url' => 'https://another.example/target']]))]));

        $this->installOptional('site-parts-migrations');
        $payload = SitePartPayload::document('header', $typed);
        $part = app(SitePartCommands::class)->create($actor, 'header', ['name' => 'Global header'], $payload);
        $partLock = app(SitePartLocks::class)->acquire($actor, $part->part->id);
        app(SitePartCommands::class)->publish($actor, $part->part->id, $partLock->token, $part->part->write_version, $part->draftDigest);

        $editor = Livewire::test(PageEditor::class, ['record' => $target->id]);
        $editor->call('reviewIncomingLinks')->assertSee('Page referrer')->assertSee('Page rich-text-link')
            ->assertSee('primary')->assertSee('Global header')->assertSee('Revision 1')
            ->assertDontSee('Page hidden-page')->assertDontSee('Page external-page');
        $editor->set('deleteConfirmed', true)->call('deletePage')->assertRedirect(PageResource::getUrl('trash'));
        $this->get('/target')->assertNotFound();
        $this->get('/previous')->assertNotFound();
        $this->get($preview)->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/target');
        $this->assertNull(Page::find($target->id));
        $trashed = Page::trash()->findOrFail($target->id);
        $this->assertSame(1, $trashed->revisions()->count());
        $this->assertNull($trashed->editLock);
        try {
            app(PageCommands::class)->create($actor, ['title' => 'New owner'], ['en' => 'target']);
            $this->fail('A retained page URL was reassigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slugs', $exception->errors());
        }
        Livewire::test(TrashPages::class)->assertSee($target->title)->assertSee('Restore to draft')
            ->call('restorePage', $target->id, $trashed->write_version - 1)->assertSee($target->title)
            ->call('restorePage', $target->id, $trashed->write_version)->assertDontSee($target->title);
        $restored = Page::findOrFail($target->id);
        $this->assertSame('draft', $restored->status);
        $this->assertTrue(app(CanonicalJson::class)->equals($target->draft_content, $restored->draft_content));
        $this->assertSame($target->slugs()->sole()->id, $restored->slugs()->sole()->id);
        $this->get('/target')->assertNotFound();
        Livewire::test(PageEditor::class, ['record' => $restored->id])->call('publish')->assertHasNoErrors();
        $this->get('/target')->assertOk();
        $this->get('/previous')->assertRedirect('/target');
    }

    public function test_incoming_links_recognize_prefixed_language_and_home_aliases(): void
    {
        config(['site-builder.routes.prefix' => 'pages']);
        $target = $this->page('home');
        $target->slugs()->create(['locale' => 'de', 'slug' => 'home']);
        $target->redirects()->create(['locale' => 'en', 'from_slug' => 'previous', 'status' => 301]);
        $aliases = ['/pages/home', '/pages/en', '/pages/en/home', '/pages/de/home/', '/pages/en/previous', 'http://localhost:80/pages/en/previous?tab=details#heading'];
        foreach ($aliases as $index => $url) {
            Menu::create(['location' => 'alias-'.$index, 'items' => ['en' => [['label' => 'Old bookmark', 'url' => $url]]]]);
        }
        foreach (['/en/home', '/pages/fr/home', 'http://localhost:8443/pages/en/home'] as $index => $url) {
            Menu::create(['location' => 'other-'.$index, 'items' => ['en' => [['label' => 'Other destination', 'url' => $url]]]]);
        }
        $rows = app(PageIncomingLinks::class)->report(Filament::auth()->user(), $target);
        $this->assertCount(count($aliases), $rows);

        $ordinary = $this->page('products/detail');
        Menu::create(['location' => 'footer', 'items' => ['en' => [['label' => 'Localized URL', 'url' => '/pages/en/products/detail']]]]);
        $rows = app(PageIncomingLinks::class)->report(Filament::auth()->user(), $ordinary);
        $this->assertSame(['footer'], array_column($rows, 'name'));
    }

    public function test_trash_listing_and_restore_recheck_per_page_access(): void
    {
        $hidden = $this->page('private-trash');
        $hidden->forceFill(['status' => 'trashed'])->save();
        $visible = $this->page('view-only-trash');
        $visible->forceFill(['status' => 'trashed'])->save();
        $this->permissions['view:'.$hidden->id] = false;
        $this->permissions['restore:'.$visible->id] = false;
        Livewire::test(TrashPages::class)->assertSee($visible->title)->assertDontSee($hidden->title)
            ->assertDontSee('>Restore to draft<', false)->call('restorePage', $visible->id, 0)->assertForbidden();
        Livewire::test(TrashPages::class)->call('restorePage', $hidden->id, 0)->assertForbidden();
        $this->assertSame(2, Page::trash()->count());
    }
}
