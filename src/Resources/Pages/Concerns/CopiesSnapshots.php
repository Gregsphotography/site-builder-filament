<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Snapshots\SnapshotCopyCommands;
use SiteBuilder\Core\Snapshots\SnapshotDestination;
use SiteBuilder\Core\Snapshots\SnapshotSelection;

/** Shared page/resource UI; locked review state is intent, never authorization. */
trait CopiesSnapshots
{
    #[Locked]
    public array $snapshotChoices = [];

    #[Locked]
    public array $snapshotReview = [];

    public array $snapshotReferenceValues = [];

    public array $snapshotKeepUnverified = [];

    public bool $snapshotConfirmed = false;

    #[Unexposed]
    public function snapshotEligible(): bool
    {
        return ! $this->readOnly && in_array($this->selectedNode()?->type, ['standard.brand', 'standard.navigation'], true);
    }

    public function loadSnapshotSources(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->snapshotFlush();
            $this->clearSnapshotReview();
            $this->snapshotChoices = app(SnapshotCopyCommands::class)->choices($this->actor(), $this->snapshotDestination(), $this->locale, $this->leaseToken, $this->writeVersion);
            $this->message = $this->snapshotChoices === [] ? 'No authorized source is configured. You can edit this block directly.' : 'Choose a source to review its draft changes.';
        });
    }

    public function reviewSnapshotSource(string $provider, string $sourceId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($provider, $sourceId): void {
            $this->snapshotFlush();
            $this->clearSnapshotReview();
            $selection = app(SnapshotCopyCommands::class)->selection($this->actor(), $this->snapshotDestination(), $provider, $sourceId, $this->locale, $this->leaseToken, $this->writeVersion);
            $this->snapshotReview = app(SnapshotCopyCommands::class)->review($this->actor(), $selection, $this->leaseToken)->toArray();
            foreach ($this->snapshotReview['references']['occurrences'] as $entry) {
                $this->snapshotReferenceValues[$entry['key']] = $entry['value'] === null ? '' : (string) $entry['value'];
                $this->snapshotKeepUnverified[$entry['key']] = false;
            }
        });
    }

    public function reviewSnapshotMappings(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $selection = $this->snapshotSelection();
            $this->snapshotReview = app(SnapshotCopyCommands::class)->review($this->actor(), $selection, $this->leaseToken, $this->snapshotMap())->toArray();
            $this->snapshotConfirmed = false;
        });
    }

    public function applySnapshotSource(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            if (! $this->snapshotConfirmed || empty($this->snapshotReview['candidateDigest'])) {
                throw ValidationException::withMessages(['snapshot' => 'Review and confirm the source field replacement first.']);
            }
            // Flush local form changes first; any accepted change makes the pinned review stale.
            $selection = $this->snapshotSelection();
            $digest = $this->snapshotReview['candidateDigest'];
            $map = $this->snapshotMap();
            $this->snapshotFlush();
            $history = isset($this->partId) ? $this->historyContext() : $this->draftHistoryContext();
            $result = app(SnapshotCopyCommands::class)->apply($this->actor(), $selection, $this->leaseToken, $digest, $map, $history);
            $this->accept($result);
            $this->fillSection();
            $this->clearSnapshotReview();
            $this->message = 'Copied source fields into this draft. Publication and pinned pages are unchanged.';
        });
    }

    public function cancelSnapshotSource(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->clearSnapshotReview();
        });
    }

    private function snapshotDestination(): SnapshotDestination
    {
        if (! $this->snapshotEligible()) {
            throw new WriteConflict('snapshot_destination_unavailable', $this->writeVersion);
        }

        return isset($this->partId) ? new SnapshotDestination('site-part', $this->partId, $this->selectedId) : new SnapshotDestination('page', $this->pageId, $this->selectedId);
    }

    private function snapshotSelection(): SnapshotSelection
    {
        if ($this->snapshotReview === []) {
            throw ValidationException::withMessages(['snapshot' => 'Select and review a source first.']);
        }
        $selection = SnapshotSelection::fromArray($this->snapshotReview['selection']);
        if ($selection->destination->jsonSerialize() !== $this->snapshotDestination()->jsonSerialize() || $selection->locale !== $this->locale) {
            throw new WriteConflict('snapshot_destination_changed', $this->writeVersion);
        }

        return $selection;
    }

    private function snapshotMap(): ReferenceMap
    {
        $entries = [];
        $keys = array_column($this->snapshotReview['references']['occurrences'] ?? [], 'key');
        if (array_diff(array_keys($this->snapshotReferenceValues), $keys) !== [] || array_diff(array_keys($this->snapshotKeepUnverified), $keys) !== []) {
            throw ValidationException::withMessages(['snapshot' => 'An unknown reference mapping was submitted.']);
        }
        foreach ($this->snapshotReview['references']['occurrences'] ?? [] as $entry) {
            $value = $this->snapshotReferenceValues[$entry['key']] ?? '';
            if (! is_string($value)) {
                throw ValidationException::withMessages(['snapshot' => 'Reference values must be text or a page ID.']);
            }
            if ($entry['kind'] === 'page' && $value !== '') {
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9007199254740991]]);
                if ($value === false) {
                    throw ValidationException::withMessages(['snapshot' => 'Choose a positive core Page ID.']);
                }
            } elseif ($value === '') {
                $value = null;
            }
            $keep = $this->snapshotKeepUnverified[$entry['key']] ?? false;
            if (! is_bool($keep)) {
                throw ValidationException::withMessages(['snapshot' => 'Confirm unverified references with the checkbox.']);
            }
            $entries[] = ['nodeId' => $entry['nodeId'], 'path' => $entry['path'], 'kind' => $entry['kind'], 'original' => $entry['original'], 'value' => $value, 'keepUnverified' => $keep];
        }

        return ReferenceMap::fromArray($entries);
    }

    private function snapshotFlush(): void
    {
        $this->snapshotDestination();
        $this->flushSection();
        if (isset($this->pageId)) {
            $this->flushSettings();
        }
    }

    private function clearSnapshotReview(): void
    {
        $this->snapshotReview = [];
        $this->snapshotReferenceValues = [];
        $this->snapshotKeepUnverified = [];
        $this->snapshotConfirmed = false;
    }
}
