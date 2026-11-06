<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use SiteBuilder\Core\Tests\TestCase;
use SiteBuilder\Filament\Support\CompiledCss;

class CompiledCssTest extends TestCase
{
    public function test_changed_compiled_bytes_change_the_asset_url_without_changing_the_composer_reference(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'builder-css-');
        try {
            file_put_contents($file, '.example { color: red; }');
            $asset = CompiledCss::make('test-css', $file)->package('gregsphotography/site-builder-filament');
            $first = $asset->getHref();
            $this->assertSame($first, $asset->getHref());
            file_put_contents($file, '.example { color: blue; }');
            $second = $asset->getHref();
            $this->assertNotSame($first, $second);
            $this->assertSame(strtok($first, '?'), strtok($second, '?'));
            $this->assertSame($second, $asset->getHref());
        } finally {
            unlink($file);
        }
    }
}
