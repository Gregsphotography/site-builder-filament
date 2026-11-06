<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Str;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Persistence\ActorIdentity;

/** Short-lived UI state; never content, reusable-source provenance or mutation authority. */
final readonly class PickerPreviewTickets
{
    public function __construct(private Factory $cache) {}

    public function issue(Authenticatable $actor, int $pageId, int $pageVersion, string $entry, SourceSelection $source, string $layoutDigest, ReferenceMap $map, array $position = ['kind' => 'end', 'anchor' => null, 'parentId' => null]): string
    {
        $identity = ActorIdentity::from($actor);
        $ticket = (string) Str::uuid();
        $this->cache->store(config('site-builder.preview.cache_store'))->put('site-builder:picker-preview:'.$ticket, [
            'actorId' => $identity->id, 'actorType' => $identity->type, 'pageId' => $pageId, 'entry' => $entry,
            'kind' => $source->kind, 'source' => $source->id, 'sourceVersion' => $source->version, 'digest' => $source->digest,
            'locale' => $source->locale, 'variant' => $source->variant, 'layoutDigest' => $layoutDigest,
            'pageVersion' => (string) $pageVersion, 'position' => $position['kind'], 'anchor' => $position['anchor'], 'parentId' => $position['parentId'],
            'map' => array_values($map->all()), 'expiresAt' => now()->addMinutes(10)->getTimestamp(),
        ], now()->addMinutes(10));

        return $ticket;
    }

    public function read(Authenticatable $actor, int $pageId, string $ticket, array $selection): ?array
    {
        $value = $this->cache->store(config('site-builder.preview.cache_store'))->get('site-builder:picker-preview:'.$ticket);
        $identity = ActorIdentity::from($actor);
        if (! is_array($value) || ($value['expiresAt'] ?? 0) < time() || ($value['actorId'] ?? null) !== $identity->id || ($value['actorType'] ?? null) !== $identity->type || ($value['pageId'] ?? null) !== $pageId) {
            return null;
        }
        foreach (['entry', 'kind', 'source', 'sourceVersion', 'digest', 'locale', 'variant', 'layoutDigest', 'pageVersion', 'position', 'anchor', 'parentId'] as $key) {
            if (($value[$key] ?? null) !== ($selection[$key] ?? null)) {
                return null;
            }
        }

        return $value;
    }
}
