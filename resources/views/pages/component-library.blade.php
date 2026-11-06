<x-filament-panels::page>
    <div class="site-builder-library">
        <p>Components available in this website’s page editor.</p>
        <div class="site-builder-library-grid">
            @forelse($this->componentForms() as $form)
                <article class="site-builder-component-card">
                    <h2>{{ $form->label() }}</h2>
                    <p>{{ $form->category() }}</p>
                </article>
            @empty
                <p>No component forms are registered yet.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
