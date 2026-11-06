<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\PageCompositionCommands;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartHistory;
use SiteBuilder\Filament\Composition\PickerCatalog;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;

trait SitePartsDraft
{
    #[Locked]
    public ?string $selectedSitePartBinding = null;

    public string $sitePartSlot = 'section';

    public string $sitePartSearch = '';

    #[Locked]
    public array $sitePartQuery = ['search' => '', 'slot' => 'section'];

    #[Locked]
    public ?string $sitePartCursor = null;

    #[Locked]
    public ?string $sitePartNextCursor = null;

    #[Locked]
    public array $sitePartPrevious = [];

    #[Locked]
    public array $sitePartReview = [];

    public bool $sitePartConfirmed = false;

    public ?string $sitePartParentId = null;

    public string $sitePartPlacement = 'end';

    public ?string $sitePartAnchor = null;

    public function selectSitePartBinding(string $binding, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($binding): void {
            $this->flushSection();
            if (! in_array($binding, ['header', 'footer', 'siteDesign'], true) || CompositionData::payload($this->document())?->{$binding} === null) {
                throw ValidationException::withMessages(['sitePart' => 'This page binding is no longer available.']);
            }
            $this->selectedSitePartBinding = $binding;
            $this->sitePartSlot = $binding;
            $this->sitePartQuery = ['search' => '', 'slot' => $binding];
            $this->sitePartCursor = null;
            $this->sitePartPrevious = [];
            $this->sitePartReview = [];
            $this->sitePartConfirmed = false;
        });
    }

    public function searchSiteParts(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->partKind($this->sitePartSlot);
            if (mb_strlen($this->sitePartSearch) > 255) {
                throw ValidationException::withMessages(['sitePart' => 'Use a shorter resource search.']);
            } $this->sitePartQuery = ['search' => trim($this->sitePartSearch), 'slot' => $this->sitePartSlot];
            $this->sitePartCursor = null;
            $this->sitePartPrevious = [];
            $this->sitePartReview = [];
            $this->sitePartConfirmed = false;
        });
    }

    public function sitePartsPage(bool $next = true, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($next): void {
            $this->editable();
            $this->flushSection();
            if ($next && $this->sitePartNextCursor !== null) {
                $this->sitePartPrevious[] = $this->sitePartCursor;
                $this->sitePartPrevious = array_slice($this->sitePartPrevious, -100);
                $this->sitePartCursor = $this->sitePartNextCursor;
            } elseif (! $next && $this->sitePartPrevious !== []) {
                $this->sitePartCursor = array_pop($this->sitePartPrevious);
            }
        });
    }

    public function reviewSitePart(string $resourceId, string $revisionId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($resourceId, $revisionId): void {
            $this->editable();
            $this->flushSection();
            $catalog = app(SitePartCatalog::class);
            $part = $catalog->find($this->actor(), $resourceId);
            $reference = $catalog->reference($this->actor(), $revisionId);
            if ($reference->resourceId !== $part->id || $part->kind !== $this->partKind($this->sitePartSlot)) {
                throw ValidationException::withMessages(['sitePart' => 'Choose a published revision of the selected resource kind.']);
            }
            $nodeId = $this->sitePartSlot === 'section' && $this->selectedNode()?->type === 'core.global-reference' ? $this->selectedId : null;
            $position = $this->insertionPosition($this->sitePartPlacement, $this->sitePartAnchor, $this->sitePartParentId);
            $commands = app(PageCompositionCommands::class);
            $report = $nodeId !== null ? $commands->reviewReplaceReference($this->actor(), $this->pageId, $nodeId, $reference)
                : ($this->sitePartSlot === 'section' ? $commands->reviewInsertReference($this->actor(), $this->pageId, $reference, $position) : $commands->reviewBind($this->actor(), $this->pageId, $this->sitePartSlot, $reference));
            $this->sitePartReview = ['slot' => $this->sitePartSlot, 'nodeId' => $nodeId, 'reference' => (array) $reference->jsonSerialize(), 'name' => $part->name, 'kind' => $part->kind, 'version' => $this->writeVersion, 'digest' => $this->draftDigest, 'layoutDigest' => app(PickerCatalog::class)->layoutDigest($this->page()), 'position' => ['parentId' => $this->sitePartParentId, 'kind' => $this->sitePartPlacement, 'anchor' => $this->sitePartAnchor], 'report' => $report,
                'previewUrl' => Gate::forUser($this->actor())->allows('preview', $part) ? URL::temporarySignedRoute(Filament::getCurrentPanel()->generateRouteName('site-builder.part-preview'), now()->addMinutes(10), ['part' => $part->id, 'locale' => $this->locale, 'revision' => $revisionId]) : null];
            $this->sitePartConfirmed = false;
        });
    }

    public function reviewClearSitePart(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if ($this->sitePartSlot === 'section') {
                throw ValidationException::withMessages(['sitePart' => 'Use the physical reference section’s Remove action to remove it.']);
            } $this->partKind($this->sitePartSlot);
            $report = app(PageCompositionCommands::class)->reviewBind($this->actor(), $this->pageId, $this->sitePartSlot, null);
            $this->sitePartReview = ['slot' => $this->sitePartSlot, 'nodeId' => null, 'reference' => null, 'name' => 'Clear '.$this->sitePartSlot.' binding', 'kind' => $this->partKind($this->sitePartSlot), 'version' => $this->writeVersion, 'digest' => $this->draftDigest, 'layoutDigest' => app(PickerCatalog::class)->layoutDigest($this->page()), 'report' => $report, 'previewUrl' => null];
            $this->sitePartConfirmed = false;
        });
    }

    public function applySitePart(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $review = $this->sitePartReview;
            if (! $this->sitePartConfirmed || $review === [] || $review['slot'] !== $this->sitePartSlot || $review['version'] !== $this->writeVersion || ! hash_equals($review['digest'], $this->draftDigest) || ! hash_equals($review['layoutDigest'], app(PickerCatalog::class)->layoutDigest($this->page())) || ($review['report']['blocking'] ?? false)) {
                throw ValidationException::withMessages(['sitePart' => 'Review and confirm this pin against the current accepted page draft.']);
            }
            $reference = $review['reference'] === null ? null : PublishedPartReference::from((object) $review['reference']);
            if ($reference !== null) {
                app(SitePartCatalog::class)->reference($this->actor(), $reference->revisionId);
            }
            $commands = app(PageCompositionCommands::class);
            $beforeIds = array_keys(app(TreeResolver::class)->nodes($this->document()));
            if ($review['slot'] === 'section') {
                if ($reference === null) {
                    throw ValidationException::withMessages(['sitePart' => 'Choose a published section revision.']);
                }
                if ($review['nodeId'] !== null) {
                    $result = $commands->replaceReference($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $review['nodeId'], $reference, $this->draftHistoryContext());
                } else {
                    $result = $commands->insertReference($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $reference, $this->insertionPosition($review['position']['kind'], $review['position']['anchor'], $review['position']['parentId']), $this->draftHistoryContext());
                }
            } else {
                $result = $commands->bind($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $review['slot'], $reference, $this->draftHistoryContext());
            }
            $this->accept($result);
            $newIds = array_values(array_diff(array_keys(app(TreeResolver::class)->nodes($this->document())), $beforeIds));
            if ($newIds !== []) {
                $this->selectedId = $newIds[0];
                $this->responseExtras = ['insertedIds' => [$newIds[0]]];
            }
            $this->fillSection();
            $this->sitePartReview = [];
            $this->sitePartConfirmed = false;
            $this->message = 'Pinned resource revision saved to the page draft. Review the destination preview and publish explicitly.';
        });
    }

    private function partKind(string $slot): string
    {
        return match ($slot) {
            'section' => 'section','header' => 'header','footer' => 'footer','siteDesign' => 'design',default => throw ValidationException::withMessages(['sitePart' => 'Choose section, header, footer or site design.'])
        };
    }

    private function sitePartsViewData(): array
    {
        $catalog = app(SitePartCatalog::class);
        $available = $catalog->available();
        $canBrowse = $available && Gate::forUser($this->actor())->allows('viewAny', SitePart::class);
        $entries = [];
        $bindings = [];
        $bindingDiagnostic = '';
        if ($canBrowse) {
            $page = $catalog->searchPage($this->actor(), $this->sitePartQuery['search'], $this->partKind($this->sitePartQuery['slot']), $this->sitePartCursor);
            $this->sitePartNextCursor = $page['nextCursor'];
            foreach ($page['entries'] as $part) {
                if ($part->current_revision_id !== null && Gate::forUser($this->actor())->allows('use', $part)) {
                    $revisions = app(SitePartHistory::class)->revisions($this->actor(), $part->id, 20)->filter(fn ($revision): bool => $revision->withdrawn_at === null);
                    $entries[] = ['id' => $part->id, 'name' => $part->name, 'description' => $part->description, 'kind' => $part->kind, 'currentRevisionId' => $part->current_revision_id, 'editUrl' => SitePartResource::getUrl('edit', ['record' => $part->id]), 'revisions' => $revisions];
                }
            }
        }
        if ($available && $this->documentJson !== '') {
            try {
                $references = CompositionData::references($this->document());
            } catch (\InvalidArgumentException) {
                $references = [];
                $bindingDiagnostic = 'Unsupported global-reference data is retained. A reviewed adapter or migration is required.';
            }
            foreach ($references as $path => $binding) {
                $reference = $binding['reference'];
                $part = SitePart::query()->find($reference->resourceId);
                $canView = $part !== null && Gate::forUser($this->actor())->allows('view', $part);
                $bindings[] = ['path' => $path, 'kind' => $binding['kind'], 'nodeId' => $binding['nodeId'], 'reference' => (array) $reference->jsonSerialize(), 'name' => $canView ? $part->name : 'Unavailable resource', 'editUrl' => $canView ? SitePartResource::getUrl('edit', ['record' => $part->id]) : null];
            }
        }

        return ['sitePartsAvailable' => $available, 'sitePartsCanBrowse' => $canBrowse, 'sitePartEntries' => $entries, 'sitePartBindings' => $bindings, 'sitePartBindingDiagnostic' => $bindingDiagnostic, 'sitePartsIndexUrl' => $canBrowse ? SitePartResource::getUrl('index') : null];
    }
}
