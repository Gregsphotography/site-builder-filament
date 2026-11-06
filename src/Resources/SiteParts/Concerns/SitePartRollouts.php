<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Composition\PageCompositionCommands;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartImpacts;

trait SitePartRollouts
{
    #[Locked]
    public array $impactReview = [];

    public string $stageNewRevision = '';

    public array $stageSelectedPageIds = [];

    public bool $stageConfirmed = false;

    #[Locked]
    public array $stageReview = [];

    #[Locked]
    public array $stageLeases = [];

    public function reviewRevisionImpact(string $revisionId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($revisionId): void {
            $this->releaseStageLeases();
            $revision = $this->part()->revisions()->findOrFail($revisionId);
            $this->impactReview = app(SitePartImpacts::class)->report($this->actor(), $revision->reference());
            $this->stageReview = [];
            $this->stageNewRevision = '';
            $this->stageSelectedPageIds = [];
            $this->stageConfirmed = false;
            $this->withdrawConfirmed = false;
        }, false);
    }

    public function prepareStage(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->releaseStageLeases();
            $old = $this->impactReference();
            $new = app(SitePartCatalog::class)->reference($this->actor(), $this->stageNewRevision);
            if ($new->resourceId !== $old->resourceId || $new->revisionId === $old->revisionId) {
                throw ValidationException::withMessages(['stage' => 'Choose another published revision of this same resource.']);
            }
            $ids = $this->selectedStageIds();
            $known = array_column($this->impactReview['targets'] ?? [], null, 'pageId');
            $targets = DB::transaction(function () use ($ids, $known): array {
                $prepared = [];
                foreach ($ids as $id) {
                    $reviewed = $known[$id] ?? null;
                    if ($reviewed === null || ! $reviewed['canStage']) {
                        throw ValidationException::withMessages(['stage' => 'Select only authorized draft targets from the reviewed impact report.']);
                    }
                    $page = Page::query()->lockForUpdate()->findOrFail($id);
                    Gate::forUser($this->actor())->authorize('update', $page);
                    if ($page->write_version !== $reviewed['writeVersion'] || ! hash_equals($reviewed['draftDigest'], app(CanonicalJson::class)->digest($page->draft_content))) {
                        throw new WriteConflict('stale_version', $page->write_version);
                    }
                    $lease = app(PageLocks::class)->acquire($this->actor(), $id);
                    $prepared[] = ['pageId' => $id, 'title' => $reviewed['title'], 'expectedVersion' => $page->write_version, 'expectedDigest' => $reviewed['draftDigest'], 'token' => $lease->token];
                }

                return $prepared;
            });
            $this->stageLeases = array_column($targets, 'token', 'pageId');
            $this->stageReview = ['old' => (array) $old->jsonSerialize(), 'new' => (array) $new->jsonSerialize(), 'targets' => array_map(fn (array $target): array => array_diff_key($target, ['token' => true]), $targets)];
            $this->stageConfirmed = false;
            $this->message = 'Separate page editing access acquired. Review at most 20 draft changes, then confirm staging. Live pages stay unchanged.';
        }, false);
    }

    public function applyStage(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            if (! $this->stageConfirmed || $this->stageReview === [] || $this->stageNewRevision !== $this->stageReview['new']['revisionId'] || $this->selectedStageIds() !== array_column($this->stageReview['targets'], 'pageId') || ! app(CanonicalJson::class)->equals($this->impactReview['reference'] ?? null, $this->stageReview['old'])) {
                throw ValidationException::withMessages(['stage' => 'Review and confirm the exact selected drafts and immutable revisions before staging.']);
            }
            $targets = [];
            foreach ($this->stageReview['targets'] as $target) {
                $token = $this->stageLeases[$target['pageId']] ?? null;
                if (! is_string($token)) {
                    throw ValidationException::withMessages(['stage' => 'The separate page editing access is unavailable. Prepare this stage again.']);
                }
                $targets[] = ['pageId' => $target['pageId'], 'leaseToken' => $token, 'expectedVersion' => $target['expectedVersion'], 'expectedDigest' => $target['expectedDigest']];
            }
            $old = PublishedPartReference::from($this->stageReview['old']);
            $new = PublishedPartReference::from($this->stageReview['new']);
            $results = app(PageCompositionCommands::class)->stage($this->actor(), $targets, $old, $new, true);
            $this->releaseStageLeases();
            $this->stageReview = [];
            $this->stageConfirmed = false;
            $this->stageSelectedPageIds = [];
            $this->impactReview = app(SitePartImpacts::class)->report($this->actor(), $new);
            $this->message = count($results).' page draft(s) staged. Live pages and this resource draft are unchanged. Review each destination and publish it explicitly.';
            $this->responseExtras = ['stagedPageIds' => array_map(fn ($result): int => $result->page->id, $results)];
        }, false);
    }

    public function cancelStage(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->releaseStageLeases();
            $this->stageReview = [];
            $this->stageConfirmed = false;
        }, false);
    }

    private function impactReference(): PublishedPartReference
    {
        if ($this->impactReview === []) {
            throw ValidationException::withMessages(['impact' => 'Review the immutable revision’s authorized dependencies first.']);
        }
        $reference = PublishedPartReference::from($this->impactReview['reference']);
        $revision = $this->part()->revisions()->findOrFail($reference->revisionId);
        if (! app(CanonicalJson::class)->equals($reference, $revision->reference())) {
            throw ValidationException::withMessages(['impact' => 'The reviewed immutable revision changed. Reload its impact report.']);
        }

        return $reference;
    }

    private function selectedStageIds(): array
    {
        if (! array_is_list($this->stageSelectedPageIds) || $this->stageSelectedPageIds === [] || count($this->stageSelectedPageIds) > 20) {
            throw ValidationException::withMessages(['stage' => 'Select between one and twenty authorized page drafts.']);
        }
        $ids = [];
        foreach ($this->stageSelectedPageIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || in_array($id, $ids, true)) {
                throw ValidationException::withMessages(['stage' => 'Select each valid destination page only once.']);
            } $ids[] = $id;
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function releaseStageLeases(): void
    {
        foreach ($this->stageLeases as $pageId => $token) {
            try {
                app(PageLocks::class)->release($this->actor(), (int) $pageId, $token);
            } catch (WriteConflict|AuthorizationException) { /* A replaced/revoked lease expires under its own owner; never release another owner’s access. */
            }
        }
        $this->stageLeases = [];
    }
}
