<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Illuminate\Contracts\Container\Container;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Filament\Contracts\MediaPicker;

/** The optional component pack remains an integration, never a required dependency. */
final readonly class OptionalComponents
{
    private const TEMPLATES = 'SiteBuilder\\Components\\Templates\\TemplateCatalog';

    private const MEDIA = 'SiteBuilder\\Components\\Media\\MediaLibrary';

    public function __construct(private Container $container) {}

    public function templateOptions(string $locale): array
    {
        return $this->templates()?->options($locale) ?? [];
    }

    public function template(string $id, string $locale): ?Document
    {
        return $this->templates()?->instantiate($id, $locale);
    }

    public function media(): ?object
    {
        return $this->container->bound(self::MEDIA) ? $this->container->make(self::MEDIA) : null;
    }

    public function picker(): ?MediaPicker
    {
        if ($this->container->bound(MediaPicker::class)) {
            return $this->container->make(MediaPicker::class);
        }
        $media = $this->media();

        return $media !== null && $media->available() ? new ComponentMediaPicker($media) : null;
    }

    private function templates(): ?object
    {
        return $this->container->bound(self::TEMPLATES) ? $this->container->make(self::TEMPLATES) : null;
    }
}
