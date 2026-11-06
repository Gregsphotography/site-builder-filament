<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

interface MediaPicker
{
    /** @return array<string, string> */
    public function options(string $locale): array;
}
