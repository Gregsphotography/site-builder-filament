<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Editing\TreeCommands;
use SiteBuilder\Core\Editing\TreeMutationResult;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\TreePresentation;

trait TreesDraft
{
    public ?string $treeMoveParentId = null;

    public function moveToParent(int $requestId = 0): array
    {
        return $this->moveTreeSection($this->selectedId ?? '', $this->treeMoveParentId, 'end', null, $requestId);
    }

    public function addTreeSection(string $type, ?string $parentId = null, string $placement = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($type, $parentId, $placement, $anchor): void {
            $this->editable();
            $this->flushSection();
            $this->acceptTree(app(TreeCommands::class)->add($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $type,
                $this->treeSlot($parentId), $this->treePosition($placement, $anchor), $this->draftHistoryContext()));
        });
    }

    public function moveTreeSection(string $id, ?string $destinationParentId = null, string $placement = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id, $destinationParentId, $placement, $anchor): void {
            $this->editable();
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $this->acceptTree(app(TreeCommands::class)->move($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id,
                $resolver->slotFor($this->document(), $id), $this->treeSlot($destinationParentId), $this->treePosition($placement, $anchor), $this->draftHistoryContext()));
        });
    }

    public function moveSectionIn(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->editable();
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $siblings = $resolver->children($this->document(), $resolver->slotFor($this->document(), $id));
            $previous = $siblings[$location->index - 1] ?? null;
            if ($previous === null) {
                throw ValidationException::withMessages(['tree' => 'Choose a preceding compatible container before moving this section inside.']);
            }
            $this->acceptTree(app(TreeCommands::class)->move($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id,
                $resolver->slotFor($this->document(), $id), TreeSlot::children($previous->id), TreePosition::end(), $this->draftHistoryContext()));
        });
    }

    public function moveSectionOut(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->editable();
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            if ($location->parentId === null) {
                throw ValidationException::withMessages(['tree' => 'This section is already at the page root.']);
            }
            $parent = $resolver->locate($this->document(), $location->parentId);
            $this->acceptTree(app(TreeCommands::class)->move($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id,
                TreeSlot::children($location->parentId), $this->treeSlot($parent->parentId), TreePosition::after($parent->node->id), $this->draftHistoryContext()));
        });
    }

    private function acceptTree(TreeMutationResult $result): void
    {
        $this->accept($result->mutation);
        $this->selectedId = $result->selectedIds[0] ?? ($this->document()->nodes[0]->id ?? null);
        $this->fillSection();
        $this->responseExtras = ['focusNodeId' => $this->selectedId, 'focusEmptyAdd' => $this->selectedId === null, 'announcement' => 'Page structure updated.'];
    }

    private function treeSlot(?string $parentId): TreeSlot
    {
        return $parentId === null || $parentId === '' ? TreeSlot::root() : TreeSlot::children($parentId);
    }

    private function treePosition(string $placement, ?string $anchor): TreePosition
    {
        return match ($placement) {
            'start' => TreePosition::start(), 'end' => TreePosition::end(),
            'before' => $anchor !== null ? TreePosition::before($anchor) : throw ValidationException::withMessages(['tree' => 'Choose an existing sibling anchor.']),
            'after' => $anchor !== null ? TreePosition::after($anchor) : throw ValidationException::withMessages(['tree' => 'Choose an existing sibling anchor.']),
            default => throw ValidationException::withMessages(['tree' => 'Choose a supported insertion position.']),
        };
    }

    private function replaceTreeNode(Document $document, Node $replacement): Document
    {
        $replace = function (Node $node) use (&$replace, $replacement): Node {
            if ($node->id === $replacement->id) {
                return $replacement;
            }
            if ($node->children === []) {
                return $node;
            }

            return new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, array_map($replace, $node->children), $node->extensions);
        };

        return new Document(array_map($replace, $document->nodes), $document->extensions);
    }

    private function selectedFormEditable(): bool
    {
        if ($this->selectedId === null || $this->documentJson === '') {
            return false;
        }
        $location = app(TreeResolver::class)->nodes($this->document())[$this->selectedId] ?? null;
        if ($location === null) {
            return false;
        }
        $registry = app(ComponentRegistry::class);
        $features = app(RenderFeatureRegistry::class);
        if ($features->readOnly($this->document())) {
            return false;
        }
        foreach ([...$location->ancestors, $location->node] as $node) {
            if ($registry->resolve($node->type)?->version() !== $node->version || $features->readOnly($this->document(), $node)) {
                return false;
            }
        }

        return app(ComponentFormRegistry::class)->resolve($location->node->type) !== null;
    }

    private function treeViewData(): array
    {
        if ($this->documentJson === '') {
            return ['sectionTree' => [], 'sections' => [], 'treeContainers' => []];
        }

        return app(TreePresentation::class)->describe($this->document(), new RenderContext($this->locale, 'preview', $this->pageId, $this->page()->layout), $this->selectedId, $this->treeMoveParentId);
    }
}
