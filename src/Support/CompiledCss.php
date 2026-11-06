<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Filament\Support\Assets\Css;

/** Published compiled bytes also change during development without a new Composer ref. */
final class CompiledCss extends Css
{
    public function getVersion(): string
    {
        $path = $this->getPath();
        $digest = is_string($path) && is_file($path) ? @hash_file('sha256', $path) : false;

        return is_string($digest) ? $digest : parent::getVersion();
    }
}
