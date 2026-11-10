<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PlacementPolicy;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;

/** Physical document addresses only: expanded global children are never editable tree entries. */
final readonly class TreePresentation
{
    public function __construct(private TreeResolver $resolver, private ComponentRegistry $components, private ComponentFormRegistry $forms, private TreeOperations $operations) {}

    public function describe(Document $document, RenderContext $context, ?string $selectedId = null, ?string $destinationParentId = null): array
    {
        // A native select serializes its root option as an empty string.
        $destinationParentId = $destinationParentId === '' ? null : $destinationParentId;
        $locations = $this->resolver->nodes($document);
        $candidateChecks = 0;
        $flat = [];
        $containers = [];
        $slot = fn (?string $parent): TreeSlot => $parent === null ? TreeSlot::root() : TreeSlot::children($parent);
        $canMove = function (string $id, TreeSlot $destination, TreePosition $position) use ($document, $context, &$candidateChecks): bool {
            $candidateChecks++;
            try {
                $this->operations->move($document, $id, $this->resolver->slotFor($document, $id), $destination, $position, $context);

                return true;
            } catch (\InvalidArgumentException|ValidationException) {
                return false;
            }
        };
        $walk = function (array $nodes, ?string $parentId, string $prefix = '') use (&$walk, &$flat, &$containers, $document, $locations, $slot, $canMove, $selectedId): array {
            $entries = [];
            foreach ($nodes as $index => $node) {
                $location = $locations[$node->id];
                $selected = $node->id === $selectedId;
                $definition = $this->components->resolve($node->type);
                $features = app(RenderFeatureRegistry::class);
                $slotEditable = ! $features->readOnly($document) && array_all($location->ancestors, fn ($ancestor): bool => $this->components->resolve($ancestor->type)?->version() === $ancestor->version && ! $features->readOnly($document, $ancestor));
                $supported = $slotEditable && $definition?->version() === $node->version && ! $features->readOnly($document, $node);
                $parentPolicy = $parentId === null ? null : $this->components->resolve($locations[$parentId]->node->type)?->allowedChildren();
                $capacity = $parentPolicy === null || $parentPolicy->maximum === null || count($nodes) < $parentPolicy->maximum;
                $label = $this->forms->resolve($node->type)?->label() ?? ($node->type === 'core.global-reference' ? 'Published site part' : $node->type);
                $ordinal = $prefix.($index + 1);
                $previous = $nodes[$index - 1] ?? null;
                $entry = ['id' => $node->id, 'type' => $node->type, 'parentId' => $parentId, 'label' => $label, 'ordinal' => $ordinal,
                    'supported' => $supported, 'canRemove' => $selected && $slotEditable && ($parentPolicy === null || $parentPolicy->allowsCount(count($nodes) - 1)), 'canDuplicate' => $selected && $supported && $capacity, 'hidden' => $node->visibility === 'hidden', 'depth' => count($location->ancestors),
                    'canMoveUp' => $selected && $index > 0 && $canMove($node->id, $slot($parentId), TreePosition::before($previous->id)),
                    'canMoveDown' => $selected && isset($nodes[$index + 1]) && $canMove($node->id, $slot($parentId), TreePosition::after($nodes[$index + 1]->id)),
                    'canMoveIn' => $selected && $previous !== null && $canMove($node->id, TreeSlot::children($previous->id), TreePosition::end()),
                    'canMoveOut' => $selected && $parentId !== null && $canMove($node->id, $slot($locations[$parentId]->parentId), TreePosition::after($parentId)),
                    'canAddChild' => $selected && $supported && $definition->allowedChildren()->allowedTypes !== [] && ($definition->allowedChildren()->maximum === null || count($node->children) < $definition->allowedChildren()->maximum),
                    'singleChildType' => $supported && count($definition->allowedChildren()->allowedTypes) === 1 ? $definition->allowedChildren()->allowedTypes[0] : null,
                    'singleChildLabel' => $supported && count($definition->allowedChildren()->allowedTypes) === 1 ? ($this->forms->resolve($definition->allowedChildren()->allowedTypes[0])?->label() ?? 'item') : null,
                    'children' => []];
                $flat[] = $entry;
                $entry['acceptsChildren'] = $supported && $definition->allowedChildren()->allowedTypes !== [] && ($definition->allowedChildren()->maximum === null || count($node->children) < $definition->allowedChildren()->maximum);
                if ($entry['acceptsChildren']) {
                    $containers[] = ['id' => $node->id, 'label' => $ordinal.'. '.$label];
                }
                $entry['children'] = $walk($node->children, $node->id, $ordinal.'.');
                $entries[] = $entry;
            }

            return $entries;
        };
        $tree = $walk($document->nodes, null);

        $destinations = [];
        $canMoveToParent = false;
        if ($selectedId !== null && isset($locations[$selectedId])) {
            $source = $locations[$selectedId];
            $definition = $this->components->resolve($source->node->type);
            $placement = $definition instanceof HasPlacementPolicy ? $definition->placement() : PlacementPolicy::anywhere();
            $sourcePolicy = $source->parentId === null ? null : $this->components->resolve($locations[$source->parentId]->node->type)?->allowedChildren();
            $mayLeave = $sourcePolicy === null || $sourcePolicy->allowsCount(count($this->resolver->children($document, $source->slot())) - 1);
            if ($placement->rootAllowed && ($source->parentId === null || $mayLeave)) {
                $destinations[''] = 'Page root · at the end';
            }
            if ($placement->childAllowed) {
                foreach ($flat as $row) {
                    $target = $locations[$row['id']];
                    if ($target->node->id === $selectedId || array_any($target->ancestors, fn ($ancestor): bool => $ancestor->id === $selectedId)) {
                        continue;
                    }
                    $policy = $this->components->resolve($target->node->type)?->allowedChildren();
                    $same = $source->parentId === $target->node->id;
                    if (! $same && ! $mayLeave) {
                        continue;
                    }
                    if ($policy === null || ! $policy->allows($source->node->type) || ! $policy->allowsCount(count($target->node->children) + ($same ? 0 : 1))) {
                        continue;
                    }
                    $features = app(RenderFeatureRegistry::class);
                    if ($features->readOnly($document) || array_any([...$target->ancestors, $target->node], fn ($ancestor): bool => $this->components->resolve($ancestor->type)?->version() !== $ancestor->version || $features->readOnly($document, $ancestor))) {
                        continue;
                    }
                    $destinations[$row['id']] = $row['ordinal'].'. '.$row['label'].' · at the end';
                }
            }
            $key = $destinationParentId ?? '';
            if (array_key_exists($key, $destinations)) {
                $canMoveToParent = $canMove($selectedId, $slot($destinationParentId), TreePosition::end());
            }
        }

        return ['sectionTree' => $tree, 'sections' => $flat, 'treeContainers' => $containers, 'treeMoveDestinations' => $destinations, 'canMoveToParent' => $canMoveToParent, 'candidateChecks' => $candidateChecks, 'nodeCount' => count($locations)];
    }
}
