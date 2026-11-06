<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Filament\Contracts\BatchMediaPicker;
use SiteBuilder\Filament\Support\OptionalComponents;

trait SelectsGalleryMedia
{
    public array $mediaBatchReferences = [];

    #[Locked]
    public array $mediaBatchReview = [];

    public bool $mediaBatchConfirmed = false;

    #[Unexposed]
    public function galleryBatchOptions(): ?array
    {
        $picker = app(OptionalComponents::class)->picker();

        return ! $this->readOnly && $this->selectedNode()?->type === 'standard.gallery' && $picker instanceof BatchMediaPicker ? $picker->options($this->locale) : null;
    }

    public function reviewMediaBatch(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $result = $this->selectedMediaBatch();
            $this->mediaBatchReview = ['node' => $this->selectedId, 'locale' => $this->locale, 'version' => $this->writeVersion, 'references' => $this->mediaBatchReferences, 'selection' => $result, 'form' => $this->formData];
            $this->mediaBatchConfirmed = false;
        });
    }

    public function acceptMediaBatch(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $review = $this->mediaBatchReview;
            if (! $this->mediaBatchConfirmed || $review === [] || $review['node'] !== $this->selectedId || $review['locale'] !== $this->locale || $review['version'] !== $this->writeVersion || $review['references'] !== $this->mediaBatchReferences || ! $this->same($review['form'], $this->formData)) {
                throw new WriteConflict('media_selection_changed', $this->writeVersion);
            }
            $current = $this->selectedMediaBatch();
            if ($current !== $review['selection']) {
                throw new WriteConflict('media_selection_changed', $this->writeVersion);
            }
            if ($current['accepted'] === []) {
                throw ValidationException::withMessages(['media' => 'No permitted images were selected.']);
            }
            foreach ($current['accepted'] as $item) {
                $this->formData['items'][(string) Str::uuid()] = ['image' => $item['reference'], 'full_image' => null, 'sources' => [], 'width' => $item['width'] ?? null, 'height' => $item['height'] ?? null, 'alt' => $item['alt'], 'decorative' => false, 'caption' => '', 'url' => null, 'page' => null, 'link_label' => ''];
            }
            $this->flushSection();
            $this->fillSection();
            $this->message = 'Added the reviewed permitted images in the shown order. You can edit captions, alternatives and order before publishing.';
        });
    }

    public function cancelMediaBatch(int $requestId = 0): array
    {
        return $this->perform($requestId, fn () => $this->clearMediaBatch(), false);
    }

    private function selectedMediaBatch(): array
    {
        $picker = app(OptionalComponents::class)->picker();
        if ($this->galleryBatchOptions() === null || ! $picker instanceof BatchMediaPicker || ! array_is_list($this->mediaBatchReferences) || count($this->mediaBatchReferences) > 48 || ! array_all($this->mediaBatchReferences, fn ($ref) => is_string($ref) && strlen($ref) <= 2048)) {
            throw ValidationException::withMessages(['media' => 'Choose a supported ordered media selection.']);
        }
        $selection = $picker->selectMany($this->actor(), $this->locale, $this->mediaBatchReferences)->toArray();
        $indexes = [...array_column($selection['accepted'], 'index'), ...array_keys($selection['failures'])];
        sort($indexes);
        if ($indexes !== array_keys($this->mediaBatchReferences)) {
            throw ValidationException::withMessages(['media' => 'The media adapter must report every selected item.']);
        }
        foreach ($selection['accepted'] as $item) {
            if ($item['reference'] !== $this->mediaBatchReferences[$item['index']]) {
                throw ValidationException::withMessages(['media' => 'The media adapter changed a selected reference.']);
            }
        }
        if (count($this->formData['items'] ?? []) + count($selection['accepted']) > 48) {
            throw ValidationException::withMessages(['media' => 'A gallery can contain at most 48 images.']);
        }

        return $selection;
    }

    private function clearMediaBatch(): void
    {
        $this->mediaBatchReview = [];
        $this->mediaBatchReferences = [];
        $this->mediaBatchConfirmed = false;
    }
}
