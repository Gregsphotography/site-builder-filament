<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

interface TypedMediaPicker extends MediaPicker
{
    /** @return array<string,string> */
    public function optionsForKind(string $locale, string $kind): array;
}
