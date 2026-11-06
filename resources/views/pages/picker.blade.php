<div class="sb-picker-overlay" x-show="$wire.pickerOpen && !pickerDismissed" x-cloak @keydown.escape.prevent.stop="dismissPicker()" @keydown.tab="trapPicker($event)">
    <section class="sb-picker" role="dialog" aria-modal="true" aria-labelledby="sb-picker-title" x-ref="picker">
        <header class="sb-picker-header">
            <div><h2 id="sb-picker-title">Choose a starting point</h2><p>{{ $pickerPlacementLabel }} · {{ strtoupper($this->locale) }} · {{ $pickerLayoutLabel }} layout</p></div>
            <button type="button" @click="dismissPicker()" aria-label="Close starting point picker">Close</button>
        </header>
        <div class="sb-picker-error" x-show="error || queue.blocked || $wire.state === 'invalid'" x-cloak>
            <p role="alert" x-text="error || $wire.message"></p>
            <button type="button" @click="download()">Download retained input</button>
            <button type="button" @click="dismissPicker()">Close to review recovery</button>
        </div>
        <div class="sb-picker-body">
            <div class="sb-picker-catalog">
                <label class="sb-picker-search" x-show="pickerKind !== 'saved-section'">Search sections and templates
                    <input type="search" data-picker-search x-ref="pickerSearch" x-model="pickerSearch" placeholder="Search by name or purpose">
                </label>
                <div class="sb-picker-kinds" role="group" aria-label="Starting point source">
                    <button type="button" @click="pickerKind = 'component'" :aria-pressed="pickerKind === 'component'">Sections</button>
                    <button type="button" @click="pickerKind = 'code-template'" :aria-pressed="pickerKind === 'code-template'">Page templates</button>
                    <button type="button" @click="pickerKind = 'saved-section'" :aria-pressed="pickerKind === 'saved-section'">Saved sections</button>
                </div>
                <div x-show="pickerKind !== 'saved-section'"><label for="sb-picker-category">Category</label>
                    <select id="sb-picker-category" name="picker-category" x-model="pickerCategory"><option value="">All categories</option>@foreach ($pickerCategories as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select>
                </div>
                @if($savedSectionsAvailable)
                    <form x-show="pickerKind === 'saved-section'" @submit.prevent="run('searchSavedSections')">
                        <label>Search saved sections <input type="search" data-picker-saved-search x-ref="pickerSavedSearch" wire:model="pickerSavedSearch" maxlength="255"></label>
                        <label>Saved category <input type="text" wire:model="pickerSavedCategory" maxlength="255" placeholder="Any category"></label>
                        <button type="submit" :disabled="busy || queue.blocked || $wire.readOnly">Search library</button>
                    </form>
                @endif
                <p role="status" x-text="$wire.pickerOpen ? pickerCount() + ' shown starting point(s)' : ''"></p>
                @unless($savedSectionsAvailable)<p class="sb-help" x-show="pickerKind === 'saved-section'">The optional saved-section store is not installed. Sections and code templates remain available.</p>@endunless
                <div class="sb-picker-results" x-ref="pickerList" role="group" aria-label="Starting point results">
                    @foreach ($pickerEntries as $entry)
                        <button type="button" data-picker-entry data-kind="{{ $entry['kind'] }}" data-category="{{ $entry['category'] }}" data-search="{{ mb_strtolower(implode(' ', [$entry['label'], $entry['description'], $entry['category'], ...$entry['keywords']])) }}"
                            wire:key="picker-entry-{{ $entry['key'] }}-{{ $entry['version'] }}" x-show="pickerMatches($el.dataset)"
                            @click="run('reviewStartingPoint', @js($entry['key']), null)" :disabled="busy || queue.blocked || $wire.readOnly || @js(!$entry['insertable'])"
                            aria-pressed="{{ ($this->pickerReview['key'] ?? null) === $entry['key'] ? 'true' : 'false' }}">
                            <span aria-hidden="true" class="sb-picker-symbol">▦</span><strong>{{ $entry['label'] }}</strong>
                            <span>{{ $entry['category'] }}{{ $entry['legacy'] ? ' · Legacy' : '' }}</span><small>{{ $entry['description'] }}</small>
                            @unless($entry['previewAvailable'])<small>Preview unavailable for this component</small>@endunless
                            @if($entry['variantDiagnostic'])<small>{{ $entry['variantDiagnostic'] }}</small>@endif
                        </button>
                    @endforeach
                </div>
                @if($savedSectionsAvailable)<div class="sb-picker-pagination" x-show="pickerKind === 'saved-section'" role="group" aria-label="Saved section result pages">
                    <p class="sb-help">Search covers the saved library. Results are shown in authorized pages; your reviewed choice stays selected.</p>
                    <button type="button" @click="run('savedSectionsPage', false)" :disabled="busy || queue.blocked || ! $wire.pickerSavedPrevious.length">Previous saved results</button>
                    <button type="button" @click="run('savedSectionsPage', true)" :disabled="busy || queue.blocked || ! $wire.pickerSavedNextCursor">Next saved results</button>
                </div>@endif
                <p x-show="!pickerCount()">No matching entries. <button type="button" x-show="pickerKind !== 'saved-section'" @click="pickerSearch = ''; pickerCategory = ''">Reset search and category</button></p>
            </div>
            <div class="sb-picker-detail">
                @if ($this->pickerReview)
                    <h3 tabindex="-1" data-picker-detail x-ref="pickerDetail">{{ $this->pickerReview['label'] }}</h3>
                    <p>{{ $this->pickerReview['description'] }}</p>
                    @if($this->pickerReview['reviewed'] ?? false)<p class="sb-help">Version {{ $this->pickerReview['version'] }} · {{ $this->pickerReview['sectionCount'] }} section(s) · source {{ substr($this->pickerReview['digest'], 0, 12) }}</p>@endif
                    @if($this->pickerReview['variantDiagnostic'])<p>{{ $this->pickerReview['variantDiagnostic'] }}</p>@endif
                    @if ($this->pickerReview['variants'])
                        <div><label for="sb-picker-variant">Variant</label>
                            <select id="sb-picker-variant" name="picker-variant" aria-label="Variant" :value="$wire.pickerReview.variant || ''" @change="run('reviewStartingPoint', @js($this->pickerReview['key']), $event.target.value)" :disabled="busy || queue.blocked || $wire.readOnly">
                                <option value="" @disabled(!$this->pickerReview['defaultVariantAvailable'])>Default variant{{ $this->pickerReview['defaultVariantAvailable'] ? '' : ' (unavailable in this theme)' }}</option>@foreach ($this->pickerReview['variants'] as $variant => $label)<option value="{{ $variant }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    @if ($this->pickerPreviewUrl)
                        <p class="sb-help" id="sb-picker-preview-hint">Read-only preview using this page’s design and language, with checked reference choices. Tab leaves the preview; Escape closes the picker.</p>
                        <iframe x-show="referenceChoicesReviewed()" class="sb-picker-preview" x-ref="pickerFrame" @load="preparePickerPreview()" src="{{ $this->pickerPreviewUrl }}" title="Read-only preview of {{ $this->pickerReview['label'] }}" aria-describedby="sb-picker-preview-hint" referrerpolicy="no-referrer"></iframe>
                        <p x-show="!referenceChoicesReviewed()">Reference choices changed. Check them below to refresh the preview.</p>
                    @else
                        <p class="sb-empty">{{ $this->pickerPreviewMessage ?: 'A preview is unavailable for this page or your permissions.' }}</p>
                    @endif
                    <section class="sb-picker-references" aria-label="Reference review">
                        <h3>Review page and media references</h3>
                        @forelse ($this->pickerReferences['occurrences'] ?? [] as $reference)
                            <div wire:key="picker-ref-{{ $reference['key'] }}">
                                <label>{{ ucfirst($reference['kind']) }} · {{ $reference['path'] }}
                                    <input type="text" wire:model="pickerReferenceValues.{{ $reference['key'] }}.value" @if($reference['kind'] === 'page') inputmode="numeric" @endif>
                                </label>
                                <p>{{ $reference['status'] }}{{ $reference['message'] ? ' · '.$reference['message'] : '' }}</p>
                                @if ($reference['status'] === 'unverified')<label><input type="checkbox" wire:model="pickerReferenceValues.{{ $reference['key'] }}.keepUnverified"> Keep this safe host reference; availability cannot be verified here</label>@endif
                            </div>
                        @empty<p>{{ ($this->pickerReview['reviewed'] ?? false) ? 'No declared page or media references.' : 'References will be checked after choosing a compatible variant.' }}</p>@endforelse
                        @foreach ($this->pickerReferences['diagnostics'] ?? [] as $diagnostic)<p>{{ is_string($diagnostic) ? $diagnostic : ($diagnostic['message'] ?? 'A source diagnostic requires review.') }}</p>@endforeach
                        @if ($this->pickerReferences['occurrences'] ?? [])<button type="button" @click="run('reviewStartingPointReferences')" :disabled="busy || queue.blocked || $wire.readOnly">Check reference choices</button>@endif
                        @if ($this->pickerReferences['blocking'] ?? false)<p role="alert">Resolve the reported references before applying this starting point.</p>@endif
                    </section>
                    @if ($this->savedSectionReview)
                        <details class="sb-picker-management"><summary>Manage this saved section</summary><p>Changes affect future copies. Existing page copies stay independent.</p>
                            @if ($this->savedSectionReview['canUpdate'])
                                <label>Name <input type="text" wire:model="savedSectionData.name" maxlength="255"></label>
                                <label>Description <textarea wire:model="savedSectionData.description" rows="2"></textarea></label>
                                <label>Category <input type="text" wire:model="savedSectionData.category" maxlength="100"></label>
                                @if ($this->savedSectionReview['canShare'])<div><label for="sb-saved-visibility">Visibility</label><select id="sb-saved-visibility" name="saved-visibility" wire:model="savedSectionData.visibility"><option value="private">Private</option><option value="shared">Shared</option></select></div>@endif
                                <button type="button" @click="run('updateSavedSection', false)" :disabled="busy || queue.blocked || $wire.readOnly">Save entry details</button>
                                @if($this->selectedId)<button type="button" @click="run('updateSavedSection', true)" :disabled="busy || queue.blocked || $wire.readOnly">Recapture selected page section as a new version</button>@endif
                            @endif
                            @if($this->savedSectionReview['canArchive'])<button type="button" @click="run('archiveSavedSection')" :disabled="busy || queue.blocked || $wire.readOnly">Archive library entry (keep page copies)</button>@endif
                        </details>
                    @endif
                    @if ($this->replacementReview && $this->pickerPosition['parentId'] === null)
                        <div class="sb-picker-replace" role="group" aria-label="Confirm draft section replacement">
                            <p>Replace all {{ $this->replacementReview['sectionCount'] }} current draft section(s) with {{ $this->pickerReview['sectionCount'] }} section(s) from this reviewed source. The live page, URLs and page settings stay unchanged.</p>
                            <label><input type="checkbox" wire:model="replaceConfirmed"> I reviewed this replacement and want to replace the draft sections</label>
                            <button type="button" @click="run('applyStartingPoint', true)" :disabled="busy || queue.blocked || $wire.readOnly || !$wire.replaceConfirmed || !referenceChoicesReviewed() || ($wire.pickerReferences.blocking ?? false)">Confirm draft replacement</button>
                        </div>
                    @endif
                @else
                    <h3 tabindex="-1" data-picker-detail x-ref="pickerDetail">Choose a section or template</h3><p>Review its actual local preview and references before adding it to this draft.</p>
                @endif
            </div>
        </div>
        <footer class="sb-picker-footer">
            <button type="button" @click="dismissPicker()">Cancel</button>
            @if ($this->pickerReview['reviewed'] ?? false)
                @if($this->pickerPosition['parentId'] === null)<button type="button" aria-label="Review replacing all draft sections" @click="run('prepareStartingPointReplacement')" :disabled="busy || queue.blocked || $wire.readOnly">Review draft replacement…</button>@endif
                <button type="button" class="sb-primary" @click="run('applyStartingPoint', false)" :disabled="busy || queue.blocked || $wire.readOnly || !referenceChoicesReviewed() || ($wire.pickerReferences.blocking ?? false)">Insert {{ $this->pickerReview['sectionCount'] }} section(s)</button>
            @endif
        </footer>
    </section>
</div>
