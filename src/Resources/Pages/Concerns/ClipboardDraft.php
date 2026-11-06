<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Filament\Composition\PickerCatalog;

trait ClipboardDraft
{
    #[Locked]
    public bool $clipboardOpen = false;

    #[Locked]
    public string $clipboardMode = 'paste';

    #[Locked]
    public string $copiedSectionText = '';

    #[Locked]
    public array $copiedSectionSummary = [];

    public string $clipboardText = '';

    public string $clipboardPlacement = 'end';

    public ?string $clipboardAnchor = null;

    public ?string $clipboardParentId = null;

    #[Locked]
    public array $clipboardReview = [];

    #[Locked]
    public array $clipboardReferences = [];

    public array $clipboardReferenceValues = [];

    #[Locked]
    public array $clipboardReferenceBaseline = [];

    public function openClipboard(string $mode = 'paste', string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->openTreeClipboard($mode, null, $position, $anchor, $requestId);
    }

    public function openTreeClipboard(string $mode = 'paste', ?string $parentId = null, string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($mode, $parentId, $position, $anchor): void {
            $this->editable();
            $this->flushSection();
            if (! in_array($mode, ['copy', 'paste'], true) || ! app(ClipboardCommands::class)->available()) {
                throw ValidationException::withMessages(['clipboard' => 'Structured cross-page copy needs the optional clipboard store. Ordinary page editing remains available.']);
            }
            $this->insertionPosition($position, $anchor, $parentId);
            if ($mode === 'copy') {
                if ($this->selectedId === null) {
                    throw ValidationException::withMessages(['clipboard' => 'Select a section to copy first.']);
                }
                $envelope = app(ClipboardCommands::class)->capture($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->selectedId, $this->tabId, $this->locale);
                $this->copiedSectionText = $envelope->toJson();
                $this->copiedSectionSummary = $envelope->summary();
            }
            $this->pickerOpen = false;
            $this->clipboardMode = $mode;
            $this->clipboardPlacement = $position;
            $this->clipboardAnchor = $anchor;
            $this->clipboardParentId = $parentId;
            $this->clipboardOpen = true;
            $this->clearClipboardReview();
        });
    }

    public function closeClipboard(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->clipboardOpen = false;
        });
    }

    public function clearClipboardInput(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->clipboardText = '';
            $this->clearClipboardReview();
            $this->message = 'Pasted content cleared.';
        });
    }

    public function reviewClipboard(bool $withReferences = false, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($withReferences): void {
            $this->editable();
            $this->flushSection();
            $this->insertionPosition($this->clipboardPlacement, $this->clipboardAnchor, $this->clipboardParentId);
            $map = $withReferences ? $this->clipboardReferenceMap() : null;
            $review = app(ClipboardCommands::class)->review($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->clipboardText, $this->locale, $map, $this->insertionPosition($this->clipboardPlacement, $this->clipboardAnchor, $this->clipboardParentId));
            $this->clipboardReview = [...$review->summary(), 'draftDigest' => $this->draftDigest, 'layoutDigest' => app(PickerCatalog::class)->layoutDigest($this->page()), 'position' => ['parentId' => $this->clipboardParentId, 'kind' => $this->clipboardPlacement, 'anchor' => $this->clipboardAnchor]];
            $this->clipboardReferences = $review->references->toArray();
            if (! $withReferences) {
                $this->clipboardReferenceValues = [];
                foreach ($this->clipboardReferences['occurrences'] as $reference) {
                    $this->clipboardReferenceValues[$reference['key']] = ['value' => $reference['value'], 'keepUnverified' => false];
                }
            }
            $this->clipboardReferenceBaseline = $this->clipboardReferenceValues;
            $this->message = 'Structured copy checked. Review its placement and references before inserting.';
        });
    }

    public function pasteClipboard(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if ($this->clipboardReview === [] || ! hash_equals($this->clipboardReview['textDigest'], hash('sha256', $this->clipboardText))
                || ! $this->same($this->clipboardReferenceValues, $this->clipboardReferenceBaseline)
                || ! $this->same($this->clipboardReview['position'] ?? null, ['parentId' => $this->clipboardParentId, 'kind' => $this->clipboardPlacement, 'anchor' => $this->clipboardAnchor])
                || $this->clipboardReview['destinationVersion'] !== $this->writeVersion
                || ! hash_equals($this->clipboardReview['draftDigest'], $this->draftDigest)
                || ! hash_equals($this->clipboardReview['layoutDigest'], app(PickerCatalog::class)->layoutDigest($this->page()))) {
                throw ValidationException::withMessages(['clipboard' => 'The text, references or accepted destination changed. Check the structured copy again before inserting.']);
            }
            if ($this->clipboardReferences['blocking'] ?? true) {
                throw ValidationException::withMessages(['clipboard' => 'Resolve the reported references before inserting this structured copy.']);
            }
            $result = app(ClipboardCommands::class)->paste($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->clipboardText,
                $this->insertionPosition($this->clipboardPlacement, $this->clipboardAnchor, $this->clipboardParentId), $this->locale, $this->clipboardReferenceMap(), $this->draftHistoryContext());
            $this->accept($result->mutation);
            $this->selectedId = $result->insertedIds[0] ?? null;
            $this->fillSection();
            $this->clipboardOpen = false;
            $this->clipboardText = '';
            $this->responseExtras = ['insertedIds' => $result->insertedIds, 'announcement' => 'Structured section added to the draft.'];
            $this->message = 'Structured section added to the draft. Review the destination preview before publishing.';
            $this->clearClipboardReview();
        });
    }

    private function clearClipboardReview(): void
    {
        $this->clipboardReview = [];
        $this->clipboardReferences = [];
        $this->clipboardReferenceValues = [];
        $this->clipboardReferenceBaseline = [];
    }

    private function clipboardReferenceMap(): ReferenceMap
    {
        if ($this->clipboardReview === [] || ! hash_equals($this->clipboardReview['textDigest'], hash('sha256', $this->clipboardText))) {
            throw ValidationException::withMessages(['clipboard' => 'Check the current structured text before reviewing reference choices.']);
        }
        $entries = [];
        foreach ($this->clipboardReferences['occurrences'] ?? [] as $reference) {
            $choice = $this->clipboardReferenceValues[$reference['key']] ?? null;
            if (! is_array($choice) || ! array_key_exists('value', $choice) || ! is_bool($choice['keepUnverified'] ?? null)) {
                throw ValidationException::withMessages(['clipboard' => 'Each declared reference needs an explicit valid choice.']);
            }
            $value = $choice['value'];
            if ($value === '') {
                $value = null;
            }
            if ($reference['kind'] === 'page' && $value !== null) {
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($value === false) {
                    throw ValidationException::withMessages(['clipboard' => 'Page references require a positive page ID.']);
                }
            }
            $entries[] = ['nodeId' => $reference['nodeId'], 'path' => $reference['path'], 'kind' => $reference['kind'], 'original' => $reference['original'],
                'value' => $value, 'keepUnverified' => $choice['keepUnverified']];
        }
        if (array_diff(array_keys($this->clipboardReferenceValues), array_column($this->clipboardReferences['occurrences'] ?? [], 'key')) !== []) {
            throw ValidationException::withMessages(['clipboard' => 'The reference choices contain an unknown occurrence.']);
        }

        return ReferenceMap::fromArray($entries);
    }

    private function clipboardViewData(): array
    {
        return ['clipboardAvailable' => app(ClipboardCommands::class)->available()];
    }
}
