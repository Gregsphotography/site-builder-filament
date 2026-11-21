<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SiteBuilder\Core\Publishing\PageReview;
use SiteBuilder\Core\Publishing\PageReviews;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class ClientReviewUiTest extends EditorUiTestCase
{
    public function test_editor_can_request_review_and_publish_the_approved_version_while_preserving_further_edits(): void
    {
        $page = $this->page('live-url');
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('Client review')
            ->set('settingsData.meta_title', 'Approved title')->set('settingsData.slug', 'reviewed-url')
            ->call('requestClientReview')->assertHasNoErrors()->assertSee('Open review');
        $review = PageReview::sole();
        $this->post(app(PageReviews::class)->link($review), ['decision' => 'approved', 'reviewer' => 'Kim'])->assertOk();
        $editor->call('$refresh')->assertSee('Approved')->assertSee('Kim')->assertSee('Publish approved version now');
        $editor->set('settingsData.meta_title', 'Later title')->set('settingsData.slug', 'later-url')
            ->call('publishApprovedVersion', $review->id)->assertHasNoErrors()->assertSee('Published')->assertSet('settingsData.slug', 'later-url');
        $this->get('/reviewed-url')->assertOk()->assertSee('Approved title')->assertDontSee('Later title');
        $this->get('/later-url')->assertNotFound();
    }

    public function test_closed_change_requests_keep_the_feedback_in_the_editor(): void
    {
        $page = $this->page('revisions');
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('requestClientReview');
        $review = PageReview::sole();
        $this->post(app(PageReviews::class)->link($review), ['decision' => 'changes_requested', 'reviewer' => 'Sam', 'feedback' => 'Revise the opening.'])->assertOk();
        $editor->call('$refresh')->assertSee('Changes requested')->assertSee('Revise the opening.')->assertSee('Sam')->assertDontSee('Open review');
    }

    public function test_an_editor_with_an_existing_review_remains_available_when_the_host_disables_links(): void
    {
        $page = $this->page('disabled');
        Livewire::test(PageEditor::class, ['record' => $page->id])->call('requestClientReview');
        $review = PageReview::sole();
        config(['site-builder.preview.epoch' => '']);
        Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('Client review links are disabled')
            ->assertSee('Pending')->assertDontSee('Create client review link')->assertDontSee('Open review')->assertSet('previewUrl', '');
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_preview_only_collaborators_can_see_review_status_without_a_decision_link(): void
    {
        $page = $this->page('restricted');
        Livewire::test(PageEditor::class, ['record' => $page->id])->call('requestClientReview');
        $review = PageReview::sole();
        $this->permissions['update:'.$page->id] = false;
        Livewire::test(PageEditor::class, ['record' => $page->id])->assertSet('readOnly', true)->assertSee('Pending')
            ->assertDontSee('Create client review link')->assertDontSee('Open review')
            ->assertDontSee(app(PageReviews::class)->link($review))->assertDontSee('Cancel review');
    }

    public function test_an_open_editor_refreshes_the_client_decision_and_schedules_in_the_site_timezone(): void
    {
        config(['auth.providers.users.model' => ReviewPanelAccount::class, 'app.timezone' => 'Europe/Zurich']);
        Schema::create('review_panel_accounts', function (Blueprint $table): void {
            $table->id();
        });
        $actor = new ReviewPanelAccount;
        $actor->save();
        $this->actingAs($actor);
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'Europe/Zurich'));
        $page = $this->page('new-campaign');
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('requestClientReview');
        $review = PageReview::sole();
        $this->post(app(PageReviews::class)->link($review), ['decision' => 'approved', 'reviewer' => 'Pat'])->assertOk();
        $editor->call('$refresh')->assertSee('Pat')->assertSee('Schedule approved version')
            ->set('releaseTimes.'.$review->id, '2026-03-29T02:30')->call('scheduleApprovedVersion', $review->id)
            ->assertSet('state', 'invalid')->assertSee('Choose a time that exists')
            ->set('releaseTimes.'.$review->id, '2026-03-29T09:45')->call('scheduleApprovedVersion', $review->id)
            ->assertSet('state', 'saved')->assertSee('2026-03-29 09:45 CEST');
        $this->assertSame('2026-03-29 07:45', CarbonImmutable::createFromTimestampUTC($review->fresh()->scheduled_for)->format('Y-m-d H:i'));
    }
}

class ReviewPanelAccount extends User
{
    public $timestamps = false;
}
