<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Filament\Composition\PickerCatalog;
use SiteBuilder\Filament\Composition\PickerPreviewTickets;

trait ComposesDraft
{
    #[Locked]
    public bool $pickerOpen = false;

    #[Locked]
    public array $pickerPosition = ['kind' => 'end', 'anchor' => null, 'parentId' => null];

    #[Locked]
    public array $pickerReview = [];

    #[Locked]
    public array $pickerReferences = [];

    #[Locked]
    public string $pickerPreviewUrl = '';

    #[Locked]
    public array $replacementReview = [];

    #[Locked]
    public array $savedSectionReview = [];

    public array $pickerReferenceValues = [];

    #[Locked]
    public array $pickerReferenceBaseline = [];

    #[Locked]
    public string $pickerPreviewMessage = '';

    public array $captureData = ['name' => '', 'description' => '', 'category' => 'Saved sections', 'visibility' => 'private'];

    public array $savedSectionData = [];

    public bool $replaceConfirmed = false;

    public string $pickerSavedSearch = '';

    public string $pickerSavedCategory = '';

    #[Locked]
    public array $pickerSavedQuery = ['search' => '', 'category' => ''];

    #[Locked]
    public ?string $pickerSavedCursor = null;

    #[Locked]
    public ?string $pickerSavedNextCursor = null;

    #[Locked]
    public array $pickerSavedPrevious = [];

    #[Locked]
    public array $pickerCatalogueState = [];

    private array $responseExtras = [];

    public function openPicker(string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->openTreePicker(null, $position, $anchor, $requestId);
    }

    public function openTreePicker(?string $parentId = null, string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($parentId, $position, $anchor): void {
            $this->editable();
            $this->flushSection();
            $this->insertionPosition($position, $anchor, $parentId);
            $this->pickerPosition = ['kind' => $position, 'anchor' => $anchor, 'parentId' => $parentId];
            $this->clipboardOpen = false;
            $this->pickerOpen = true;
            $this->clearStartingPointReview();
        });
    }

    public function closePicker(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->pickerOpen = false;
            $this->clearStartingPointReview();
        });
    }

    public function searchSavedSections(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if (mb_strlen($this->pickerSavedSearch) > 255 || mb_strlen($this->pickerSavedCategory) > 255) {
                throw ValidationException::withMessages(['picker' => 'Use a search and category shorter than 256 characters.']);
            }
            $this->pickerSavedQuery = ['search' => trim($this->pickerSavedSearch), 'category' => trim($this->pickerSavedCategory)];
            $this->pickerSavedCursor = null;
            $this->pickerSavedPrevious = [];
        });
    }

    public function savedSectionsPage(bool $next = true, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($next): void {
            $this->editable();
            $this->flushSection();
            if ($next && $this->pickerSavedNextCursor !== null) {
                $this->pickerSavedPrevious[] = $this->pickerSavedCursor;
                $this->pickerSavedPrevious = array_slice($this->pickerSavedPrevious, -100);
                $this->pickerSavedCursor = $this->pickerSavedNextCursor;
            } elseif (! $next && $this->pickerSavedPrevious !== []) {
                $this->pickerSavedCursor = array_pop($this->pickerSavedPrevious);
            }
        });
    }

    public function reviewStartingPoint(string $key, ?string $variant = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($key, $variant): void {
            $this->editable();
            $this->flushSection();
            $catalog = app(PickerCatalog::class);
            $entry = $catalog->find($this->actor(), $key, $this->locale, $this->page(), $this->treeSlot($this->pickerPosition['parentId']));
            if ($variant === '') {
                $variant = null;
            }
            if ($variant === null && ! $entry['defaultVariantAvailable']) {
                $this->clearStartingPointReview();
                $this->pickerReview = [...$entry, 'variant' => null, 'reviewed' => false, 'digest' => '', 'sectionCount' => 0];
                $this->pickerReferences = ['occurrences' => [], 'diagnostics' => [], 'blocking' => true];
                $this->pickerPreviewMessage = $entry['insertable'] ? 'Choose a compatible variant to review its preview and references.' : $entry['variantDiagnostic'];

                return;
            }
            $source = $catalog->snapshot($this->actor(), $entry, $this->locale, $variant);
            $selection = new SourceSelection($source->kind, $source->id, $source->version, $source->digest, $this->locale, $variant);
            $references = app(CompositionCommands::class)->review($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $selection, null, $this->reviewedInsertPosition())->toArray();
            $this->pickerReview = [...$entry, 'kind' => $source->kind, 'id' => $source->id, 'version' => $source->version, 'digest' => $source->digest, 'locale' => $this->locale, 'variant' => $variant, 'reviewed' => true, 'sectionCount' => count($source->document->nodes), 'layoutDigest' => $catalog->layoutDigest($this->page()), 'destinationVersion' => $this->writeVersion];
            $this->pickerReferences = $references;
            $this->pickerReferenceValues = [];
            foreach ($this->pickerReferences['occurrences'] as $reference) {
                $this->pickerReferenceValues[$reference['key']] = ['value' => $reference['value'], 'keepUnverified' => false];
            }
            $this->pickerReferenceBaseline = $this->pickerReferenceValues;
            $this->replacementReview = [];
            $this->replaceConfirmed = false;
            $this->savedSectionReview = [];
            $this->savedSectionData = [];
            if ($entry['kind'] === 'saved-section') {
                $section = SavedSection::query()->findOrFail($entry['id']);
                Gate::forUser($this->actor())->authorize('view', $section);
                $this->savedSectionReview = ['id' => $section->id, 'writeVersion' => $section->write_version,
                    'canUpdate' => Gate::forUser($this->actor())->allows('update', $section),
                    'canShare' => Gate::forUser($this->actor())->allows('share', $section),
                    'canArchive' => Gate::forUser($this->actor())->allows('delete', $section)];
                $this->savedSectionData = ['name' => $section->name, 'description' => $section->description, 'category' => $section->category, 'visibility' => $section->visibility];
            }
            $this->refreshPickerPreview();
        });
    }

    public function reviewStartingPointReferences(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->pickerReferences = app(CompositionCommands::class)->review($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->sourceSelection(), $this->referenceMap(), $this->reviewedInsertPosition())->toArray();
            $this->pickerReferenceBaseline = $this->pickerReferenceValues;
            $this->pickerReview['destinationVersion'] = $this->writeVersion;
            $this->pickerReview['layoutDigest'] = app(PickerCatalog::class)->layoutDigest($this->page());
            $this->refreshPickerPreview();
            $this->replacementReview = [];
            $this->replaceConfirmed = false;
        });
    }

    public function prepareStartingPointReplacement(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->pickerReferences = app(CompositionCommands::class)->review($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->sourceSelection(), $this->referenceMap(), $this->reviewedInsertPosition())->toArray();
            $this->pickerReferenceBaseline = $this->pickerReferenceValues;
            $this->pickerReview['destinationVersion'] = $this->writeVersion;
            $this->pickerReview['layoutDigest'] = app(PickerCatalog::class)->layoutDigest($this->page());
            $this->refreshPickerPreview();
            $this->replacementReview = ['pageVersion' => $this->writeVersion, 'draftDigest' => $this->draftDigest, 'sectionCount' => count($this->document()->nodes), 'sourceDigest' => $this->pickerReview['digest']];
            $this->replaceConfirmed = false;
        });
    }

    public function applyStartingPoint(bool $replace = false, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($replace): void {
            $this->editable();
            $this->flushSection();
            if (! $this->same($this->pickerReferenceValues, $this->pickerReferenceBaseline)) {
                throw ValidationException::withMessages(['pickerReferences' => 'Check the changed reference choices before applying this starting point.']);
            }
            if (($this->pickerReview['destinationVersion'] ?? null) !== $this->writeVersion) {
                throw ValidationException::withMessages(['picker' => 'The accepted draft changed after this review. Review the starting point again before applying it.']);
            }
            if (! hash_equals($this->pickerReview['layoutDigest'] ?? '', app(PickerCatalog::class)->layoutDigest($this->page()))) {
                throw ValidationException::withMessages(['picker' => 'The page layout changed. Review this starting point again before applying it.']);
            }
            if ($replace) {
                if ($this->pickerPosition['parentId'] !== null) {
                    throw ValidationException::withMessages(['picker' => 'Replacing all draft sections is available from the page root only.']);
                }
                if (! $this->replaceConfirmed || $this->replacementReview === [] || $this->replacementReview['pageVersion'] !== $this->writeVersion || ! hash_equals($this->replacementReview['sourceDigest'], $this->pickerReview['digest'])) {
                    throw ValidationException::withMessages(['picker' => 'Review the current draft replacement and confirm it before continuing.']);
                }
                $result = app(CompositionCommands::class)->replaceDraftSections($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->replacementReview['draftDigest'], $this->sourceSelection(), $this->referenceMap(), true, $this->draftHistoryContext());
            } else {
                $result = app(CompositionCommands::class)->insert($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->sourceSelection(), $this->reviewedInsertPosition(), $this->referenceMap(), $this->draftHistoryContext());
            }
            $this->accept($result->mutation);
            $this->selectedId = $result->insertedIds[0] ?? null;
            $this->fillSection();
            $this->pickerOpen = false;
            $this->responseExtras = ['insertedIds' => $result->insertedIds, 'announcement' => count($result->insertedIds).' section(s) '.($replace ? 'replaced the draft sections.' : 'added to the draft.')];
            $this->message = $this->responseExtras['announcement'].' The live page is unchanged.';
            $this->clearStartingPointReview();
        });
    }

    public function captureSavedSection(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if ($this->selectedId === null) {
                throw ValidationException::withMessages(['savedSection' => 'Select a section to save first.']);
            }
            $section = app(SavedSectionCommands::class)->capture($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->selectedId, $this->captureData);
            $this->responseExtras = ['savedSection' => ['id' => $section->id, 'writeVersion' => $section->write_version, 'sourceVersion' => $section->current_version]];
            $this->captureData = ['name' => '', 'description' => '', 'category' => 'Saved sections', 'visibility' => 'private'];
            $this->message = 'Section saved to the library. Existing page copies remain independent.';
        });
    }

    public function updateSavedSection(bool $recapture = false, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($recapture): void {
            $this->editable();
            $this->flushSection();
            if ($this->savedSectionReview === []) {
                throw ValidationException::withMessages(['savedSection' => 'Review a saved section first.']);
            }
            $commands = app(SavedSectionCommands::class);
            $id = $this->savedSectionReview['id'];
            $version = $this->savedSectionReview['writeVersion'];
            if ($recapture) {
                if ($this->selectedId === null) {
                    throw ValidationException::withMessages(['savedSection' => 'Select a page section to recapture first.']);
                }
                $section = $commands->recapture($this->actor(), $id, $version, $this->pageId, $this->leaseToken, $this->writeVersion, $this->selectedId, $this->savedSectionData);
            } else {
                $section = $commands->update($this->actor(), $id, $version, $this->savedSectionData);
            }
            $this->responseExtras = ['savedSection' => ['id' => $section->id, 'writeVersion' => $section->write_version, 'sourceVersion' => $section->current_version]];
            $this->clearStartingPointReview();
            $this->message = 'Saved section updated. Review its new version before inserting it. Existing page copies are unchanged.';
        });
    }

    public function archiveSavedSection(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if ($this->savedSectionReview === []) {
                throw ValidationException::withMessages(['savedSection' => 'Review a saved section first.']);
            }
            $section = app(SavedSectionCommands::class)->archive($this->actor(), $this->savedSectionReview['id'], $this->savedSectionReview['writeVersion']);
            $this->responseExtras = ['savedSection' => ['id' => $section->id, 'writeVersion' => $section->write_version, 'sourceVersion' => $section->current_version]];
            $this->clearStartingPointReview();
            $this->message = 'Saved section archived. Existing page copies are unchanged.';
        });
    }

    private function sourceSelection(): SourceSelection
    {
        if ($this->pickerReview === [] || ! ($this->pickerReview['reviewed'] ?? false)) {
            throw ValidationException::withMessages(['picker' => 'Choose and review a starting point first.']);
        }

        return new SourceSelection($this->pickerReview['kind'], $this->pickerReview['id'], $this->pickerReview['version'], $this->pickerReview['digest'], $this->pickerReview['locale'], $this->pickerReview['variant']);
    }

    private function referenceMap(): ReferenceMap
    {
        $map = [];
        $keys = array_column($this->pickerReferences['occurrences'] ?? [], 'key');
        if (array_diff(array_keys($this->pickerReferenceValues), $keys) !== []) {
            throw ValidationException::withMessages(['pickerReferences' => 'An unknown reference choice was submitted.']);
        }
        foreach ($this->pickerReferences['occurrences'] ?? [] as $reference) {
            $choice = $this->pickerReferenceValues[$reference['key']] ?? [];
            $value = $choice['value'] ?? null;
            if ($reference['kind'] === 'page' && $value !== null && $value !== '') {
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($value === false) {
                    throw ValidationException::withMessages(['pickerReferences' => 'Page references require a positive page ID.']);
                }
            }
            $keep = $choice['keepUnverified'] ?? false;
            if (! is_bool($keep)) {
                throw ValidationException::withMessages(['pickerReferences' => 'Confirm unverified references using the keep checkbox.']);
            }
            $map[] = ['nodeId' => $reference['nodeId'], 'path' => $reference['path'], 'kind' => $reference['kind'], 'original' => $reference['original'], 'value' => $value === '' ? null : $value, 'keepUnverified' => $keep];
        }

        return ReferenceMap::fromArray($map);
    }

    private function insertionPosition(string $position, ?string $anchor, ?string $parentId = null): InsertPosition
    {
        $slot = $this->treeSlot($parentId);
        $treePosition = $this->treePosition($position, $anchor);
        $treePosition->index(app(TreeResolver::class)->children($this->document(), $slot));

        return InsertPosition::in($slot, $treePosition);
    }

    private function reviewedInsertPosition(): InsertPosition
    {
        return $this->insertionPosition($this->pickerPosition['kind'], $this->pickerPosition['anchor'], $this->pickerPosition['parentId']);
    }

    private function clearStartingPointReview(): void
    {
        $this->pickerReview = [];
        $this->pickerReferences = [];
        $this->pickerReferenceValues = [];
        $this->pickerReferenceBaseline = [];
        $this->pickerPreviewUrl = '';
        $this->pickerPreviewMessage = '';
        $this->replacementReview = [];
        $this->replaceConfirmed = false;
        $this->savedSectionReview = [];
        $this->savedSectionData = [];
    }

    private function refreshPickerPreview(): void
    {
        $this->pickerPreviewUrl = '';
        $this->pickerPreviewMessage = '';
        if (! ($this->pickerReview['previewAvailable'] ?? false)) {
            $this->pickerPreviewMessage = 'Preview unavailable for this component. Its registered defaults will be inserted.';

            return;
        }
        if ($this->pickerReferences['blocking'] ?? true) {
            $this->pickerPreviewMessage = 'Resolve and check the reference or theme compatibility choices before previewing this starting point.';

            return;
        }
        if (! Gate::forUser($this->actor())->allows('preview', $this->page())) {
            $this->pickerPreviewMessage = 'A preview is unavailable for this page or your permissions.';

            return;
        }
        $source = $this->sourceSelection();
        $ticket = app(PickerPreviewTickets::class)->issue($this->actor(), $this->pageId, $this->writeVersion, $this->pickerReview['key'], $source, $this->pickerReview['layoutDigest'], $this->referenceMap(), $this->pickerPosition);
        $this->pickerPreviewUrl = URL::temporarySignedRoute(Filament::getCurrentPanel()->generateRouteName('site-builder.picker-preview'), now()->addMinutes(10), [
            'page' => $this->pageId, 'entry' => $this->pickerReview['key'], 'kind' => $source->kind, 'source' => $source->id,
            'sourceVersion' => $source->version, 'digest' => $source->digest, 'locale' => $source->locale, 'variant' => $source->variant,
            'layoutDigest' => $this->pickerReview['layoutDigest'], 'position' => $this->pickerPosition['kind'], 'anchor' => $this->pickerPosition['anchor'], 'parentId' => $this->pickerPosition['parentId'], 'ticket' => $ticket, 'pageVersion' => (string) $this->writeVersion,
        ]);
    }

    private function compositionViewData(): array
    {
        $catalog = $this->pickerOpen ? app(PickerCatalog::class)->page($this->actor(), $this->locale, $this->pickerSavedQuery['search'], $this->pickerSavedQuery['category'], $this->pickerSavedCursor, $this->page(), $this->treeSlot($this->pickerPosition['parentId'])) : ['entries' => [], 'nextCursor' => null];
        $entries = $catalog['entries'];
        $this->pickerCatalogueState = array_map(fn (array $entry): array => ['kind' => $entry['kind'], 'category' => $entry['category'], 'search' => mb_strtolower(implode(' ', [$entry['label'], $entry['description'], $entry['category'], ...$entry['keywords']]))], $entries);
        $this->pickerSavedNextCursor = $catalog['nextCursor'];
        $savedAvailable = app(SavedSectionCommands::class)->available();

        return ['pickerEntries' => $entries, 'pickerCategories' => array_values(array_unique(array_column($entries, 'category'))),
            'pickerLayoutLabel' => Str::headline(Str::afterLast($this->page()->layout ?? config('site-builder.layout'), '.')),
            'savedSectionsAvailable' => $savedAvailable,
            'canCaptureSection' => $savedAvailable && $this->selectedId !== null && Gate::forUser($this->actor())->allows('create', SavedSection::class),
            'pickerPlacementLabel' => ($this->pickerPosition['parentId'] !== null ? 'Inside selected container · ' : '').match ($this->pickerPosition['kind']) {
                'before' => 'Before the selected section', 'after' => 'After the selected section', 'start' => 'At the beginning of this page', default => 'At the end of this page',
            }];
    }
}
