<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\OverlaySettings;
use SiteBuilder\Core\Composition\SitePartCopyCommands;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistorySession;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartDraftHistory;
use SiteBuilder\Core\SiteParts\SitePartHistory;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartMutationResult;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Forms\NodeFormState;
use SiteBuilder\Filament\Forms\TreePresentation;
use SiteBuilder\Filament\Resources\Pages\Concerns\CopiesSnapshots;
use SiteBuilder\Filament\Resources\Pages\Concerns\SelectsGalleryMedia;
use SiteBuilder\Filament\Resources\SiteParts\Concerns\SitePartDesigns;
use SiteBuilder\Filament\Resources\SiteParts\Concerns\SitePartRollouts;
use SiteBuilder\Filament\Support\OptionalComponents;
use Throwable;

final class SitePartEditor extends ResourcePage
{
    use CopiesSnapshots;
    use SelectsGalleryMedia;
    use SitePartDesigns;
    use SitePartRollouts;

    protected static string $resource = SitePartResource::class;

    protected static string $layout = 'filament-panels::components.layout.base';

    protected string $view = 'site-builder-filament::site-parts.editor';

    #[Locked]
    public string $partId;

    #[Locked]
    public string $kind;

    #[Locked]
    public string $partName;

    #[Locked]
    public string $tabId;

    #[Locked]
    public string $leaseToken;

    #[Locked]
    public int $writeVersion = 0;

    #[Locked]
    public string $draftDigest = '';

    #[Locked]
    public string $payloadJson = '';

    #[Locked]
    public bool $unsupportedPayload = false;

    #[Locked]
    public string $documentJson = '';

    #[Locked]
    public string $locale = '';

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public array $formBaseline = [];

    #[Locked]
    public array $settingsBaseline = [];

    #[Locked]
    public array $draftHistoryContextData = [];

    #[Locked]
    public array $draftHistoryState = ['available' => false, 'canUndo' => false, 'canRedo' => false, 'undoCount' => 0, 'redoCount' => 0];

    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public string $state = 'loading';

    #[Locked]
    public string $message = '';

    #[Locked]
    public string $previewUrl = '';

    #[Locked]
    public bool $pickerOpen = false;

    #[Locked]
    public array $pickerPosition = ['parentId' => null, 'kind' => 'end', 'anchor' => null];

    #[Locked]
    public ?string $revisionBefore = null;

    #[Locked]
    public array $repeaterClones = [];

    public array $formData = [];

    public array $settingsData = [];

    public string $newComponentType = '';

    public ?string $treeMoveParentId = null;

    public bool $archiveConfirmed = false;

    public bool $deleteConfirmed = false;

    public string $withdrawReason = '';

    public bool $withdrawConfirmed = false;

    private bool $autosaving = false;

    private ?string $typingGroup = null;

    private array $responseExtras = [];

    public function mount(string $record): void
    {
        abort_unless(app(SitePartCatalog::class)->available(), 404);
        $part = SitePart::query()->findOrFail($record);
        Gate::forUser($this->actor())->authorize('view', $part);
        $this->partId = $part->id;
        $this->tabId = (string) Str::uuid();
        $this->leaseToken = (string) Str::uuid();
        $this->locale = app(LocaleCatalog::class)->default();
        $this->loadPart($part);
        if (! $this->unsupportedPayload && Gate::forUser($this->actor())->allows('update', $part) && $part->archived_at === null) {
            try {
                app(SitePartLocks::class)->acquire($this->actor(), $this->partId, $this->leaseToken);
                $this->readOnly = false;
                $this->state = 'saved';
            } catch (WriteConflict $exception) {
                $this->conflict($exception);
            }
        }
        $this->openHistory();
        $this->fillSection();
        $this->fillSettings();
        $this->refreshPreview();
    }

    #[Unexposed]
    public function sectionForm(Schema $schema): Schema
    {
        $node = $this->selectedNode();
        $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
        $form = $node === null ? null : app(ComponentFormRegistry::class)->resolve($node->type);
        $supported = $definition?->version() === $node?->version && $this->editableAncestors();

        return $schema->components($supported && $form !== null ? $form->schema(new EditorContext($this->locale, nodeId: $node->id, readOnly: $this->readOnly, mediaPicker: app(OptionalComponents::class)->picker(), originalValues: app(NodeFormState::class)->project($node, $definition, $this->locale))) : [])->statePath('formData')->disabled($this->readOnly);
    }

    #[Unexposed]
    public function settingsForm(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->rows(3), ...($this->kind === 'overlay' ? [
            TextInput::make('overlay_title')->label('Popup title · '.strtoupper($this->locale))->required()->maxLength(255),
            Select::make('presentation')->options(['dialog' => 'Centered dialog', 'drawer-left' => 'Drawer from left', 'drawer-right' => 'Drawer from right'])->required(),
            Select::make('size')->options(['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'full' => 'Full'])->required(),
        ] : [])])->statePath('settingsData')->disabled($this->readOnly);
    }

    public function saveSelected(int $requestId = 0): array
    {
        return $this->perform($requestId, fn () => $this->flushSection());
    }

    public function autosaveSelected(string $group, int $requestId = 0): array
    {
        $this->autosaving = true;
        try {
            return $this->perform($requestId, function () use ($group): void {
                if (! Str::isUuid($group)) {
                    throw ValidationException::withMessages(['history' => 'Use Save draft to accept this input.']);
                }
                $this->typingGroup = $group;
                $this->flushSection();
            });
        } finally {
            $this->autosaving = false;
        }
    }

    public function selectNode(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            app(TreeResolver::class)->locate($this->document(), $id);
            $this->selectedId = $id;
            $this->treeMoveParentId = app(TreeResolver::class)->locate($this->document(), $id)->parentId;
            $this->fillSection();
        }, false);
    }

    public function changeLocale(string $locale, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($locale): void {
            if (! app(LocaleCatalog::class)->has($locale)) {
                throw ValidationException::withMessages(['locale' => 'Choose a configured language.']);
            }
            $this->flushSection();
            $this->locale = $locale;
            $this->fillSection();
            $this->refreshPreview();
        }, false);
    }

    public function openTreePicker(?string $parentId = null, string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($parentId, $position, $anchor): void {
            $this->flushSection();
            $this->treePosition($position, $anchor)->index(app(TreeResolver::class)->children($this->document(), $this->treeSlot($parentId)));
            $this->pickerPosition = ['parentId' => $parentId, 'kind' => $position, 'anchor' => $anchor];
            $this->newComponentType = '';
            $this->pickerOpen = true;
        });
    }

    public function closePicker(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->pickerOpen = false;
        }, false);
    }

    public function applyComponent(int $requestId = 0): array
    {
        return $this->addTreeSection($this->newComponentType, $this->pickerPosition['parentId'], $this->pickerPosition['kind'], $this->pickerPosition['anchor'], $requestId);
    }

    public function addTreeSection(string $type, ?string $parentId = null, string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($type, $parentId, $position, $anchor): void {
            $this->flushSection();
            $before = app(TreeResolver::class)->nodes($this->document());
            $next = app(TreeOperations::class)->add($this->document(), $type, $this->treeSlot($parentId), $this->treePosition($position, $anchor), $this->context());
            $ids = array_values(array_diff(array_keys(app(TreeResolver::class)->nodes($next)), array_keys($before)));
            $this->saveDocument($next, 'Add component');
            $this->selectedId = $ids[0] ?? null;
            $this->fillSection();
            $this->pickerOpen = false;
            $this->responseExtras = ['insertedIds' => $ids === [] ? [] : [$ids[0]]];
        });
    }

    public function insertInstalledPattern(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $registry = app(StartingPointRegistry::class);
            $descriptor = $registry->find($id);
            if ($descriptor === null || $descriptor->sourceKind !== 'code-template' || ! str_starts_with($id, 'pattern:')) {
                throw new \InvalidArgumentException('Choose an installed pattern.');
            }
            $source = $registry->snapshot($id, $descriptor->version, $this->locale);
            $selection = new SourceSelection($source->kind, $source->id, $source->version, $source->digest, $this->locale);
            $this->accept(app(SitePartCopyCommands::class)->insert($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $selection, history: $this->historyContext()));
            $this->selectedId = $this->document()->nodes[array_key_last($this->document()->nodes)]->id;
            $this->fillSection();
        });
    }

    #[Unexposed]
    public function installedPatterns(): array
    {
        return array_values(array_filter(app(StartingPointRegistry::class)->all(), fn ($entry): bool => $entry->sourceKind === 'code-template' && str_starts_with($entry->id, 'pattern:')));
    }

    public function moveToParent(int $requestId = 0): array
    {
        return $this->moveTreeSection($this->selectedId ?? '', $this->treeMoveParentId, 'end', null, $requestId);
    }

    public function moveTreeSection(string $id, ?string $parentId = null, string $position = 'end', ?string $anchor = null, int $requestId = 0): array
    {
        return $this->perform($requestId, fn () => $this->movePhysicalNode($id, $parentId, $position, $anchor));
    }

    private function movePhysicalNode(string $id, ?string $parentId, string $position, ?string $anchor): void
    {
        $this->flushSection();
        $resolver = app(TreeResolver::class);
        $this->saveDocument(app(TreeOperations::class)->move($this->document(), $id, $resolver->slotFor($this->document(), $id), $this->treeSlot($parentId), $this->treePosition($position, $anchor), $this->context()), 'Move component');
        $this->selectedId = $id;
        $this->fillSection();
        $this->responseExtras = ['focusNodeId' => $id];
    }

    public function moveSection(string $id, int $direction, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id, $direction): void {
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $target = $resolver->children($this->document(), $location->slot())[$location->index + $direction] ?? null;
            if (! in_array($direction, [-1, 1], true) || $target === null) {
                throw ValidationException::withMessages(['tree' => 'This component cannot move further.']);
            }
            $this->movePhysicalNode($id, $location->parentId, $direction < 0 ? 'before' : 'after', $target->id);
        });
    }

    public function moveSectionIn(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $previous = $resolver->children($this->document(), $location->slot())[$location->index - 1] ?? null;
            if ($previous === null) {
                throw ValidationException::withMessages(['tree' => 'Choose a preceding compatible parent.']);
            }
            $this->movePhysicalNode($id, $previous->id, 'end', null);
        });
    }

    public function moveSectionOut(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            if ($location->parentId === null) {
                throw ValidationException::withMessages(['tree' => 'This component is at the resource root.']);
            }
            $this->movePhysicalNode($id, $resolver->locate($this->document(), $location->parentId)->parentId, 'after', $location->parentId);
        });
    }

    public function duplicateSection(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $tree = app(TreeResolver::class);
            $before = array_keys($tree->nodes($this->document()));
            $next = app(TreeOperations::class)->duplicate($this->document(), $id, $tree->slotFor($this->document(), $id), $this->context());
            $ids = array_values(array_diff(array_keys($tree->nodes($next)), $before));
            $this->saveDocument($next, 'Duplicate component');
            $this->selectedId = $ids[0] ?? null;
            $this->fillSection();
            $this->responseExtras = ['insertedIds' => $ids === [] ? [] : [$ids[0]]];
        });
    }

    public function hideSection(string $id, bool $hidden, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id, $hidden): void {
            $this->flushSection();
            $this->saveDocument(app(TreeOperations::class)->visibility($this->document(), $id, app(TreeResolver::class)->slotFor($this->document(), $id), ! $hidden, $this->context()), $hidden ? 'Hide component' : 'Show component');
        });
    }

    public function removeSection(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $next = app(TreeOperations::class)->remove($this->document(), $id, $location->slot(), $this->context());
            $this->saveDocument($next, 'Remove component');
            if (! isset($resolver->nodes($next)[$this->selectedId])) {
                $this->selectedId = $location->parentId ?? ($next->nodes[0]->id ?? null);
            }
            $this->fillSection();
            $this->responseExtras = ['focusNodeId' => $this->selectedId, 'focusEmptyAdd' => $this->selectedId === null];
        });
    }

    public function saveSettings(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->flushSettings();
        });
    }

    private function flushSettings(): void
    {
        if ($this->same($this->settingsData, $this->settingsBaseline)) {
            return;
        }
        $this->withGuard(function (): void {
            $data = $this->settingsForm->getState();
            if ($this->kind === 'overlay') {
                $document = OverlaySettings::with($this->document(), $this->locale, $data['overlay_title'], $data['presentation'], $data['size']);
                $this->saveDocument($document, 'Edit popup settings');
            }
            $metadata = array_intersect_key($data, array_flip(['name', 'description']));
            $this->accept(app(SitePartCommands::class)->saveMetadata($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $metadata, $this->historyContext()));
            $this->settingsBaseline = $this->settingsData;
        });
    }

    public function publish(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->flushSettings();
            if ($this->designThemeChoice !== $this->designThemeBaseline) {
                throw ValidationException::withMessages(['theme' => 'Review/apply or discard the pending theme before publication.']);
            }
            $this->accept(app(SitePartCommands::class)->publish($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $this->draftDigest), true);
            $this->message = 'Published an immutable site-part revision. Existing page pins are unchanged.';
        });
    }

    public function unpublish(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->accept(app(SitePartCommands::class)->unpublish($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion), true);
            $this->message = 'Unpublished this resource for new use. Review historical revision availability separately.';
        });
    }

    public function restoreRevision(string $revisionId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($revisionId): void {
            $this->flushSection();
            $this->accept(app(SitePartCommands::class)->restore($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $revisionId), true);
            $this->selectedId = $this->document()->nodes[0]->id ?? null;
            $this->fillSection();
        });
    }

    public function undoDraft(int $requestId = 0): array
    {
        return $this->travel(false, $requestId);
    }

    public function redoDraft(int $requestId = 0): array
    {
        return $this->travel(true, $requestId);
    }

    public function historyPage(?string $before = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($before): void {
            $this->revisionBefore = $before;
        }, false);
    }

    public function archivePart(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            if (! $this->archiveConfirmed) {
                throw ValidationException::withMessages(['archive' => 'Confirm archiving this independent resource.']);
            }
            $this->flushSection();
            $this->accept(app(SitePartCommands::class)->archive($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion));
            $this->readOnly = true;
            $this->message = 'Resource archived. Review existing published pins and withdrawals separately.';
        });
    }

    public function withdrawRevision(string $revisionId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($revisionId): void {
            if (! $this->withdrawConfirmed || trim($this->withdrawReason) === '') {
                throw ValidationException::withMessages(['withdraw' => 'Review affected pins, supply a reason and confirm withdrawal.']);
            }
            if ($this->impactReference()->revisionId !== $revisionId) {
                throw ValidationException::withMessages(['withdraw' => 'Review this exact immutable revision’s impact before withdrawing it.']);
            }
            $this->flushSection();
            $this->accept(app(SitePartCommands::class)->withdraw($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $revisionId, $this->withdrawReason), true);
            $this->withdrawConfirmed = false;
            $this->withdrawReason = '';
            $this->message = 'Revision withdrawn. Pages requiring it remain fail-closed until explicitly reconciled.';
        });
    }

    public function deletePart(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            if (! $this->deleteConfirmed) {
                throw ValidationException::withMessages(['delete' => 'Review dependencies and explicitly confirm permanent resource deletion.']);
            }
            $this->flushSection();
            $this->releaseStageLeases();
            app(SitePartCommands::class)->delete($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion);
            $this->readOnly = true;
            $this->settingsData = $this->settingsBaseline;
            $this->redirect(SitePartResource::getUrl('index'));
            $this->skipRender();
        });
    }

    public function heartbeat(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->withGuard(fn () => app(SitePartLocks::class)->renew($this->actor(), $this->partId, $this->leaseToken));
        });
    }

    public function reloadSaved(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->releaseStageLeases();
            $this->stageReview = [];
            $part = $this->part();
            try {
                app(SitePartLocks::class)->release($this->actor(), $this->partId, $this->leaseToken);
            } catch (WriteConflict|AuthorizationException) {
            }
            $this->leaseToken = (string) Str::uuid();
            $this->readOnly = true;
            $this->loadPart($part);
            if ($this->unsupportedPayload || $part->archived_at !== null) {
                $this->state = 'read_only';
                $this->message = $this->unsupportedPayload ? 'This unsupported resource payload is retained read-only.' : 'This archived resource is retained read-only.';
                $this->fillSection();
                $this->fillSettings();
                $this->refreshPreview();

                return;
            }
            app(SitePartLocks::class)->acquire($this->actor(), $this->partId, $this->leaseToken);
            $this->readOnly = false;
            $this->openHistory(true);
            $this->selectedId = $this->document()->nodes[0]->id ?? null;
            $this->fillSection();
            $this->fillDesign(true);
            $this->fillSettings();
            $this->refreshPreview();
        }, false);
    }

    private function perform(int $requestId, Closure $action, bool $editing = true): array
    {
        $this->part();
        $this->responseExtras = [];
        try {
            if ($editing) {
                $this->withGuard(fn () => null);
            }
            $action();
            if (! $this->readOnly) {
                $this->state = 'saved';
                if (! $this->autosaving && $this->historyContext() !== null) {
                    $this->acceptHistory(app(SitePartDraftHistory::class)->closeGroup($this->actor(), $this->partId, $this->leaseToken, $this->historyContext()));
                }
            }

            return ['ok' => true, 'requestId' => $requestId, 'version' => $this->writeVersion, 'history' => $this->draftHistoryState, ...$this->responseExtras];
        } catch (WriteConflict $exception) {
            $this->conflict($exception);
        } catch (ValidationException $exception) {
            $this->state = 'invalid';
            $this->message = collect($exception->errors())->flatten()->first() ?? 'Check the fields.';
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (\InvalidArgumentException $exception) {
            $this->state = 'invalid';
            $this->message = $exception->getMessage();
        } catch (AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->readOnly = true;
            $this->state = 'failed';
            $this->message = 'The request failed. Keep/download input before reloading this resource.';
        }

        return ['ok' => false, 'requestId' => $requestId, 'version' => $this->writeVersion, 'history' => $this->draftHistoryState, 'state' => $this->state, 'message' => $this->message];
    }

    private function flushSection(bool $withDesign = true): void
    {
        if (! $this->same($this->formData, $this->formBaseline)) {
            $this->withGuard(function (): void {
                $node = $this->selectedNode();
                $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
                if ($definition?->version() !== $node?->version || ! $this->editableAncestors() || app(ComponentFormRegistry::class)->resolve($node->type) === null) {
                    throw ValidationException::withMessages(['content' => 'This physical subtree is read-only; its stored content is retained.']);
                }
                $dehydrated = $this->sectionForm->getState();
                $replacement = app(NodeFormState::class)->patch($node, $definition, $this->locale, $dehydrated, $this->formBaseline, $this->formData, $this->repeaterClones);
                $next = $this->replaceNode($this->document(), $replacement);
                $changed = array_keys(array_filter($this->formData, fn ($value, $key): bool => ! $this->same($this->formBaseline[$key] ?? null, $value), ARRAY_FILTER_USE_BOTH));
                $typing = $this->typingGroup !== null && count($changed) <= 64 && $changed !== [] && array_all($changed, fn ($key): bool => preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,127}$/D', $key) === 1 && in_array($definition->properties()[$key]->kind ?? null, ['text', 'html', 'url'], true));
                $this->accept(app(SitePartCommands::class)->saveDraft($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, SitePartPayload::document($this->kind, CompositionData::promote($next)), $this->historyContext(), new LogicalAction($typing ? 'typing' : 'save', 'Edit site-part content', $typing ? $this->typingGroup : null, $node->id, $this->locale, $typing ? $changed : [])));
                $this->formBaseline = $this->formData;
                $this->resetErrorBag();
            });
        }
        if ($withDesign) {
            $this->flushDesign();
        }
    }

    private function saveDocument(Document $document, string $label): void
    {
        $this->accept(app(SitePartCommands::class)->saveDraft($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, SitePartPayload::document($this->kind, $document), $this->historyContext(), new LogicalAction('tree', $label)));
    }

    private function withGuard(Closure $operation): mixed
    {
        if ($this->readOnly) {
            throw new WriteConflict('editor_read_only', $this->writeVersion);
        }

        return DB::transaction(function () use ($operation): mixed {
            $part = SitePart::query()->lockForUpdate()->findOrFail($this->partId);
            Gate::forUser($this->actor())->authorize('update', $part);
            app(SitePartLocks::class)->assertHeld($part, ActorIdentity::from($this->actor()), $this->leaseToken);
            if ($part->write_version !== $this->writeVersion) {
                throw new WriteConflict('stale_version', $part->write_version);
            } if ($this->historyContext() !== null) {
                app(SitePartDraftHistory::class)->assertCurrent($this->historyContext(), $this->actor(), $part, $this->leaseToken);
            }

            return $operation();
        });
    }

    private function accept(SitePartMutationResult $result, bool $boundary = false): void
    {
        $this->loadPart($result->part);
        $this->message = '';
        if ($result->part->archived_at !== null) {
            $this->readOnly = true;
            $this->draftHistoryContextData = [];
            $this->draftHistoryState = ['available' => false, 'canUndo' => false, 'canRedo' => false, 'undoCount' => 0, 'redoCount' => 0];
        } if ($boundary) {
            $this->openHistory(true);
        } elseif ($this->historyContext() !== null) {
            $this->acceptHistory(app(SitePartDraftHistory::class)->status($this->actor(), $this->partId, $this->leaseToken, $this->historyContext()));
        }
        $this->refreshPreview();
    }

    private function loadPart(SitePart $part): void
    {
        $this->kind = $part->kind;
        $this->partName = $part->name;
        $this->writeVersion = $part->write_version;
        try {
            $payload = $part->draft_payload;
            $this->payloadJson = app(CanonicalJson::class)->encode($payload);
            $this->draftDigest = app(CanonicalJson::class)->digest($payload);
            $this->documentJson = app(DocumentCodec::class)->encode($payload->validationDocument());
            $this->unsupportedPayload = false;
        } catch (\InvalidArgumentException|\JsonException $exception) {
            $this->payloadJson = (string) $part->getRawOriginal('draft_payload');
            $this->draftDigest = hash('sha256', $this->payloadJson);
            $this->documentJson = app(DocumentCodec::class)->encode(new Document);
            $this->unsupportedPayload = true;
            $this->readOnly = true;
            $this->state = 'unsupported';
            $this->message = 'This resource payload is unsupported and retained read-only. Download it before a reviewed migration.';
        }
    }

    private function document(): Document
    {
        return app(DocumentCodec::class)->decode($this->documentJson);
    }

    private function selectedNode(): ?Node
    {
        return $this->selectedId === null ? null : (app(TreeResolver::class)->nodes($this->document())[$this->selectedId]->node ?? null);
    }

    private function fillSection(): void
    {
        $this->clearSnapshotReview();
        $this->clearMediaBatch();
        $this->snapshotChoices = [];
        if ($this->selectedId === null && $this->kind !== 'design') {
            $this->selectedId = $this->document()->nodes[0]->id ?? null;
        }
        $this->cacheSchema('sectionForm', $this->sectionForm(Schema::make($this)));
        $node = $this->selectedNode();
        $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
        $this->sectionForm->fill($node !== null && $definition !== null ? app(NodeFormState::class)->project($node, $definition, $this->locale) : []);
        $this->formBaseline = $this->formData;
        $this->repeaterClones = [];
        $this->fillDesign();
    }

    private function fillSettings(): void
    {
        $part = $this->part();
        $this->cacheSchema('settingsForm', $this->settingsForm(Schema::make($this)));
        $overlay = $this->kind === 'overlay' ? OverlaySettings::values($this->document(), $this->locale) : [];
        $this->settingsForm->fill(['name' => $part->name, 'description' => $part->description, ...($overlay === [] ? [] : ['overlay_title' => $overlay['title'], 'presentation' => $overlay['presentation'], 'size' => $overlay['size']])]);
        $this->settingsBaseline = $this->settingsData;
    }

    private function editableAncestors(): bool
    {
        if ($this->selectedId === null) {
            return false;
        } $location = app(TreeResolver::class)->nodes($this->document())[$this->selectedId] ?? null;

        return $location !== null && ! app(RenderFeatureRegistry::class)->readOnly($this->document()) && array_all([...$location->ancestors, $location->node], fn (Node $node): bool => app(ComponentRegistry::class)->resolve($node->type)?->version() === $node->version && ! app(RenderFeatureRegistry::class)->readOnly($this->document(), $node));
    }

    private function replaceNode(Document $document, Node $replacement): Document
    {
        $walk = function (array $nodes) use (&$walk, $replacement): array {
            return array_map(fn (Node $node): Node => $node->id === $replacement->id ? $replacement : new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $walk($node->children), $node->extensions), $nodes);
        };

        return new Document($walk($document->nodes), $document->extensions);
    }

    private function treeSlot(?string $parent): TreeSlot
    {
        return $parent === null || $parent === '' ? TreeSlot::root() : TreeSlot::children($parent);
    }

    private function treePosition(string $placement, ?string $anchor): TreePosition
    {
        return match ($placement) {
            'start' => TreePosition::start(), 'end' => TreePosition::end(), 'before' => TreePosition::before($anchor ?? ''), 'after' => TreePosition::after($anchor ?? ''), default => throw ValidationException::withMessages(['tree' => 'Choose a supported placement.'])
        };
    }

    private function context(): RenderContext
    {
        return app(SitePartCommands::class)->context($this->locale);
    }

    private function part(): SitePart
    {
        $part = SitePart::query()->findOrFail($this->partId);
        Gate::forUser($this->actor())->authorize('view', $part);

        return $part;
    }

    private function actor(): Authenticatable
    {
        return Filament::auth()->user() ?? abort(403);
    }

    private function same(mixed $before, mixed $after): bool
    {
        return app(NodeFormState::class)->sameRaw($before, $after);
    }

    private function conflict(WriteConflict $exception): void
    {
        $this->readOnly = true;
        $this->state = 'conflict';
        $this->message = 'This resource changed or its editing/history access expired. Your input is retained; download it before requesting fresh access.';
    }

    private function refreshPreview(): void
    {
        $this->previewUrl = ! $this->unsupportedPayload && Gate::forUser($this->actor())->allows('preview', $this->part()) ? URL::temporarySignedRoute(Filament::getCurrentPanel()->generateRouteName('site-builder.part-preview'), now()->addMinutes(30), ['part' => $this->partId, 'locale' => $this->locale]) : '';
    }

    private function historyContext(): ?HistoryContext
    {
        return $this->draftHistoryContextData === [] ? null : HistoryContext::fromArray($this->draftHistoryContextData);
    }

    private function openHistory(bool $reset = false): void
    {
        $this->draftHistoryContextData = [];
        $this->draftHistoryState = ['available' => false, 'canUndo' => false, 'canRedo' => false, 'undoCount' => 0, 'redoCount' => 0];
        if (! $this->readOnly && app(SitePartDraftHistory::class)->available()) {
            try {
                $this->acceptHistory(app(SitePartDraftHistory::class)->open($this->actor(), $this->partId, $this->leaseToken, $this->tabId, $reset));
            } catch (ValidationException $exception) {
                $this->message = collect($exception->errors())->flatten()->first();
            }
        }
    }

    private function acceptHistory(HistorySession $session): void
    {
        $this->draftHistoryContextData = $session->context->toArray();
        $this->draftHistoryState = [...$session->status, 'canUndo' => $session->status['undoCount'] > 0, 'canRedo' => $session->status['redoCount'] > 0];
    }

    private function travel(bool $redo, int $requestId): array
    {
        return $this->perform($requestId, function () use ($redo): void {
            $this->flushSection();
            $context = $this->historyContext();
            if ($context === null) {
                throw ValidationException::withMessages(['history' => 'Install optional history support for site-part undo.']);
            } $history = app(SitePartDraftHistory::class);
            $result = $redo ? $history->redo($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $context) : $history->undo($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $context);
            $this->accept($result->mutation);
            $this->acceptHistory($result->history);
            if (! isset(app(TreeResolver::class)->nodes($this->document())[$this->selectedId])) {
                $this->selectedId = $this->document()->nodes[0]->id ?? null;
            }
            $this->fillSection();
            $this->fillDesign(true);
            $this->responseExtras = ['focusNodeId' => $this->selectedId, 'focusEmptyAdd' => $this->selectedId === null];
        });
    }

    #[Exposed]
    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        return $this->nativeAction(fn (): mixed => parent::mountAction($name, $arguments, $context));
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        return $this->nativeAction(fn (): mixed => parent::callMountedAction($arguments));
    }

    private function nativeAction(Closure $action): mixed
    {
        try {
            return $this->withGuard($action);
        } catch (WriteConflict $exception) {
            $this->conflict($exception);

            return null;
        }
    }

    #[Unexposed]
    public function rememberRepeaterClone(string $property, string $sourceKey, string $newKey): void
    {
        $this->withGuard(function () use ($property, $sourceKey, $newKey): void {
            if (isset($this->formBaseline[$property][$sourceKey],$this->formData[$property][$newKey])) {
                $this->repeaterClones[$property][$newKey] = $sourceKey;
            }
        });
    }

    protected function afterActionCalled(Action $action): void
    {
        $this->saveSelected();
    }

    private function resourceThemeChanges(): array
    {
        $themes = app(DesignCatalog::class)->themes($this->context());
        $format = function (mixed $value) use ($themes): string {
            if (is_array($value) && isset($value['id'],$value['version'],$value['preset'])) {
                foreach ($themes as $theme) {
                    if ($theme['id'] === $value['id'] && $theme['version'] === $value['version'] && $theme['preset'] === $value['preset']) {
                        return $theme['label'].' v'.$theme['version'];
                    }
                }

                return $value['id'].' v'.$value['version'].' / '.$value['preset'];
            }

            return is_scalar($value) ? (string) $value : ($value === null ? 'Inherited' : 'Registered defaults');
        };
        $changes = [];
        foreach ($this->designThemeReview['report']['changedDefaults'] ?? [] as $key => $change) {
            if (is_string($change)) {
                $changes[] = $change;
            } elseif (is_array($change)) {
                $changes[] = Str::headline((string) $key).': '.$format($change['before'] ?? $change['from'] ?? null).' → '.$format($change['after'] ?? $change['to'] ?? null);
                if (is_string($change['message'] ?? null)) {
                    $changes[] = $change['message'];
                }
            }
        }

        return $changes;
    }

    protected function getViewData(): array
    {
        $part = $this->part();
        $description = $this->designDescription();
        $slot = $this->treeSlot($this->pickerPosition['parentId']);
        $choices = [];
        if ($this->pickerOpen) {
            foreach (app(ComponentFormRegistry::class)->all() as $type => $form) {
                if (app(TreeOperations::class)->canInsertType($this->document(), $type, $slot, $this->context())) {
                    $choices[$type] = $form->label();
                }
            }
        }

        return [...app(TreePresentation::class)->describe($this->document(), $this->context(), $this->selectedId, $this->treeMoveParentId), 'componentChoices' => $choices, 'locales' => config('site-builder.locales'), 'hasForm' => $this->selectedNode() !== null && $this->editableAncestors() && app(ComponentFormRegistry::class)->resolve($this->selectedNode()->type) !== null,
            'designDescription' => $description, 'resourceThemeChanges' => $this->resourceThemeChanges(), 'designThemes' => app(DesignCatalog::class)->themes($this->context()), 'revisions' => app(SitePartHistory::class)->revisions($this->actor(), $this->partId, 20, $this->revisionBefore),
            'canPublish' => Gate::forUser($this->actor())->allows('publish', $part), 'canRestore' => Gate::forUser($this->actor())->allows('restore', $part), 'canWithdraw' => Gate::forUser($this->actor())->allows('withdraw', $part), 'canDelete' => Gate::forUser($this->actor())->allows('delete', $part),
            'releaseUrl' => Filament::getCurrentPanel()->route('site-builder.part-release', ['part' => $this->partId]), 'indexUrl' => SitePartResource::getUrl('index'), 'editorAssetVersion' => substr(hash_file('sha256', __DIR__.'/../../../resources/dist/site-part-editor.js'), 0, 16)];
    }
}
