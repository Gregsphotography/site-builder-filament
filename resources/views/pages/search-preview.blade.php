<section class="sb-sharing-preview" aria-label="Search and social previews">
    <h3>Search preview · {{ strtoupper($this->locale) }}</h3>
    <p class="sb-help">These examples use your saved draft. Search engines and social apps may display it differently.</p>
    @if(data_get($this->settingsBaseline, 'noindex'))
        <p>This language is marked to be excluded from indexing.</p>
    @endif
    <div class="sb-snippet">
        <small>{{ $searchPreview['canonical'] ?? 'No URL for this language' }}</small>
        <strong>{{ $searchPreview['title'] }}</strong>
        <p>{{ $searchPreview['description'] ?: 'No search description supplied.' }}</p>
    </div>
    <h3>Social preview</h3>
    <div class="sb-share-card">
        @if($searchPreview['image'])
            <img src="{{ $searchPreview['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
        @endif
        <div><small>{{ parse_url($searchPreview['canonical'] ?? '', PHP_URL_HOST) }}</small>
            <strong>{{ $searchPreview['title'] }}</strong>
            @if($searchPreview['description'])<p>{{ $searchPreview['description'] }}</p>@endif
        </div>
    </div>
</section>
