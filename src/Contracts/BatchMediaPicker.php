<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Filament\Forms\BatchMediaSelection;

interface BatchMediaPicker extends MediaPicker
{
    /** Verify each selected reference; accepted results retain input order and original index. */
    public function selectMany(Authenticatable $actor, string $locale, array $references): BatchMediaSelection;
}
