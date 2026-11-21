<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;
use SiteBuilder\Filament\Support\DraftAccessibility;

trait ReviewsAccessibility
{
    #[Locked]
    public ?array $accessibilityReview = null;

    public function reviewAccessibility(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->accessibilityReview = null;
            if ($this->documentJson === '') {
                throw ValidationException::withMessages(['accessibility' => 'The draft could not be read for this review.']);
            }
            $this->flushSection();
            $this->flushSettings();
            $page = $this->page();
            if ($page->write_version !== $this->writeVersion) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            $context = new RenderContext($this->locale, 'preview', $this->pageId, $page->draftLayout());
            $findings = app(DraftAccessibility::class)->review($this->document(), $context);
            $parts = collect($findings)->pluck('sharedPart')->filter()->unique();
            $visible = $parts->isEmpty() ? collect() : SitePart::findMany($parts)->filter(fn (SitePart $part): bool => Gate::forUser($this->actor())->allows('view', $part))->keyBy('id');
            foreach ($findings as &$finding) {
                if ($finding['mounted']) {
                    if ($visible->has($finding['sharedPart'])) {
                        $finding['url'] = SitePartResource::getUrl('edit', ['record' => $finding['sharedPart'], 'locale' => $this->locale, 'node' => $finding['node'], 'field' => $finding['path'][0], 'fieldPath' => count($finding['path']) > 1 ? $finding['path'] : null]);
                    }
                    unset($finding['node']);
                }
                unset($finding['sharedPart']);
            }
            unset($finding);
            $this->accessibilityReview = ['version' => $this->writeVersion, 'locale' => $this->locale, 'findings' => $findings];
        });
    }

    public function openAccessibilityFinding(int $index, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($index): void {
            $this->flushSection();
            $currentVersion = $this->page()->write_version;
            if ($currentVersion !== $this->writeVersion) {
                throw new WriteConflict('stale_version', $currentVersion);
            }
            $finding = $this->accessibilityReview['findings'][$index] ?? null;
            if (! $finding || $finding['mounted'] || $this->accessibilityReview['version'] !== $this->writeVersion || $this->accessibilityReview['locale'] !== $this->locale) {
                throw ValidationException::withMessages(['accessibility' => 'Run the review again for the current draft and language.']);
            }
            $location = app(TreeResolver::class)->nodes($this->document())[$finding['node']];
            $this->selectedId = $location->node->id;
            $this->treeMoveParentId = $location->parentId;
            $this->selectedSitePartBinding = null;
            $this->fillSection();
            $this->responseExtras = ['accessibilityField' => $this->linkedFormPath($finding['path'])];
        });
    }
}
