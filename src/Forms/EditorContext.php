<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use SiteBuilder\Filament\Contracts\MediaPicker;

final readonly class EditorContext
{
    public function __construct(public string $locale, public ?int $pageId = null, public ?string $nodeId = null, public bool $readOnly = true, public ?MediaPicker $mediaPicker = null, public array $originalValues = []) {}
}
