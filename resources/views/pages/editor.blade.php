<div
    class="sb-editor"
    data-editor-identity="{{ $this->pageId }}-{{ $this->tabId }}"
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('siteBuilderEditor', package: 'gregsphotography/site-builder-filament') }}&amp;content={{ $editorAssetVersion }}"
    x-data="siteBuilderEditor(@js(['pageId' => $this->pageId, 'session' => $this->tabId, 'releaseUrl' => $releaseUrl, 'csrf' => csrf_token(), 'heartbeat' => max(5, (int) config('site-builder.locks.heartbeat_seconds', 30))]))"
    @focusout="typingBoundary = true"
    wire:key="editor-{{ $this->pageId }}-{{ $this->tabId }}"
>
    <p class="sb-screen-reader" role="status" aria-live="polite" x-text="announcement"></p>
    <header class="sb-topbar" :inert="($wire.pickerOpen && !pickerDismissed) || ($wire.clipboardOpen && !clipboardDismissed)">
        <a class="sb-back" href="{{ $indexUrl }}">← Pages</a>
        <div class="sb-heading"><h1>{{ $this->pageTitle }}</h1><span>{{ ucfirst($this->publicationStatus) }}</span></div>
        <label class="sb-language">Language
            <select aria-label="Content language" :value="$wire.locale" @change="run('changeLocale', $event.target.value)" :disabled="busy || queue.blocked">
                @foreach ($locales as $code => $configuration)<option value="{{ $code }}">{{ $configuration['label'] ?? $code }}</option>@endforeach
            </select>
        </label>
        <span class="sb-save-state" role="status" aria-live="polite" x-text="busy ? 'Saving…' : (queue.blocked ? 'Save unconfirmed' : (dirty ? 'Unsaved changes' : (pendingThemeChoice() ? 'Theme review pending' : ($wire.readOnly ? 'Read only' : 'Draft saved'))))"></span>
        <div class="sb-draft-history" role="group" aria-label="Document undo and redo">
            <button type="button" @click="run('undoDraft')" :disabled="busy || $wire.readOnly || queue.blocked || !historyState.canUndo" :aria-label="'Undo ' + (historyState.undoLabel || 'document change')">Undo<span x-show="historyState.undoCount" x-text="' (' + historyState.undoCount + ')' "></span></button>
            <button type="button" @click="run('redoDraft')" :disabled="busy || $wire.readOnly || queue.blocked || !historyState.canRedo" :aria-label="'Redo ' + (historyState.redoLabel || 'document change')">Redo<span x-show="historyState.redoCount" x-text="' (' + historyState.redoCount + ')' "></span></button>
        </div>
        <button type="button" @click="run('saveSelected')" :disabled="busy || $wire.readOnly || queue.blocked">Save draft</button>
        @if ($canPublish)<button class="sb-primary" type="button" @click="run('publish')" :disabled="busy || $wire.readOnly || queue.blocked">Publish</button>@endif
        @if ($publicUrl)<a class="sb-public" href="{{ $publicUrl }}" target="_blank" rel="noopener">View live ↗</a>@endif
    </header>
    <div class="sb-status" role="status" aria-live="polite" :inert="($wire.pickerOpen && !pickerDismissed) || ($wire.clipboardOpen && !clipboardDismissed)" x-show="$wire.message && !error && !$wire.readOnly && !queue.blocked && $wire.state === 'saved' && !dirty && !pendingThemeChoice()" x-cloak x-text="$wire.message"></div>
    <div class="sb-notice" :inert="($wire.pickerOpen && !pickerDismissed) || ($wire.clipboardOpen && !clipboardDismissed)" x-show="error || $wire.readOnly || queue.blocked || ['invalid', 'failed', 'conflict'].includes($wire.state)" x-cloak>
        <p role="alert" x-text="error || $wire.message || 'This page is read only.'">{{ $message }}</p>
        <button type="button" @click="download()">Download unsaved changes</button>
        <button type="button" @click="run('reloadSaved')" :disabled="busy">Reload saved content / request editing access</button>
        <div class="sb-reload-confirm" role="group" aria-label="Confirm saved-content reload" x-show="confirmReload" x-cloak @keydown.escape="confirmReload = false">
            <p>Reloading resets unsaved page fields and document undo. Pasted structured content is retained for recovery and needs a fresh review. Download your page changes first if you need to keep them.</p>
            <button type="button" @click="confirmReload = false">Keep unsaved input</button>
            <button type="button" x-ref="reloadConfirm" @click="run('reloadSaved')">Reset page edits and reload</button>
        </div>
    </div>
    <div class="sb-workspace" :inert="($wire.pickerOpen && !pickerDismissed) || ($wire.clipboardOpen && !clipboardDismissed)">
        <aside class="sb-sidebar" aria-label="Page editing controls">
            <nav class="sb-tabs" aria-label="Editor panels">
                <button type="button" @click="panel = 'sections'" :aria-pressed="panel === 'sections'">Sections</button>
                <button type="button" @click="panel = 'design'" :aria-pressed="panel === 'design'">Design</button>
                <button type="button" @click="panel = 'settings'" :aria-pressed="panel === 'settings'">Page settings</button>
                <button type="button" @click="panel = 'site-parts'" :aria-pressed="panel === 'site-parts'">Site parts</button>
                <button type="button" @click="panel = 'history'" :aria-pressed="panel === 'history'">History</button>
            </nav>
            <div class="sb-panel" x-show="panel === 'sections'">
                <h2>Page sections</h2>
                <p class="sb-help" x-show="!historyState.available" x-cloak>{{ $this->draftHistoryUnavailableMessage ?: 'Document undo is unavailable until its optional history store is installed.' }}</p>
                <p class="sb-help" x-show="historyState.evicted" x-cloak x-text="'The oldest ' + historyState.evicted + ' undo step(s) exceeded this tab’s bounded history and are no longer available.'"></p>
                <p class="sb-help">Select a section here or in the preview.</p>
                <p class="sb-help">The tree follows reading order. Use the structure buttons, or Alt + arrow keys on a selected section: up/down among siblings, right into the preceding compatible container, left after its parent. Select a section to reveal its structure controls. Drag it onto another tree row to move before that row; hold Shift while dropping on a compatible container to move inside.</p>
                <ol class="sb-sections sb-tree" aria-label="Page section tree">
                    @if($sectionTree)@include('site-builder-filament::pages.tree', ['nodes' => $sectionTree])@else<li class="sb-empty">Add your first section below.</li>@endif
                </ol>
                <div class="sb-add">
                    <button type="button" class="sb-primary" data-empty-add @click="openPicker('end')" :disabled="busy || $wire.readOnly || queue.blocked">Add section or template</button>
                    @if($clipboardAvailable)
                        <button type="button" @click="openClipboard('copy')" :disabled="busy || $wire.readOnly || queue.blocked || !$wire.selectedId">Copy selected section</button>
                        <button type="button" @click="openClipboard('paste')" :disabled="busy || $wire.readOnly || queue.blocked">Paste structured section</button>
                    @else<p class="sb-help">Cross-page copy is unavailable until the optional clipboard store is installed.</p>@endif
                    @if ($canCaptureSection)
                        <button type="button" @click="showCapture = !showCapture" :aria-expanded="showCapture" :disabled="busy || $wire.readOnly || queue.blocked">Save selected section</button>
                    @endif
                </div>
                @if ($canCaptureSection)
                    <form class="sb-capture" x-show="showCapture" x-cloak @submit.prevent="run('captureSavedSection')">
                        <h2>Save a reusable copy</h2>
                        <p class="sb-help">Captures this section, its descendants and every language. Existing copies remain independent.</p>
                        <label>Name <input type="text" wire:model="captureData.name" maxlength="255" required></label>
                        <label>Description <textarea wire:model="captureData.description" rows="2"></textarea></label>
                        <label>Category <input type="text" wire:model="captureData.category" maxlength="100"></label>
                        <div><label for="sb-capture-visibility">Visibility</label><select id="sb-capture-visibility" name="capture-visibility" wire:model="captureData.visibility"><option value="private">Private</option><option value="shared">Shared (requires permission)</option></select></div>
                        <button type="submit" :disabled="busy || $wire.readOnly || queue.blocked">Save copy to library</button>
                    </form>
                @endif
                @if($this->selectedId && ($selectedBinding = array_find($sitePartBindings, fn($binding) => $binding['nodeId'] === $this->selectedId)))
                    <div class="sb-capture"><h2>Pinned published site part</h2><p>{{ $selectedBinding['name'] }} · revision {{ substr($selectedBinding['reference']['revisionId'],0,12) }}</p><p class="sb-help">Mounted descendants belong to the source resource. Edit its separate draft or explicitly review a different immutable pin.</p>@if($selectedBinding['editUrl'])<a href="{{ $selectedBinding['editUrl'] }}">Open separate site-part editor</a>@endif<button type="button" @click="panel='site-parts'">Review this page’s site-part pins</button></div>
                @endif
                <div class="sb-fields" wire:key="fields-{{ $this->selectedId }}-{{ $this->locale }}">
                    <h2>Section settings · {{ strtoupper($this->locale) }}</h2>
                    @if ($hasForm)
                        <p class="sb-help">Only this language is edited. The preview may fall back to the default language; fallback values are never copied into the form.</p>
                        <form @submit.prevent="run('saveSelected')"><fieldset :disabled="$wire.readOnly || queue.blocked">{{ $this->sectionForm }}</fieldset></form>
                    @include('site-builder-filament::pages.snapshots')
                @include('site-builder-filament::pages.gallery-media')
                    @else
                        <p class="sb-help">{{ $this->selectedId ? 'Settings are unavailable for this component. Its stored data is retained.' : 'Select a section to edit its content.' }}</p>
                    @endif
                </div>
                @if ($diagnostics)
                    <details class="sb-diagnostics"><summary>{{ count($diagnostics) }} content diagnostic(s)</summary><ul>@foreach ($diagnostics as $diagnostic)<li>{{ $diagnostic->message }} <small>{{ $diagnostic->path }}</small></li>@endforeach</ul></details>
                @endif
            </div>
            @include('site-builder-filament::pages.design')
            @include('site-builder-filament::pages.site-parts')
            <div class="sb-panel" x-show="panel === 'settings'" x-cloak>
                <h2>Page settings</h2>
                <p class="sb-help">Saving title, URLs, layout and search settings changes the live page immediately. Section drafts remain private until published.</p>
                <form @submit.prevent="run('saveSettings')"><fieldset :disabled="$wire.readOnly || queue.blocked">{{ $this->settingsForm }}</fieldset><button class="sb-primary" type="submit" :disabled="busy || $wire.readOnly || queue.blocked">Save page settings</button></form>
                <h3>Content discovery · {{ strtoupper($this->locale) }}</h3><p class="sb-help">These fields stay in the draft until you publish the page.</p>
                <form @submit.prevent="run('saveDiscoverySettings')"><fieldset :disabled="$wire.readOnly || queue.blocked">{{ $this->discoveryForm }}</fieldset><button class="sb-primary" type="submit" :disabled="busy || $wire.readOnly || queue.blocked">Save discovery draft</button></form>
                @if ($canPublish && $this->publicationStatus === 'published')<button type="button" @click="run('unpublish')" :disabled="busy || $wire.readOnly || queue.blocked">Unpublish page</button>@endif
                @if ($canDelete)
                    <details class="sb-delete"><summary>Delete this page</summary><p>Review links and references to this page first. Deletion removes the page, URLs and content history.</p>
                        <label><input type="checkbox" wire:model="deleteConfirmed"> I have reviewed references and want to delete this page.</label>
                        <button type="button" @click="run('deletePage')" :disabled="busy || $wire.readOnly || queue.blocked || !$wire.deleteConfirmed">Delete page</button>
                    </details>
                @endif
            </div>
            <div class="sb-panel" x-show="panel === 'history'" x-cloak>
                <h2>Published history</h2><p class="sb-help">Restore sections into the draft. The live page and current page settings stay unchanged.</p>
                <ol class="sb-history">@forelse ($revisions as $revision)<li><strong>{{ $revision->label ?: 'Published revision #'.$revision->id }}</strong><span>{{ $revision->created_at?->format('Y-m-d H:i') ?? 'Historical time unavailable' }}</span>
                    @if ($canRestore)<button type="button" @click="run('restoreRevision', {{ $revision->id }})" :disabled="busy || $wire.readOnly || queue.blocked">Restore to draft</button>@endif
                </li>@empty<li>No published revisions yet.</li>@endforelse</ol>
                @if(count($revisions) === 20)<button type="button" @click="run('historyPage', {{ $revisions->last()->id }})" :disabled="busy || queue.blocked">Older revisions</button>@endif
                @if($this->historyBefore)<button type="button" @click="run('historyPage', null)" :disabled="busy || queue.blocked">Most recent revisions</button>@endif
            </div>
        </aside>
        <section class="sb-preview" aria-label="Draft preview">
            <div class="sb-preview-toolbar"><h2>Draft preview</h2><div aria-label="Preview size">
                <button type="button" @click="viewport = 'desktop'" :aria-pressed="viewport === 'desktop'">Desktop</button>
                <button type="button" @click="viewport = 'tablet'" :aria-pressed="viewport === 'tablet'">Tablet</button>
                <button type="button" @click="viewport = 'mobile'" :aria-pressed="viewport === 'mobile'">Mobile</button>
            </div></div>
            <div class="sb-preview-canvas">
                @if ($this->previewUrl)
                    <iframe x-ref="preview" :src="$wire.previewUrl" @load="highlight()" title="Private draft preview" :style="{ width: viewport === 'desktop' ? '100%' : (viewport === 'tablet' ? '{{ (int) config('site-builder.preview.widths.tablet', 820) }}px' : '{{ (int) config('site-builder.preview.widths.mobile', 390) }}px') }" referrerpolicy="no-referrer" wire:ignore></iframe>
                @else<p class="sb-empty">A preview is unavailable for this page or your permissions.</p>@endif
            </div>
        </section>
    </div>
    @include('site-builder-filament::pages.picker')
    @include('site-builder-filament::pages.clipboard')
    <x-filament-actions::modals />
</div>
