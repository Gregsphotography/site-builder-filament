<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeCommands;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageHistory;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Routing\PageUrlResolver;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Filament\Contracts\EditorIdentityName;
use SiteBuilder\Filament\Contracts\EditorName;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Forms\NodeFormState;
use SiteBuilder\Filament\Resources\Pages\Concerns\ClipboardDraft;
use SiteBuilder\Filament\Resources\Pages\Concerns\ComposesDraft;
use SiteBuilder\Filament\Resources\Pages\Concerns\CopiesSnapshots;
use SiteBuilder\Filament\Resources\Pages\Concerns\DesignsDraft;
use SiteBuilder\Filament\Resources\Pages\Concerns\HistoriesDraft;
use SiteBuilder\Filament\Resources\Pages\Concerns\SelectsGalleryMedia;
use SiteBuilder\Filament\Resources\Pages\Concerns\SitePartsDraft;
use SiteBuilder\Filament\Resources\Pages\Concerns\TreesDraft;
use SiteBuilder\Filament\Support\OptionalComponents;
use stdClass;
use Throwable;

final class PageEditor extends ResourcePage
{
    use ClipboardDraft;
    use ComposesDraft;
    use CopiesSnapshots;
    use DesignsDraft;
    use HistoriesDraft;
    use SelectsGalleryMedia;
    use SitePartsDraft;
    use TreesDraft;

    protected static string $resource = PageResource::class;

    protected static string $layout = 'filament-panels::components.layout.base';

    protected string $view = 'site-builder-filament::pages.editor';

    #[Locked]
    public int $pageId;

    #[Locked]
    public string $tabId;

    #[Locked]
    public string $leaseToken;

    #[Locked]
    public string $channel;

    #[Locked]
    public int $writeVersion = 0;

    #[Locked]
    public string $draftDigest = '';

    #[Locked]
    public string $documentJson = '';

    #[Locked]
    public string $rawDocument = '';

    #[Locked]
    public int $previewExpiresAt = 0;

    #[Locked]
    public string $locale = '';

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public array $formBaseline = [];

    #[Locked]
    public array $repeaterClones = [];

    #[Locked]
    public array $settingsBaseline = [];

    #[Locked]
    public string $previewUrl = '';

    #[Locked]
    public bool $readOnly = true;

    #[Locked]
    public string $state = 'loading';

    #[Locked]
    public string $message = '';

    #[Locked]
    public string $pageTitle = '';

    #[Locked]
    public string $publicationStatus = 'draft';

    #[Locked]
    public ?int $historyBefore = null;

    public array $formData = [];

    public array $settingsData = [];

    public bool $deleteConfirmed = false;

    public function mount(int|string $record): void
    {
        $page = Page::query()->findOrFail($record);
        Gate::forUser($this->actor())->authorize('view', $page);
        $this->pageId = (int) $page->id;
        $this->tabId = (string) Str::uuid();
        $this->leaseToken = (string) Str::uuid();
        $this->channel = (string) Str::uuid();
        $this->locale = app(LocaleCatalog::class)->default();
        $this->loadPage($page);
        if (Gate::forUser($this->actor())->allows('update', $page) && $this->documentJson !== '') {
            try {
                app(PageLocks::class)->acquire($this->actor(), $this->pageId, $this->leaseToken);
                $this->readOnly = false;
                $this->state = 'saved';
            } catch (WriteConflict $exception) {
                $this->conflict($exception);
            }
        } elseif ($this->documentJson !== '') {
            $this->state = 'read_only';
            $this->message = 'You can view this page. Editing is not permitted.';
        }
        $this->openDraftHistory();
        $this->fillSection();
        $this->refreshPreview();
    }

    #[Unexposed]
    public function sectionForm(Schema $schema): Schema
    {
        $node = $this->selectedNode();
        $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
        $form = $node === null ? null : app(ComponentFormRegistry::class)->resolve($node->type);
        $supported = $this->selectedFormEditable();
        $picker = app(OptionalComponents::class)->picker();

        return $schema->components($supported && $form !== null ? $form->schema(new EditorContext($this->locale, $this->pageId, $node->id, $this->readOnly, $picker, app(NodeFormState::class)->project($node, $definition, $this->locale))) : [])
            ->statePath('formData')->disabled(fn (): bool => $this->readOnly)->columns(1);
    }

    #[Unexposed]
    public function settingsForm(Schema $schema): Schema
    {
        $layouts = array_combine(app(LayoutRegistry::class)->keys(), array_map(Str::headline(...), app(LayoutRegistry::class)->keys()));

        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('type')->required()->maxLength(255),
            Select::make('layout')->options($layouts)->placeholder('Default layout'),
            TextInput::make('slug')->label('URL path for '.$this->locale)->helperText('Use home for this language’s root. Clear to remove this translation’s URL.')->maxLength(255),
            TextInput::make('meta_title')->label('Search title'),
            Textarea::make('meta_description')->label('Search description'),
            TextInput::make('canonical')->label('Canonical URL'),
            TextInput::make('og_image')->label('Social image path'),
            Toggle::make('noindex')->label('Exclude this language from indexing'),
            Toggle::make('nofollow')->label('Ask crawlers not to follow links'),
            Textarea::make('schema_json')->label('Structured data (JSON)')->rows(5),
            Textarea::make('data_json')->label('Custom page data (JSON)')->rows(4),
        ])->statePath('settingsData')->disabled(fn (): bool => $this->readOnly)->columns(1);
    }

    public function saveSelected(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
        });
    }

    public function selectNode(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->flushSection();
            $selected = app(TreeResolver::class)->nodes($this->document())[$id] ?? null;
            if ($selected === null) {
                throw ValidationException::withMessages(['section' => 'This section no longer exists.']);
            }
            $this->selectedId = $selected->node->id;
            $this->treeMoveParentId = $selected->parentId;
            $this->selectedSitePartBinding = null;
            $this->fillSection();
        });
    }

    public function changeLocale(string $locale, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($locale): void {
            if (! app(LocaleCatalog::class)->has($locale)) {
                throw ValidationException::withMessages(['locale' => 'Select a configured language.']);
            }
            $this->flushSection();
            $this->flushSettings();
            $this->locale = $locale;
            $this->fillSection();
            $this->fillSettings($this->page());
            $this->refreshPreview();
        });
    }

    public function addSection(string $type, ?int $index = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($type, $index): void {
            $this->editable();
            $this->flushSection();
            $nodes = $this->document()->nodes;
            if ($index !== null && ($index < 0 || $index > count($nodes))) {
                throw ValidationException::withMessages(['section' => 'Choose an existing insertion position.']);
            }
            $position = $index !== null && isset($nodes[$index]) ? TreePosition::before($nodes[$index]->id) : TreePosition::end();
            $this->acceptTree(app(TreeCommands::class)->add($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $type, TreeSlot::root(), $position, $this->draftHistoryContext()));
        });
    }

    public function duplicateSection(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->editable();
            $this->flushSection();
            $this->acceptTree(app(TreeCommands::class)->duplicate($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id,
                app(TreeResolver::class)->slotFor($this->document(), $id), $this->draftHistoryContext()));
        });
    }

    public function hideSection(string $id, bool $hidden, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id, $hidden): void {
            $this->editable();
            $this->flushSection();
            $this->acceptTree(app(TreeCommands::class)->visibility($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id, app(TreeResolver::class)->slotFor($this->document(), $id), ! $hidden, $this->draftHistoryContext()));
        });
    }

    public function removeSection(string $id, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id): void {
            $this->editable();
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $siblings = $resolver->children($this->document(), $location->slot());
            $nextSelection = $siblings[$location->index + 1]->id ?? $siblings[$location->index - 1]->id ?? $location->parentId;
            $result = app(TreeCommands::class)->remove($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id, $location->slot(), $this->draftHistoryContext());
            $this->accept($result->mutation);
            if (! isset($resolver->nodes($this->document())[$this->selectedId])) {
                $this->selectedId = $nextSelection ?? ($this->document()->nodes[0]->id ?? null);
                $this->fillSection();
            }
            $this->responseExtras = ['focusNodeId' => $this->selectedId, 'focusEmptyAdd' => $this->document()->nodes === [], 'announcement' => 'Section removed from the draft.'];
        });
    }

    public function moveSection(string $id, int $direction, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($id, $direction): void {
            $this->editable();
            $this->flushSection();
            $resolver = app(TreeResolver::class);
            $location = $resolver->locate($this->document(), $id);
            $siblings = $resolver->children($this->document(), $location->slot());
            $next = $location->index + $direction;
            if (! in_array($direction, [-1, 1], true) || ! isset($siblings[$next])) {
                throw ValidationException::withMessages(['order' => 'This section cannot move further within its parent.']);
            }
            $position = $direction < 0 ? TreePosition::before($siblings[$next]->id) : TreePosition::after($siblings[$next]->id);
            $this->acceptTree(app(TreeCommands::class)->move($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $id, $location->slot(), $location->slot(), $position, $this->draftHistoryContext()));
            $this->responseExtras['announcement'] = 'Section moved within its parent to position '.($next + 1).'.';
        });
    }

    public function saveSettings(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->flushSettings();
        });
    }

    public function publish(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->flushSettings();
            $this->assertDesignThemeSettled();
            $this->accept(app(PageCommands::class)->publish($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->draftDigest), true);
            $this->message = 'Published. Your accepted draft is now live.';
        });
    }

    public function unpublish(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->accept(app(PageCommands::class)->unpublish($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion), true);
            $this->message = 'Unpublished. Content snapshots and publication revisions are retained; document undo starts fresh.';
        });
    }

    public function restoreRevision(int $revisionId, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($revisionId): void {
            $this->editable();
            $this->flushSection();
            $this->accept(app(PageCommands::class)->restore($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $revisionId), true);
            $this->selectedId = $this->document()->nodes[0]->id ?? null;
            $this->fillSection();
            $this->message = 'Restored into the draft. The published page is unchanged.';
        });
    }

    public function deletePage(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            if (! $this->deleteConfirmed) {
                throw ValidationException::withMessages(['delete' => 'Review page references and confirm deletion first.']);
            }
            $this->flushSection();
            app(PageCommands::class)->delete($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion);
            $this->readOnly = true;
            $this->redirect(PageResource::getUrl('index'));
        });
    }

    public function heartbeat(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            if ($this->page()->write_version !== $this->writeVersion) {
                throw new WriteConflict('stale_version', $this->page()->write_version);
            }
            app(PageLocks::class)->renew($this->actor(), $this->pageId, $this->leaseToken);
            if ($this->previewExpiresAt < time() + 60) {
                $this->refreshPreview();
            }
        });
    }

    public function historyPage(?int $before = null, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($before): void {
            $this->historyBefore = $before;
        });
    }

    public function reloadSaved(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $page = $this->page();
            $oldToken = $this->leaseToken;
            $this->readOnly = true;
            $this->state = 'read_only';
            $this->loadPage($page);
            $this->leaseToken = (string) Str::uuid();
            $conflict = null;
            if (Gate::forUser($this->actor())->allows('update', $page) && $this->documentJson !== '') {
                $lock = $page->editLock()->first();
                if ($lock !== null && $lock->expires_at->isFuture() && ActorIdentity::from($this->actor())->holds($lock->user_type, $lock->user_id) && hash_equals($lock->token_hash, hash('sha256', $oldToken))) {
                    app(PageLocks::class)->release($this->actor(), $this->pageId, $oldToken);
                }
                try {
                    app(PageLocks::class)->acquire($this->actor(), $this->pageId, $this->leaseToken);
                    $this->readOnly = false;
                    $this->state = 'saved';
                    $this->message = '';
                } catch (WriteConflict $exception) {
                    $conflict = $exception;
                }
            }
            $this->openDraftHistory(true);
            $this->clearClipboardReview();
            $this->fillSection();
            $this->fillDesign(true);
            $this->refreshPreview();
            if ($conflict !== null) {
                throw $conflict;
            }
        });
    }

    private function perform(int $requestId, Closure $action): array
    {
        $this->page();
        $this->responseExtras = [];
        try {
            $action();
            $this->closeDraftHistoryGroup();
            if (! $this->readOnly) {
                $this->state = 'saved';
            }

            return ['ok' => true, 'requestId' => $requestId, 'version' => $this->writeVersion, 'history' => $this->draftHistoryState, ...$this->responseExtras];
        } catch (WriteConflict $exception) {
            $this->conflict($exception);
        } catch (ValidationException $exception) {
            $this->state = 'invalid';
            $this->message = collect($exception->errors())->flatten()->first() ?? 'Check the form values.';
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
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
            $this->message = 'The action failed. Keep your unsaved changes and reload saved content before continuing.';
        }

        return ['ok' => false, 'requestId' => $requestId, 'version' => $this->writeVersion, 'state' => $this->state, 'message' => $this->message, 'history' => $this->draftHistoryState];
    }

    private function flushSection(bool $withDesign = true): void
    {
        if (! $this->same($this->formBaseline, $this->formData)) {
            $this->editable();
            $node = $this->selectedNode();
            $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
            if (! $this->selectedFormEditable()) {
                throw ValidationException::withMessages(['section' => 'This component has no supported settings form. Its data is retained.']);
            }
            $result = $this->withEditingGuard(function () use ($node, $definition): MutationResult {
                // Dehydration may run host upload/relationship hooks. Check access before those hooks.
                $dehydrated = $this->sectionForm->getState();
                $patched = app(NodeFormState::class)->patch($node, $definition, $this->locale, $dehydrated, $this->formBaseline, $this->formData, $this->repeaterClones);
                $document = $this->document();
                $next = $this->replaceTreeNode($document, $patched);

                return app(PageCommands::class)->saveDraft($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $next, $this->draftHistoryContext(), $this->contentHistoryAction());
            });
            $this->accept($result);
            $this->formBaseline = $this->formData;
            $this->repeaterClones = [];
            $this->resetErrorBag();
        }
        if ($withDesign) {
            $this->flushDesign();
        }
    }

    private function flushSettings(): void
    {
        if ($this->same($this->settingsBaseline, $this->settingsData)) {
            if ($this->getErrorBag()->has('settingsData.slug')) {
                $this->resetValidation('settingsData.slug');
                $this->message = '';
            }

            return;
        }
        $this->editable();
        $data = $this->settingsForm->getState();
        $page = $this->page();
        $metadata = [];
        foreach (['title', 'type', 'layout'] as $key) {
            if (! $this->same($this->settingsBaseline[$key] ?? null, $data[$key] ?? null)) {
                $metadata[$key] = $key === 'layout' ? ($data[$key] ?: null) : $data[$key];
            }
        }
        $seoChanged = [];
        foreach (['meta_title', 'meta_description', 'canonical', 'og_image', 'noindex', 'nofollow'] as $key) {
            if (! $this->same($this->settingsBaseline[$key] ?? null, $data[$key] ?? null)) {
                $seoChanged[$key] = $data[$key] ?? null;
            }
        }
        if ($seoChanged !== []) {
            $seo = json_decode(json_encode($page->seo ?? new stdClass, JSON_THROW_ON_ERROR));
            if ($seo === []) {
                $seo = new stdClass;
            }
            if (! $seo instanceof stdClass) {
                throw ValidationException::withMessages(['seo' => 'The stored SEO format needs a migration before these fields can be changed.']);
            }
            $seo->{$this->locale} ??= new stdClass;
            foreach ($seoChanged as $key => $value) {
                $seo->{$this->locale}->{$key} = $value;
            }
            $metadata['seo'] = $seo;
        }
        foreach (['schema_json' => 'schema', 'data_json' => 'data'] as $field => $column) {
            if (! $this->same($this->settingsBaseline[$field] ?? null, $data[$field] ?? null)) {
                try {
                    $metadata[$column] = json_decode($data[$field] ?: 'null', flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw ValidationException::withMessages(['settingsData.'.$field => 'Enter valid JSON.']);
                }
            }
        }
        $slug = $data['slug'] ?? null;
        $slugs = $this->same($this->settingsBaseline['slug'] ?? null, $slug) ? null : [$this->locale => ($slug === '' ? null : $slug)];
        try {
            $this->accept(app(PageCommands::class)->saveMetadata($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $metadata, $slugs, $this->draftHistoryContext(), new LogicalAction('metadata', 'Change page settings')));
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['slugs'])) {
                $errors['settingsData.slug'] = $errors['slugs'];
                unset($errors['slugs']);
            }
            throw ValidationException::withMessages($errors);
        }
        $this->settingsBaseline = $this->settingsData;
        $this->message = 'Page settings saved. These shared settings take effect immediately.';
        $this->fillDesign();
    }

    private function saveDocument(Document $document, string $label = 'Edit section'): void
    {
        $this->accept(app(PageCommands::class)->saveDraft($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $document, $this->draftHistoryContext(), new LogicalAction('structure', $label)));
    }

    private function accept(MutationResult $result, bool $historyBoundary = false): void
    {
        $this->documentJson = app(DocumentCodec::class)->encode($result->page->draft_content ?? new Document);
        $this->draftDigest = $result->draftDigest;
        $this->writeVersion = $result->page->write_version;
        $this->pageTitle = $result->page->title;
        $this->publicationStatus = $result->page->status;
        $this->message = '';
        if ($historyBoundary) {
            $this->openDraftHistory(true);
        } else {
            $this->refreshDraftHistory();
        }
        $this->refreshPreview();
    }

    private function loadPage(Page $page): void
    {
        $this->pageTitle = $page->title;
        $this->publicationStatus = $page->status;
        $this->writeVersion = $page->write_version;
        try {
            $document = $page->draft_content;
            $this->documentJson = app(DocumentCodec::class)->encode($document ?? new Document);
            $this->draftDigest = app(CanonicalJson::class)->digest($document);
            $this->selectedId = $document?->nodes[0]->id ?? null;
        } catch (InvalidDocument $exception) {
            $this->documentJson = '';
            $this->rawDocument = $page->getRawOriginal('draft_content') ?? 'null';
            $this->readOnly = true;
            $this->state = 'unsupported';
            $this->message = $exception->getMessage().' The original document is retained.';
        }
        $this->fillSettings($page);
    }

    private function fillSection(): void
    {
        $this->clearSnapshotReview();
        $this->clearMediaBatch();
        $this->snapshotChoices = [];
        $this->repeaterClones = [];
        $node = $this->selectedNode();
        $definition = $node === null ? null : app(ComponentRegistry::class)->resolve($node->type);
        $this->cacheSchema('sectionForm', $this->sectionForm(Schema::make($this)));
        $values = $node !== null && $definition !== null ? app(NodeFormState::class)->project($node, $definition, $this->locale) : [];
        $this->sectionForm->fill($values);
        $this->formBaseline = $this->formData;
        $this->resetErrorBag();
        $this->fillDesign();
    }

    private function fillSettings(Page $page): void
    {
        $this->cacheSchema('settingsForm', $this->settingsForm(Schema::make($this)));
        $this->settingsForm->fill(['title' => $page->title, 'type' => $page->type, 'layout' => $page->layout,
            'slug' => $page->slugs->firstWhere('locale', $this->locale)?->slug,
            'meta_title' => data_get($page->seo, $this->locale.'.meta_title'), 'meta_description' => data_get($page->seo, $this->locale.'.meta_description'),
            'canonical' => data_get($page->seo, $this->locale.'.canonical'), 'og_image' => data_get($page->seo, $this->locale.'.og_image'),
            'noindex' => (bool) data_get($page->seo, $this->locale.'.noindex', false), 'nofollow' => (bool) data_get($page->seo, $this->locale.'.nofollow', false),
            'schema_json' => json_encode($page->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_json' => json_encode($page->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->settingsBaseline = $this->settingsData;
    }

    private function refreshPreview(): void
    {
        if ($this->documentJson !== '' && Gate::forUser($this->actor())->allows('preview', $this->page())) {
            $this->channel = (string) Str::uuid();
            $this->previewExpiresAt = time() + max(1, (int) config('site-builder.preview.expiry_minutes', 1440)) * 60;
            $this->previewUrl = app(PreviewLinks::class)->create($this->actor(), $this->pageId, $this->locale, $this->tabId, $this->channel);
        } else {
            $this->previewUrl = '';
        }
    }

    private function document(): Document
    {
        return app(DocumentCodec::class)->decode($this->documentJson);
    }

    private function selectedNode(): ?Node
    {
        if ($this->documentJson === '') {
            return null;
        }

        return app(TreeResolver::class)->nodes($this->document())[$this->selectedId]?->node ?? null;
    }

    private function page(): Page
    {
        $page = Page::query()->findOrFail($this->pageId);
        Gate::forUser($this->actor())->authorize('view', $page);

        return $page;
    }

    private function actor(): Authenticatable
    {
        return Filament::auth()->user() ?? abort(403);
    }

    private function editable(): void
    {
        if ($this->readOnly || $this->documentJson === '') {
            throw new WriteConflict('editor_read_only', $this->writeVersion);
        }
    }

    private function withEditingGuard(Closure $action): mixed
    {
        $this->editable();

        // Do not retry host hooks: a filesystem or external side effect may not be transactional.
        return DB::transaction(function () use ($action): mixed {
            $page = Page::query()->lockForUpdate()->findOrFail($this->pageId);
            $userId = app(PageAccess::class)->authorize($this->actor(), 'update', $page);
            app(PageLocks::class)->assertHeld($page, $userId, $this->leaseToken);
            if ($page->write_version !== $this->writeVersion) {
                throw new WriteConflict('stale_version', $page->write_version);
            }

            $this->refreshDraftHistory();

            return $action();
        });
    }

    #[Exposed]
    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        return $this->formAction(fn (): mixed => parent::mountAction($name, $arguments, $context));
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        return $this->formAction(fn (): mixed => parent::callMountedAction($arguments));
    }

    /** Called only by a trusted native form action after it assigns the clone's UUID. */
    #[Unexposed]
    public function rememberRepeaterClone(string $property, string $sourceKey, string $newKey): void
    {
        $this->withEditingGuard(function () use ($property, $sourceKey, $newKey): void {
            if (isset($this->formBaseline[$property][$sourceKey], $this->formData[$property][$newKey])) {
                $this->repeaterClones[$property][$newKey] = $sourceKey;
            }
        });
    }

    private function formAction(Closure $action): mixed
    {
        $this->page();
        try {
            return $this->withEditingGuard($action);
        } catch (WriteConflict $exception) {
            $this->conflict($exception);

            return null;
        }
    }

    private function same(mixed $left, mixed $right): bool
    {
        return app(NodeFormState::class)->sameRaw($left, $right);
    }

    private function conflict(WriteConflict $exception): void
    {
        $this->readOnly = true;
        $this->state = 'conflict';
        $this->message = match ($exception->reason) {
            'lease_held' => 'Another tab or editor is editing this page. You can view it until they finish or their editing access expires.',
            'history_changed', 'history_page_changed', 'stale_version', 'draft_digest_changed' => 'The saved page changed. Your unsaved input is retained. Download it before reloading.',
            'history_unavailable' => 'Document undo expired or is unavailable. Your input is retained. Download it before reloading to start a fresh editing history.',
            'stale_saved_section_source', 'source_version_or_digest_changed', 'stale_saved_section_version', 'saved_section_archived' => 'The saved starting point changed. Your page input and library edits are retained. Reload the saved page to reconcile, then review the current library entry before continuing.',
            default => 'Editing access expired or was lost. Your unsaved input is retained. Download it before requesting editing access again.',
        };
        if ($exception->reason === 'lease_held') {
            $lock = Page::find($this->pageId)?->editLock;
            $owner = $lock?->user_id;
            $name = null;
            if ($owner !== null && app()->bound(EditorIdentityName::class)) {
                $name = app(EditorIdentityName::class)->forActor((string) $owner, $lock->user_type);
            } elseif ($owner !== null && app()->bound(EditorName::class) && ctype_digit((string) $owner) && (string) (int) $owner === (string) $owner && $lock->user_type === $this->actor()::class) {
                $name = app(EditorName::class)->forUser((int) $owner);
            }
            if (is_string($name) && $name !== '') {
                $this->message .= ' Current editor: '.$name.'.';
            }
        }
    }

    protected function afterActionCalled(Action $action): void
    {
        // Repeater/upload/modal actions do not always invoke updatedFormData().
        $this->saveSelected();
    }

    protected function getViewData(): array
    {
        $page = $this->page();
        $registry = app(ComponentRegistry::class);
        $forms = app(ComponentFormRegistry::class);
        $selected = $this->selectedNode();
        $diagnostics = $this->documentJson === '' ? [] : app(DocumentValidator::class)->diagnostics($this->document());

        return [...$this->sitePartsViewData(), ...$this->clipboardViewData(), ...$this->compositionViewData(), ...$this->designViewData(), ...$this->treeViewData(), 'componentForms' => $forms->all(), 'locales' => config('site-builder.locales'),
            'editorAssetVersion' => substr(hash_file('sha256', __DIR__.'/../../../resources/dist/site-builder.js'), 0, 16),
            'hasForm' => $this->selectedFormEditable(),
            'diagnostics' => $diagnostics, 'canPublish' => Gate::forUser($this->actor())->allows('publish', $page),
            'canDelete' => Gate::forUser($this->actor())->allows('delete', $page),
            'canRestore' => Gate::forUser($this->actor())->allows('restore', $page),
            'revisions' => app(PageHistory::class)->revisions($this->actor(), $this->pageId, 20, $this->historyBefore),
            'publicUrl' => $page->status === 'published' ? app(PageUrlResolver::class)->url($page, $this->locale) : null,
            'releaseUrl' => Filament::getCurrentPanel()->route('site-builder.release', ['page' => $this->pageId]),
            'indexUrl' => PageResource::getUrl('index')];
    }
}
