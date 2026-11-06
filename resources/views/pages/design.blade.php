<div class="sb-panel sb-design-panel" x-show="panel === 'design'" x-cloak>
    <h2 data-design-heading tabindex="-1">Page and section design</h2>
    <p class="sb-help">Design is shared across languages and stays in the draft until published. Preview size is a viewing aid; it never creates a saved responsive override.</p>
    <div class="sb-design-targets">
        <div><label for="sb-design-scope">Apply design to</label>
            <select id="sb-design-scope" name="design-scope" :value="$wire.designScope" @change="run('changeDesignTarget', $event.target.value, $wire.designBreakpoint)" :disabled="busy || queue.blocked">
                <option value="node">Selected section</option><option value="page">Page defaults</option>
            </select>
        </div>
        <div><label for="sb-design-breakpoint">Saved responsive target</label>
            <select id="sb-design-breakpoint" name="design-breakpoint" :value="$wire.designBreakpoint" @change="run('changeDesignTarget', $wire.designScope, $event.target.value)" :disabled="busy || queue.blocked">
                @foreach ($designBreakpoints as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </div>
    </div>
    <p class="sb-design-context"><strong>{{ $designScopeLabel }} · {{ $designTargetLabel }}</strong></p>
    @if($designDescription['available'])
        <p class="sb-help">Unavailable choices would conflict with this target’s inherited or pending settings. Choose a readable surface and text treatment; other targets are checked when saving.</p>
        <form wire:key="design-fields-{{ $this->designScope }}-{{ $this->selectedId }}-{{ $this->designBreakpoint }}" @submit.prevent="run('saveDesign')">
            <fieldset :disabled="$wire.readOnly || queue.blocked">{{ $this->designForm }}</fieldset>
            <button class="sb-primary" type="submit" :disabled="busy || $wire.readOnly || queue.blocked">Save design to draft</button>
        </form>
        <details class="sb-design-resets" wire:ignore.self>
            <summary data-design-reset-summary>Reset and inheritance</summary>
            <p class="sb-help">Reset removes an override. The control then inherits the resolved value shown above. Resetting all sizes keeps deliberate overrides at other targets unless you reset the entire scope.</p>
            <div class="sb-design-reset-list">
                @foreach($designControls as $control)
                    <button type="button" @click="run('resetDesignKey', @js($control['key']))" :disabled="busy || $wire.readOnly || queue.blocked || !$wire.designData[@js($control['key'])] || @js($this->designBreakpoint !== 'base' && !$control['responsive'])">Reset {{ $control['label'] }}</button>
                @endforeach
            </div>
            <button type="button" @click="run('resetDesignBreakpoint')" :disabled="busy || $wire.readOnly || queue.blocked">Reset {{ $designTargetLabel }} overrides</button>
            <button type="button" @click="run('resetDesignScope')" :disabled="busy || $wire.readOnly || queue.blocked">Reset all {{ $this->designScope === 'page' ? 'page' : 'section' }} design overrides</button>
            <p class="sb-help">Content, translations and the document theme are retained.</p>
        </details>
    @else
        <p class="sb-empty">{{ ($designDescription['canResetScope'] ?? false) ? 'Stored design needs a compatibility review and is retained as-is. Reset this scope or review a compatible theme before editing its controls.' : 'Design controls are unavailable for this section, layout or rendering capability. Stored design is retained. Host layouts need a registered design adapter and available styles.' }}</p>
        @if($designDescription['canResetScope'] ?? false)<button type="button" @click="run('resetDesignScope')" :disabled="busy || $wire.readOnly || queue.blocked">Reset incompatible {{ $this->designScope === 'page' ? 'page' : 'section' }} overrides</button>@endif
    @endif
    @foreach($designDescription['diagnostics'] as $diagnostic)
        <p class="sb-design-diagnostic">{{ is_string($diagnostic) ? $diagnostic : data_get($diagnostic, 'message', 'A design compatibility issue requires review.') }}</p>
    @endforeach
    <section class="sb-design-theme" aria-labelledby="sb-design-theme-heading">
        <h3 id="sb-design-theme-heading">Content canvas theme</h3>
        <p class="sb-help">Changes affect this page’s content canvas after publication. The host layout, navigation and site header/footer stay under their own settings.</p>
        @if($designCanChooseTheme)
            <div><label for="sb-design-theme-choice">Theme and preset</label>
                <select id="sb-design-theme-choice" name="design-theme" wire:model="designThemeChoice" :disabled="busy || $wire.readOnly || queue.blocked">
                    <option value="">Use the current host default</option>
                    @if($this->designThemeBaseline === '__unavailable__')<option value="__unavailable__" disabled>Stored theme unavailable — data retained</option>@endif
                    @foreach($designThemes as $theme)<option value="{{ $theme['key'] }}" @disabled(!$theme['available'])>{{ $theme['label'] }}{{ $theme['available'] ? '' : ' (unavailable)' }}</option>@endforeach
                </select>
            </div>
            <div class="sb-design-theme-actions">
                <button type="button" @click="run('prepareDesignTheme')" :disabled="busy || $wire.readOnly || queue.blocked">Review theme change</button>
                <button type="button" @click="run('discardDesignThemeChoice')" :disabled="busy || queue.blocked || !pendingThemeChoice()">Discard pending theme choice</button>
            </div>
        @else
            <p>No compatible canvas-theme adapter is available in this layout. Existing theme data is preserved.</p>
        @endif
        @if($this->designThemeReview)
            <div class="sb-design-theme-review" role="group" aria-label="Review canvas theme change">
                <h4 data-design-theme-review-heading tabindex="-1">Canvas theme review</h4>
                <p>Review applies to accepted draft version {{ $this->designThemeReview['pageVersion'] }}. Existing content and explicit settings are retained.</p>
                @foreach($designThemeChanges as $change)<p>{{ $change }}</p>@endforeach
                @forelse($this->designThemeReview['report']['diagnostics'] ?? [] as $diagnostic)
                    <p>{{ is_string($diagnostic) ? $diagnostic : data_get($diagnostic, 'message', 'A theme compatibility issue requires review.') }}</p>
                @empty<p>The registered theme supports the current design settings.</p>@endforelse
                @if($this->designThemeReview['report']['compatible'] ?? false)
                    <label><input type="checkbox" wire:model="designThemeConfirmed"> I reviewed the effect on this draft and want to apply the theme</label>
                    <button type="button" class="sb-primary" @click="run('applyDesignTheme')" :disabled="busy || $wire.readOnly || queue.blocked || !$wire.designThemeConfirmed || $wire.designThemeReview.pageVersion !== $wire.writeVersion || $wire.designThemeReview.choice !== ($wire.designThemeChoice || null)">Apply reviewed theme to draft</button>
                @else<p role="alert">Resolve incompatible overrides before applying this theme. No settings have been discarded.</p>@endif
            </div>
        @endif
    </section>
</div>
