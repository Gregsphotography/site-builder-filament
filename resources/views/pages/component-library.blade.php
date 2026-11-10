<x-filament-panels::page>
    <div class="site-builder-library">
        <p>Components available in this website’s page editor.</p>
        <div class="site-builder-library-grid">
            @forelse($this->componentForms() as $form)
                <article class="site-builder-component-card sb-library-card">
                    <div class="sb-library-illustration">
                        @include('site-builder-filament::pages.component-illustration', ['type' => $form->type()])
                    </div>
                    <div>
                        <h2>{{ $form->label() }}</h2>
                        <p>{{ $form->category() }}</p>
                    </div>
                </article>
            @empty
                <p>No component forms are registered yet.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
