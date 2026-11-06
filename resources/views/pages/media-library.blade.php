<x-filament-panels::page>
    <p>Upload reusable images and maintain alternative text for each language.</p>
    @if($this->canManage())
        <form wire:submit="save" class="sb-create">
            <h2>{{ $selectedAsset ? 'Edit alternative text' : 'Upload image' }}</h2>
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
                <img src="{{ $asset['url'] }}" alt="{{ $asset['alt'] }}" loading="lazy" style="width:100%;height:160px;object-fit:contain">
                <h2>{{ $asset['name'] }}</h2>
                <p>{{ $asset['width'] }} × {{ $asset['height'] }}</p>
                @if($this->canMedia('update'))
                    <x-filament::button color="gray" size="sm" wire:click="editAsset('{{ $asset['id'] }}')">Edit alternative text</x-filament::button>
                @endif
            </article>
        @empty
            <p>No images have been uploaded yet.</p>
        @endforelse
    </div>
</x-filament-panels::page>
