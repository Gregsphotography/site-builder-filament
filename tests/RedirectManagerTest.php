<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Routing\ManagedRedirects;
use SiteBuilder\Filament\Pages\RedirectManager;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

final class RedirectManagerTest extends EditorUiTestCase
{
    private function destination(string $slug): Page
    {
        $page = $this->page($slug);
        $page->forceFill(['status' => 'published', 'published_content' => $page->draft_content])->save();

        return $page;
    }

    public function test_resolve_observed_path_edit_existing_rule_and_remove_through_panel(): void
    {
        $first = $this->destination('first-destination');
        $second = $this->destination('second-destination');
        $this->get('/old-file.htm?contact=private')->assertNotFound();
        $miss = DB::table('site_builder_missing_paths')->sole();
        $panel = Livewire::test(RedirectManager::class)->assertSee('/old-file.htm')->assertDontSee('contact=private')
            ->call('resolveMissing', $miss->id)->assertSet('source', 'old-file.htm')->set('target', $first->id)->call('save')->assertHasNoErrors();
        $rule = PageRedirect::sole();
        $this->get('/old-file.htm')->assertRedirect('/first-destination')->assertStatus(301);
        $panel->call('edit', $rule->id)->set('target', $second->id)->set('status', 307)->call('save')->assertHasNoErrors();
        $this->get('/old-file.htm')->assertRedirect('/second-destination')->assertStatus(307);
        $panel->call('edit', $rule->id)->call('remove')->assertHasNoErrors();
        $this->get('/old-file.htm')->assertNotFound();
    }

    public function test_report_and_mutations_recheck_global_and_per_page_permissions(): void
    {
        $this->permissions[ManagedRedirects::GATE] = false;
        Livewire::test(RedirectManager::class)->assertForbidden();
        $this->permissions[ManagedRedirects::GATE] = true;
        $public = $this->destination('public-destination');
        $private = $this->destination('private-destination');
        $rule = $private->redirects()->create(['locale' => 'en', 'from_slug' => 'private-rule', 'status' => 301]);
        $this->permissions['view:'.$private->id] = false;
        Livewire::test(RedirectManager::class)->assertDontSee('private-destination')->assertDontSee('private-rule')
            ->call('edit', $rule->id)->assertForbidden();
        $this->permissions['publish:'.$public->id] = false;
        Livewire::test(RedirectManager::class)->set('source', 'forged')->set('target', $public->id)->call('save')->assertForbidden();
        $this->assertSame(1, PageRedirect::count());
    }
}
