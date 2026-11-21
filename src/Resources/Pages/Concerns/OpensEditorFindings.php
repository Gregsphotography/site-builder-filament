<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Livewire\Attributes\Locked;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Rendering\LocaleCatalog;

trait OpensEditorFindings
{
    #[Locked]
    public ?string $initialField = null;

    private function linkedLocale(array $query): string
    {
        $locales = app(LocaleCatalog::class);

        return is_string($query['locale'] ?? null) && $locales->has($query['locale']) ? $query['locale'] : $locales->default();
    }

    private function selectLinkedSection(array $query): void
    {
        $nodeId = $query['node'] ?? null;
        if (! is_string($nodeId) || $this->documentJson === '') {
            return;
        }
        $location = app(TreeResolver::class)->nodes($this->document())[$nodeId] ?? null;
        if ($location === null) {
            $this->message = 'The section in this finding is no longer in the current draft.';

            return;
        }
        $this->selectedId = $nodeId;
        $this->treeMoveParentId = $location->parentId;
        $properties = app(ComponentRegistry::class)->resolve($location->node->type)?->properties() ?? [];
        $field = $query['field'] ?? null;
        if (is_string($field) && isset($properties[$field])) {
            $this->initialField = 'formData.'.$field;
        }
    }

    /** Resolve ordinal findings to the actual Livewire field keys after form hydration. */
    private function linkedFormPath(array $segments): string
    {
        $state = $this->formData;
        $path = [];
        foreach ($segments as $segment) {
            if (! is_array($state) || (! is_string($segment) && ! is_int($segment))) {
                break;
            }
            if (ctype_digit((string) $segment)) {
                $segment = array_keys($state)[(int) $segment] ?? null;
            }
            if ($segment === null || ! array_key_exists($segment, $state)) {
                break; // A fallback row may not yet exist in the local language.
            }
            $path[] = $segment;
            $state = $state[$segment];
        }

        return implode('.', $path) ?: (string) $segments[0];
    }
}
