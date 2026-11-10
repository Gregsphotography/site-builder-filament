<x-filament-panels::page>
    <div class="site-builder-library">
        <p>Components available in this website’s page editor.</p>
        <div class="site-builder-library-grid">
            @forelse($this->componentForms() as $form)
                <article class="site-builder-component-card sb-library-card">
                    <button class="sb-library-card-button" type="button" wire:click="showExample({{ \Illuminate\Support\Js::from($form->type()) }})" aria-label="View example of {{ $form->label() }}">
                        <span class="sb-library-illustration" aria-hidden="true">
                            @include('site-builder-filament::pages.component-illustration', ['type' => $form->type()])
                        </span>
                        <span>
                            <span class="sb-library-card-title">{{ $form->label() }}</span>
                            <span class="sb-library-card-category">{{ $form->category() }}</span>
                        </span>
                        <span class="sb-library-card-hint">View example <span aria-hidden="true">→</span></span>
                    </button>
                </article>
            @empty
                <p>No component forms are registered yet.</p>
            @endforelse
        </div>
    </div>

    <x-filament::modal id="sb-component-example" width="5xl" :heading="$exampleLabel ?: 'Component example'">
        <div class="sb-component-example">
            <p class="sb-component-example-intro">A sample showing how this component can look. Your content and styling can be different.@if ($exampleHtml !== null) Scroll to see the full example.@endif</p>
            @if ($exampleNote && $exampleHtml !== null)<p class="sb-component-example-note">{{ $exampleNote }}</p>@endif
            @if ($exampleHtml !== null)
                <iframe
                    class="sb-component-example-frame"
                    title="{{ $exampleLabel }} example (scrollable)"
                    sandbox="allow-same-origin"
                    referrerpolicy="no-referrer"
                    tabindex="0"
                    srcdoc="{{ $exampleHtml }}"
                    x-on:load="$el.contentWindow.addEventListener('keydown', event => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            $dispatch('close-modal', { id: 'sb-component-example' });
                        } else if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('a, button, input, select, textarea, summary')) {
                            event.preventDefault();
                        }
                    })"
                ></iframe>
            @elseif ($exampleType !== '')
                <div class="sb-component-example-empty">
                    <div class="sb-library-illustration" aria-hidden="true">
                        @include('site-builder-filament::pages.component-illustration', ['type' => $exampleType])
                    </div>
                    <p>{{ $exampleNote ?: 'No example is available for this component yet.' }}</p>
                </div>
            @endif
        </div>
    </x-filament::modal>
</x-filament-panels::page>
