<x-filament-panels::page>
    <p>Author popup and drawer content once, then attach its published revision to buttons, calls to action or navigation links.</p>
    @if(\SiteBuilder\Filament\Resources\SiteParts\SitePartResource::canCreate())<a class="sb-primary" href="{{ \SiteBuilder\Filament\Resources\SiteParts\SitePartResource::getUrl('create', ['kind' => 'overlay']) }}">Create popup or drawer</a>@endif
    <label>Find a popup<input type="search" wire:model.live.debounce.300ms="search" maxlength="255"></label>
    @php($results = $this->entries())
    <ul class="sb-popup-library">
        @forelse($results['entries'] as $part)
            <li><a href="{{ \SiteBuilder\Filament\Resources\SiteParts\SitePartResource::getUrl('edit', ['record' => $part->id]) }}"><strong>{{ $part->name }}</strong></a><p>{{ $part->description }}</p><span>{{ $part->current_revision_id ? 'Published revision available' : 'Draft — publish before attaching' }}</span></li>
        @empty<li>No matching popups or drawers.</li>@endforelse
    </ul>
    <div>@if($this->cursor)<button type="button" wire:click="firstPage">Back to first page</button>@endif @if($results['nextCursor'])<button type="button" wire:click="nextPage">Next page</button>@endif</div>
</x-filament-panels::page>
