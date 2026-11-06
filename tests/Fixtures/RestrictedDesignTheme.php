<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests\Fixtures;

use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\DesignTheme;
use SiteBuilder\Core\Design\DesignControl;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Rendering\RenderContext;

/** A host adapter with a nonstandard breakpoint and a deliberately restricted hero. */
final class RestrictedDesignTheme implements DesignTheme
{
    public function id(): string
    {
        return 'test.restricted';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Restricted host';
    }

    public function presets(): array
    {
        return ['one' => 'One'];
    }

    public function breakpoints(): array
    {
        return ['cinema' => '100rem+'];
    }

    public function controls(ThemeIdentity $theme, string $scope, ?string $componentType = null): array
    {
        return ['align' => new DesignControl('align', 'Host alignment', ['start' => 'Start', 'center' => 'Center', 'end' => 'End'])];
    }

    public function supports(string $type, int $version, ?string $variant): bool
    {
        return $version === 1 && (($type === 'standard.text' && in_array($variant, [null, 'default', 'narrow'], true)) || ($type === 'standard.hero' && $variant === 'split'));
    }

    public function defaults(ThemeIdentity $theme, ?Node $node, RenderContext $context): array
    {
        return ['align' => 'start'];
    }

    public function classes(string $key, string $option, string $breakpoint): array|string
    {
        return ['host-'.$breakpoint.'-'.$key.'-'.$option];
    }

    public function canvasAttributes(ThemeIdentity $theme): array
    {
        return ['data-site-builder-design' => '1', 'data-site-builder-design-theme' => $this->id()];
    }

    public function available(RenderContext $context): bool
    {
        return $context->layout === 'test.custom';
    }

    public function combinationErrors(ThemeIdentity $theme, array $values, ?Node $node): array
    {
        return [];
    }
}
