<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use stdClass;

/** Form state is a projection, never the stored node. */
final class NodeFormState
{
    /** Repeater UUID maps have meaningful key order before dehydration. */
    public function sameRaw(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) === json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function project(Node $node, ComponentDefinition $definition, string $locale): array
    {
        $values = [];
        foreach ($definition->properties() as $key => $property) {
            $map = $property->translated ? ($node->translations->get($locale) ?? new stdClass) : $node->props->toObject();
            if (property_exists($map, $key)) {
                $values[$key] = $this->projectValue($map->{$key}, $property);
            }
        }

        return $values;
    }

    public function patch(Node $node, ComponentDefinition $definition, string $locale, array $dehydrated, array $baselineRaw, array $currentRaw, array $clones = []): Node
    {
        $props = $node->props->toObject();
        $translations = $node->translations->toObject();
        foreach ($definition->properties() as $key => $property) {
            if (! array_key_exists($key, $dehydrated) || $this->same($baselineRaw[$key] ?? null, $currentRaw[$key] ?? null)) {
                continue;
            }
            if ($property->translated) {
                $translations->{$locale} ??= new stdClass;
                $map = $translations->{$locale};
            } else {
                $map = $props;
            }
            $map->{$key} = $this->merge($map->{$key} ?? null, $property, $dehydrated[$key], $baselineRaw[$key] ?? null, $currentRaw[$key] ?? null, $clones[$key] ?? []);
        }

        return new Node($node->id, $node->type, $node->version, new JsonObject($props), new JsonObject($translations), $node->visibility, $node->children, $node->extensions);
    }

    private function merge(mixed $original, PropertyDefinition $property, mixed $value, mixed $baseline, mixed $current, array $clones = []): mixed
    {
        if ($property->kind === 'revision' && $property->revisionKind !== null && is_array($value)) {
            return PublishedPartReference::from((object) $value)->jsonSerialize();
        }
        if ($value === null && ! $property->nullable && in_array($property->kind, ['text', 'html'], true)) {
            return '';
        }
        if ($property->kind === 'object' && is_array($value)) {
            $object = $original instanceof stdClass ? clone $original : new stdClass;
            foreach ($property->properties as $key => $child) {
                if (array_key_exists($key, $value) && ($original === null || ! $this->same($baseline[$key] ?? null, $current[$key] ?? null))) {
                    $object->{$key} = $this->merge($object->{$key} ?? null, $child, $value[$key], $baseline[$key] ?? null, $current[$key] ?? null);
                }
            }

            return $object;
        }
        if ($property->kind === 'list' && is_array($value)) {
            $beforeKeys = array_keys(is_array($baseline) ? $baseline : []);
            $currentKeys = array_keys(is_array($current) ? $current : []);
            $items = [];
            foreach (array_values($value) as $index => $item) {
                $key = $currentKeys[$index] ?? $index;
                $sourceKey = $clones[$key] ?? $key;
                $oldIndex = array_search($sourceKey, $beforeKeys, true);
                $old = $oldIndex === false ? null : ($original[$oldIndex] ?? null);
                $items[] = $this->merge($old, $property->items, $item, $baseline[$sourceKey] ?? null, $current[$key] ?? null);
            }

            return $items;
        }

        return $value;
    }

    private function formValue(mixed $value): mixed
    {
        if ($value instanceof stdClass || is_array($value)) {
            return array_map($this->formValue(...), (array) $value);
        }

        return $value;
    }

    private function projectValue(mixed $value, PropertyDefinition $property): mixed
    {
        if ($property->kind === 'revision' && $property->revisionKind !== null && $value !== null) {
            return PublishedPartReference::from($value)->revisionId;
        }
        if ($property->kind === 'object' && $value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = isset($property->properties[$key]) ? $this->projectValue($item, $property->properties[$key]) : $this->formValue($item);
            }

            return $result;
        }
        if ($property->kind === 'list' && is_array($value)) {
            return array_map(fn ($item) => $this->projectValue($item, $property->items), $value);
        }

        return $this->formValue($value);
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->sameRaw($left, $right);
    }
}
