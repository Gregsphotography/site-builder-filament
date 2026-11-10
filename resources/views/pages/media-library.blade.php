<x-filament-panels::page>
    <p>Upload reusable images, browser-ready MP4/WebM videos and WebVTT captions. Images retain alternative text for each language.</p>
    @if($this->canManage())
        <form wire:submit="save" class="sb-create">
            <h2>{{ $selectedAsset ? 'Edit media text' : 'Upload media' }}</h2>
            {{ $this->form }}
            <x-filament::button type="submit">Save media</x-filament::button>
            @if($selectedAsset && $this->canMedia('upload'))
                <x-filament::button type="button" color="gray" wire:click="newUpload">New upload</x-filament::button>
            @endif
        </form>
    @endif
    <div class="site-builder-library-grid">
        @forelse($this->assets() as $asset)
            <article class="site-builder-component-card" wire:key="media-{{ $asset['id'] }}">
                @if($asset['kind'] === 'image')
                    <img src="{{ $asset['url'] }}" alt="{{ $asset['alt'] }}" loading="lazy" style="width:100%;height:160px;object-fit:contain">
                @elseif($asset['kind'] === 'video')
                    <video controls preload="none" style="width:100%;height:160px"><source src="{{ $asset['url'] }}"></video>
                @else
                    <a href="{{ $asset['url'] }}">Open WebVTT captions</a>
                @endif
                <h2>{{ $asset['name'] }}</h2>
                <p>{{ $asset['mime'] }} @if($asset['width']) · {{ $asset['width'] }} × {{ $asset['height'] }} @endif</p>
                @if($asset['kind'] === 'image' && $this->canMedia('update'))
                    <x-filament::button color="gray" size="sm" wire:click="editAsset('{{ $asset['id'] }}')">Edit alternative text</x-filament::button>
                @endif
            </article>
        @empty
            <p>No media has been uploaded yet.</p>
        @endforelse
    </div>
</x-filament-panels::page>
