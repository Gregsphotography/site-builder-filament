<div class="sb-picker-overlay" x-show="$wire.clipboardOpen && !clipboardDismissed" x-cloak @keydown.escape.prevent.stop="dismissClipboard()" @keydown.tab="trapClipboard($event)">
    <section class="sb-picker sb-clipboard" role="dialog" aria-modal="true" aria-labelledby="sb-clipboard-title" x-ref="clipboardDialog">
        <header class="sb-picker-header">
            <div><h2 id="sb-clipboard-title">{{ $this->clipboardMode === 'copy' ? 'Copy selected section' : 'Paste a structured section' }}</h2><p>Independent copy · {{ strtoupper($this->locale) }} · current installation</p></div>
            <button type="button" @click="dismissClipboard()" aria-label="Close structured clipboard">Close</button>
        </header>
        <div class="sb-picker-error" x-show="error || queue.blocked || $wire.state === 'invalid'" x-cloak>
            <p role="alert" x-text="error || $wire.message"></p>
            <button type="button" @click="download()">Download retained input</button>
            <button type="button" @click="dismissClipboard()">Close to review recovery</button>
        </div>
        <div class="sb-clipboard-body">
            @if($this->clipboardMode === 'copy')
                <p>This copy includes the selected section, descendants, all languages and section design. Paste it into another authorized page before {{ $this->copiedSectionSummary['expiresAt'] ?? 'its short expiry' }}. The target keeps its page defaults.</p>
                <p>{{ $this->copiedSectionSummary['nodeCount'] ?? 0 }} node(s) · accepted source version {{ $this->copiedSectionSummary['sourceVersion'] ?? '' }}. Copying does not change either page.</p>
                <label for="sb-clipboard-copy-text">Structured section text</label>
                <textarea id="sb-clipboard-copy-text" name="structured-section-copy" data-clipboard-copy readonly rows="10" :value="$wire.copiedSectionText" @focus="$event.target.select()"></textarea>
                <div class="sb-clipboard-actions"><button type="button" @click="copyPreparedSection()">Copy text to clipboard</button><button type="button" @click="downloadClipboard()">Download structured copy</button><button type="button" @click="$refs.clipboardDialog.querySelector('[data-clipboard-copy]').focus()">Select text for manual copy</button></div>
                <p class="sb-help">Automatic clipboard access may be unavailable on HTTP sites or denied by your browser. Select and copy the visible text, or download it and paste the file’s text into the destination page.</p>
            @else
                <p>Paste a structured section copied from an authorized page on this installation. The retained source and every declared reference are checked again. Plain HTML and arbitrary JSON are not section imports. Page reload retains this text for recovery and clears its review; Clear pasted content discards it. Nothing is saved in browser storage.</p>
                <label for="sb-clipboard-paste-text">Structured section text to paste</label>
                <textarea id="sb-clipboard-paste-text" name="structured-section-paste" data-clipboard-paste wire:model="clipboardText" maxlength="1048576" rows="8" spellcheck="false"></textarea>
                <div class="sb-clipboard-actions"><button type="button" @click="readClipboardText()">Read browser clipboard</button><button type="button" @click="clearPastedContent()" :disabled="busy || !$wire.clipboardText">Clear pasted content</button><button type="button" @click="run('reviewClipboard', false)" :disabled="busy || queue.blocked || $wire.readOnly || !$wire.clipboardText">Check structured copy</button></div>
                <div class="sb-clipboard-placement"><div><label for="sb-clipboard-parent">Destination parent</label><select id="sb-clipboard-parent" name="clipboard-parent" wire:model.live="clipboardParentId"><option value="">Page root</option>@foreach($treeContainers as $container)<option value="{{ $container['id'] }}">{{ $container['label'] }}</option>@endforeach</select></div>
                    <div><label for="sb-clipboard-placement">Insert copied section</label><select id="sb-clipboard-placement" name="clipboard-placement" wire:model.live="clipboardPlacement"><option value="start">At the beginning</option><option value="end">At the end</option><option value="before">Before a section</option><option value="after">After a section</option></select></div>
                    <div x-show="['before','after'].includes($wire.clipboardPlacement)"><label for="sb-clipboard-anchor">Anchor section</label><select id="sb-clipboard-anchor" name="clipboard-anchor" wire:model="clipboardAnchor"><option value="">Choose a section</option>@foreach($sections as $index => $section)<option value="{{ $section['id'] }}" :disabled="($wire.clipboardParentId || null) !== @js($section['parentId'])">{{ $section['ordinal'] }}. {{ $section['label'] }}</option>@endforeach</select></div>
                </div>
                @if($this->clipboardReview)
                    <section class="sb-picker-references" aria-labelledby="sb-clipboard-review-title" x-show="$wire.clipboardText === clipboardCheckedText">
                        <h3 id="sb-clipboard-review-title" tabindex="-1" data-clipboard-review>Review structured copy</h3>
                        <p>{{ $this->clipboardReview['nodeCount'] ?? 0 }} node(s) from page {{ $this->clipboardReview['sourcePageId'] ?? '' }}, accepted source version {{ $this->clipboardReview['sourceVersion'] ?? '' }}. Destination draft version {{ $this->clipboardReview['destinationVersion'] }}.</p>
                        <p class="sb-help">Each descendant receives a new identity. Page defaults remain those of this destination; section-owned design and every language are copied. Review the actual destination preview after insertion and before publishing.</p>
                        @forelse($this->clipboardReferences['occurrences'] ?? [] as $reference)
                            <div wire:key="clipboard-ref-{{ $reference['key'] }}"><label for="sb-clipboard-ref-{{ $reference['key'] }}">{{ ucfirst($reference['kind']) }} · {{ $reference['path'] }}</label>
                                <input id="sb-clipboard-ref-{{ $reference['key'] }}" type="text" wire:model="clipboardReferenceValues.{{ $reference['key'] }}.value" @if($reference['kind'] === 'page') inputmode="numeric" @endif>
                                <p>{{ $reference['status'] }}{{ $reference['message'] ? ' · '.$reference['message'] : '' }}</p>
                                @if($reference['status'] === 'unverified')<label><input type="checkbox" wire:model="clipboardReferenceValues.{{ $reference['key'] }}.keepUnverified"> Keep this safe host reference; availability cannot be verified here</label>@endif
                            </div>
                        @empty<p>No declared page or media references.</p>@endforelse
                        @foreach($this->clipboardReferences['diagnostics'] ?? [] as $diagnostic)<p>{{ is_string($diagnostic) ? $diagnostic : data_get($diagnostic, 'message', 'A copy diagnostic requires review.') }}</p>@endforeach
                        @if($this->clipboardReferences['occurrences'] ?? [])<button type="button" @click="run('reviewClipboard', true)" :disabled="busy || queue.blocked || $wire.readOnly">Check copied reference choices</button>@endif
                        @if($this->clipboardReferences['blocking'] ?? false)<p role="alert">Resolve the reported references before inserting.</p>@endif
                        <p x-show="!clipboardChoicesReviewed()" role="status">The text, placement or reference choices changed. Check them again before inserting.</p>
                    </section>
                @endif
                <p x-show="$wire.clipboardReview.textDigest && $wire.clipboardText !== clipboardCheckedText">The structured text changed. Check it again to review the current source.</p>
            @endif
            <p class="sb-help" role="status" aria-live="polite" x-text="clipboardMessage"></p>
        </div>
        <footer class="sb-picker-footer"><button type="button" @click="dismissClipboard()">Close</button>
            @if($this->clipboardMode === 'paste' && $this->clipboardReview)<button type="button" class="sb-primary" @click="run('pasteClipboard')" :disabled="busy || queue.blocked || $wire.readOnly || !clipboardChoicesReviewed() || ($wire.clipboardReferences.blocking ?? true)">Insert reviewed copy</button>@endif
        </footer>
    </section>
</div>
