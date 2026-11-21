<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Publishing\PageReview;
use SiteBuilder\Core\Publishing\PageReviews;
use SiteBuilder\Core\Routing\PreviewLinks;

trait ReviewsPublication
{
    public array $releaseTimes = [];

    public function requestClientReview(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->flushSettings();
            $this->assertDesignThemeSettled();
            app(PageReviews::class)->request($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion);
            $this->message = 'Review link ready. Share it with your client.';
        });
    }

    public function scheduleApprovedVersion(string $review, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($review): void {
            $this->editable();
            $this->flushSection();
            $this->flushSettings();
            $this->validate(['releaseTimes.'.$review => 'required|date_format:Y-m-d\TH:i']);
            $localTime = $this->releaseTimes[$review];
            $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $localTime, config('app.timezone'));
            if ($time->format('Y-m-d\TH:i') !== $localTime) {
                throw ValidationException::withMessages(['releaseTimes.'.$review => 'Choose a time that exists in the site timezone.']);
            }
            app(PageReviews::class)->schedule($this->actor(), $this->pageId, $review, $time, Filament::getAuthGuard());
            $this->message = 'Approved version scheduled.';
        });
    }

    public function publishApprovedVersion(string $review, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($review): void {
            $this->editable();
            $this->flushSection();
            $this->flushSettings();
            $result = app(PageCommands::class)->publishReview($this->actor(), $this->pageId, $review);
            $this->accept($result, true);
            $this->fillSettings($result->page);
            $this->message = 'The approved version is live. Later edits remain in the draft.';
        });
    }

    public function cancelClientReview(string $review, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($review): void {
            $this->editable();
            app(PageReviews::class)->cancel($this->actor(), $this->pageId, $review);
            $this->message = 'Review and any scheduled publication cancelled.';
        });
    }

    private function publishingViewData(Page $page): array
    {
        $gate = Gate::forUser($this->actor());
        if (! PageReview::available() || ! Route::has('site-builder.review') || ! $gate->allows('preview', $page)) {
            return ['clientReviewsAvailable' => false, 'canShareReview' => false, 'clientReviews' => []];
        }
        $list = PageReview::query()->where('page_id', $page->id)
            ->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'approved' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'draft_version', 'settings->title as review_title', 'status', 'reviewer', 'feedback', 'failure', 'expires_at', 'scheduled_for']);
        $reviews = app(PageReviews::class);

        $linksEnabled = PreviewLinks::epoch() !== '';
        $canManage = $gate->allows('update', $page);
        $canShare = $canManage && $linksEnabled;

        return ['clientReviewsAvailable' => true, 'canShareReview' => $canShare, 'canManageReview' => $canManage, 'reviewLinksEnabled' => $linksEnabled, 'clientReviews' => $list->map(fn (PageReview $review): array => [
            'record' => $review, 'url' => $canShare && $review->status === 'pending' && $review->expires_at > now()->timestamp ? $reviews->link($review) : null,
            'time' => $review->scheduled_for === null ? null : CarbonImmutable::createFromTimestamp($review->scheduled_for, config('app.timezone'))->format('Y-m-d H:i T'),
        ])];
    }
}
