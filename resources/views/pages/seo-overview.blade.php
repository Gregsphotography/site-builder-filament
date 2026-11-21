<x-filament-panels::page>
    <p>Review the published version of each page in every configured language. Unpublished pages show their draft settings and content.</p>
    <div class="site-builder-library-grid">
        <div><label for="seo-filter-language">Language</label>
            <x-filament::input.wrapper><x-filament::input.select id="seo-filter-language" wire:model.live="language">
                <option value="">All languages</option>
                @foreach(app(\SiteBuilder\Core\Rendering\LocaleCatalog::class)->all() as $code)
                    <option value="{{ $code }}">{{ config('site-builder.locales.'.$code.'.label', strtoupper($code)) }}</option>
                @endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </div>
        <div><label for="seo-filter-publication">Publication</label>
            <x-filament::input.wrapper><x-filament::input.select id="seo-filter-publication" wire:model.live="publication">
                <option value="">All pages</option><option value="published">Published</option><option value="draft">Draft</option>
            </x-filament::input.select></x-filament::input.wrapper>
        </div>
        <div><label for="seo-filter-finding">Finding</label>
            <x-filament::input.wrapper><x-filament::input.select id="seo-filter-finding" wire:model.live="finding">
                <option value="">All findings</option>
                <option value="missing_url">Missing language URL</option><option value="missing_title">Missing search title</option>
                <option value="shared_title">Uses the page title</option><option value="missing_description">Missing description</option><option value="missing_image">Missing social image</option>
                <option value="duplicate_title">Duplicate search title</option><option value="duplicate_description">Duplicate description</option>
                <option value="translation_fallback">Content using a fallback</option><option value="translation_unavailable">Content unavailable for review</option>
                <option value="noindex">Excluded from indexing</option><option value="other_canonical">Canonical points elsewhere</option>
                <option value="clear">No findings</option>
            </x-filament::input.select></x-filament::input.wrapper>
        </div>
    </div>

    @php($report = $this->report())
    <p role="status">{{ $report['total'] }} {{ $report['total'] === 1 ? 'page translation' : 'page translations' }}</p>
    @foreach($report['rows'] as $row)
        @php($target = ['record' => $row['id'], 'locale' => $row['locale']])
        <x-filament::section compact wire:key="seo-{{ $row['id'] }}-{{ $row['locale'] }}">
            <x-slot name="heading">{{ $row['page'] }} · {{ strtoupper($row['locale']) }}</x-slot>
            <x-slot name="description">{{ $row['published'] ? 'Published page' : 'Draft page' }} · {{ $row['url'] ?? 'No URL for this language' }}</x-slot>
            <p>Search title · <x-filament::link :href="\SiteBuilder\Filament\Resources\Pages\PageResource::getUrl('edit', $target + ['panel' => 'settings', 'field' => 'meta_title'])">{{ $row['title'] ?: 'Set a search title' }}</x-filament::link>
                @if($row['usesPageTitle']) (from page title) @endif
            </p>
            @if($row['issues'])
                <ul>
                    @foreach($row['issues'] as $issue)
                        <li><x-filament::link :href="$issue['url']">{{ $issue['label'] }}</x-filament::link></li>
                    @endforeach
                </ul>
            @else
                <p>No findings for this language.</p>
            @endif
        </x-filament::section>
    @endforeach
    @if($report['total'] === 0)<p>No pages match these filters.</p>@endif
    @if($report['last'] > 1)
        <nav aria-label="SEO overview pages">
            @if($report['current'] > 1)<x-filament::button color="gray" wire:click="$set('pageNumber', {{ $report['current'] - 1 }})">Previous</x-filament::button>@endif
            Page {{ $report['current'] }} of {{ $report['last'] }}
            @if($report['current'] < $report['last'])<x-filament::button color="gray" wire:click="$set('pageNumber', {{ $report['current'] + 1 }})">Next</x-filament::button>@endif
        </nav>
    @endif
</x-filament-panels::page>
