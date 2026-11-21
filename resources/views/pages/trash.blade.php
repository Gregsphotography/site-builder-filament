<x-filament-panels::page>
    <p>Restore a page to draft to edit or republish it. Its URLs and publication history are retained.</p>
    @forelse ($entries as $entry)
        <x-filament::section wire:key="trash-page-{{ $entry->id }}">
            <strong>{{ $entry->title }}</strong>
            <span>Moved to Trash {{ $entry->updated_at?->format('Y-m-d H:i') }}</span>
            @if ($gate->allows('restore', $entry))
                <x-filament::button wire:click="restorePage({{ $entry->id }}, {{ $entry->write_version }})">Restore to draft</x-filament::button>
            @endif
        </x-filament::section>
    @empty
        <p>No pages in Trash on this page.</p>
    @endforelse
    <div>
        @if ($pageNumber > 1)
            <x-filament::button color="gray" wire:click="$set('pageNumber', {{ $pageNumber - 1 }})">Previous</x-filament::button>
        @endif
        @if ($more)
            <x-filament::button color="gray" wire:click="$set('pageNumber', {{ $pageNumber + 1 }})">Next</x-filament::button>
        @endif
    </div>
    <a href="{{ \SiteBuilder\Filament\Resources\Pages\PageResource::getUrl('index') }}">Back to pages</a>
</x-filament-panels::page>
