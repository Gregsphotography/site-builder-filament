<x-filament-panels::page>
    <form wire:submit="create" class="sb-create">
        {{ $this->form }}
        <x-filament::button type="submit">Create page</x-filament::button>
    </form>
</x-filament-panels::page>
