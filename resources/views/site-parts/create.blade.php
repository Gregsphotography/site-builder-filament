<x-filament-panels::page>
    <div class="sb-create">
        <p>A site part has its own draft, editing access, publication and revisions. Publishing it makes a revision available for explicit use; existing page copies and pinned revisions stay unchanged.</p>
        <form wire:submit="create">{{ $this->form }}<x-filament::button type="submit">Create site-part draft</x-filament::button></form>
    </div>
</x-filament-panels::page>
