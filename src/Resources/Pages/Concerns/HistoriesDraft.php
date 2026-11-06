<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistorySession;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeResolver;

trait HistoriesDraft
{
    #[Locked]
    public array $draftHistoryContextData = [];

    #[Locked]
    public array $draftHistoryState = ['available' => false, 'undoCount' => 0, 'redoCount' => 0, 'canUndo' => false, 'canRedo' => false, 'evicted' => false];

    #[Locked]
    public string $typingGroup = '';

    #[Locked]
    public string $draftHistoryUnavailableMessage = '';

    #[Locked]
    public bool $draftHistoryDisabled = false;

    private bool $historyAutosaving = false;

    public function autosaveSelected(string $group, int $requestId = 0): array
    {
        $this->historyAutosaving = true;
        try {
            return $this->perform($requestId, function () use ($group): void {
                if (! Str::isUuid($group)) {
                    throw ValidationException::withMessages(['history' => 'The typing group is unavailable. Use Save draft to accept this input.']);
                }
                $this->typingGroup = $group;
                $this->flushSection();
            });
        } finally {
            $this->historyAutosaving = false;
        }
    }

    public function undoDraft(int $requestId = 0): array
    {
        return $this->travelDraftHistory(false, $requestId);
    }

    public function redoDraft(int $requestId = 0): array
    {
        return $this->travelDraftHistory(true, $requestId);
    }

    private function travelDraftHistory(bool $redo, int $requestId): array
    {
        return $this->perform($requestId, function () use ($redo): void {
            $this->editable();
            // Pending native input is accepted before choosing the adjacent checkpoint.
            $this->flushSection();
            $context = $this->draftHistoryContext();
            if ($context === null) {
                throw ValidationException::withMessages(['history' => 'Document undo is unavailable until its optional history store is installed.']);
            }
            $history = app(DraftHistory::class);
            $result = $redo ? $history->redo($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $context)
                : $history->undo($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $context);
            $this->accept($result->mutation);
            $this->acceptDraftHistory($result->history);
            if (! isset(app(TreeResolver::class)->nodes($this->document())[$this->selectedId])) {
                $this->selectedId = $this->document()->nodes[0]->id ?? null;
            }
            $this->fillSection();
            $this->fillDesign(true);
            $this->pickerOpen = false;
            $this->clearStartingPointReview();
            $this->clipboardOpen = false;
            $this->clearClipboardReview();
            $this->responseExtras = ['focusNodeId' => $this->selectedId, 'focusEmptyAdd' => $this->selectedId === null, 'announcement' => $redo ? 'Draft change redone.' : 'Draft change undone.'];
            $this->message = ($redo ? 'Redid' : 'Undid').' the last document change. The live page and shared page settings are unchanged.';
        });
    }

    private function openDraftHistory(bool $reset = false): void
    {
        if ($reset) {
            $this->draftHistoryDisabled = false;
            $this->draftHistoryUnavailableMessage = '';
        }
        if ($this->readOnly || $this->draftHistoryDisabled || ! app(DraftHistory::class)->available()) {
            $this->draftHistoryContextData = [];
            $this->draftHistoryState = ['available' => false, 'undoCount' => 0, 'redoCount' => 0, 'canUndo' => false, 'canRedo' => false, 'evicted' => false];

            return;
        }
        try {
            $this->acceptDraftHistory(app(DraftHistory::class)->open($this->actor(), $this->pageId, $this->leaseToken, $this->tabId, $reset));
        } catch (ValidationException $exception) {
            $this->draftHistoryContextData = [];
            $this->draftHistoryState = ['available' => false, 'undoCount' => 0, 'redoCount' => 0, 'canUndo' => false, 'canRedo' => false, 'evicted' => false];
            $this->draftHistoryDisabled = true;
            $this->draftHistoryUnavailableMessage = collect($exception->errors())->flatten()->first() ?? 'Document undo is unavailable for this draft. Editing without history remains available.';
        }
    }

    private function draftHistoryContext(): ?HistoryContext
    {
        if ($this->draftHistoryContextData === []) {
            $this->openDraftHistory();
        }

        return $this->draftHistoryContextData === [] ? null : HistoryContext::fromArray($this->draftHistoryContextData);
    }

    private function refreshDraftHistory(): void
    {
        if ($this->draftHistoryContextData !== []) {
            $this->acceptDraftHistory(app(DraftHistory::class)->status($this->actor(), $this->pageId, $this->leaseToken, HistoryContext::fromArray($this->draftHistoryContextData)));
        }
    }

    private function closeDraftHistoryGroup(): void
    {
        if (! $this->historyAutosaving && $this->draftHistoryContextData !== [] && ! $this->readOnly) {
            $this->acceptDraftHistory(app(DraftHistory::class)->closeGroup($this->actor(), $this->pageId, $this->leaseToken, HistoryContext::fromArray($this->draftHistoryContextData)));
        }
    }

    private function acceptDraftHistory(HistorySession $session): void
    {
        $this->draftHistoryContextData = $session->context->toArray();
        $this->draftHistoryState = [...$session->status, 'canUndo' => ($session->status['undoCount'] ?? 0) > 0, 'canRedo' => ($session->status['redoCount'] ?? 0) > 0];
    }

    private function contentHistoryAction(): LogicalAction
    {
        $changed = [];
        $node = $this->selectedNode();
        $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
        foreach (array_unique([...array_keys($this->formBaseline), ...array_keys($this->formData)]) as $key) {
            if (! $this->same($this->formBaseline[$key] ?? null, $this->formData[$key] ?? null)) {
                $changed[] = $key;
            }
        }
        sort($changed);
        $properties = $definition?->properties() ?? [];
        $textOnly = $changed !== [] && count($changed) <= 64 && array_all($changed, fn ($key): bool => preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,127}$/D', $key) === 1 && in_array($properties[$key]->kind ?? null, ['text', 'html', 'url'], true));
        $typing = $textOnly && Str::isUuid($this->typingGroup);

        return new LogicalAction($typing ? 'typing' : 'save', 'Edit section', $typing ? $this->typingGroup : null, $this->selectedId, $this->locale, $typing ? $changed : []);
    }
}
