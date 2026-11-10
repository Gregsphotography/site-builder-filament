<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Installation\SetupAuthorization;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Filament\Contracts\BatchMediaPicker;
use SiteBuilder\Filament\Contracts\TypedMediaPicker;
use SiteBuilder\Filament\Forms\BatchMediaSelection;

final readonly class ComponentMediaPicker implements BatchMediaPicker, TypedMediaPicker
{
    public function __construct(private object $media) {}

    public function options(string $locale): array
    {
        if (app()->bound('site-builder.setup.active') && in_array(data_get(config('site-builder-setup.answers'), 'authorization.mode'), ['allowlist', 'deny'], true)) {
            $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();
            if ($actor === null || ! app(SetupAuthorization::class)->media($actor, 'view')) {
                return [];
            }
        }

        return $this->media->options($locale);
    }

    public function optionsForKind(string $locale, string $kind): array
    {
        if ($kind === 'image') {
            return $this->options($locale);
        }
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();
        if ($actor === null || ! (app(SetupAuthorization::class)->media($actor, 'view') ?? Gate::forUser($actor)->allows('viewAny', Page::class))) {
            return [];
        }

        return $this->media->options($locale, $kind);
    }

    public function selectMany(Authenticatable $actor, string $locale, array $references): BatchMediaSelection
    {
        if (! array_is_list($references) || count($references) > 48 || ! array_all($references, fn ($value) => is_string($value) && strlen($value) <= 2048)) {
            throw new \InvalidArgumentException('Choose at most 48 media references.');
        }
        $allowed = app(SetupAuthorization::class)->media($actor, 'view') ?? Gate::forUser($actor)->allows('viewAny', Page::class);
        $entries = $allowed && $this->media->available() ? $this->media->entries()->keyBy('path') : collect();
        $accepted = $failures = [];
        foreach ($references as $index => $reference) {
            $entry = $entries->get($reference);
            if ($entry === null || $this->media->url($reference) === null) {
                $failures[$index] = 'This image is unavailable or no longer permitted.';

                continue;
            }
            $accepted[] = ['index' => $index, 'reference' => $reference, 'alt' => $entry->alt[$locale] ?? $entry->alt[app(LocaleCatalog::class)->default()] ?? '', 'width' => $entry->width, 'height' => $entry->height];
        }

        return new BatchMediaSelection($accepted, $failures);
    }
}
