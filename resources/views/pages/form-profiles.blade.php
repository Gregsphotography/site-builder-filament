<x-filament-panels::page>
    <p>Choose where enquiries are sent. Page editors select an approved destination; email addresses stay out of public page content.</p>
    @if($this->canManage())
        <form wire:submit="save" class="sb-create">
            <h2>{{ $selectedProfile ? 'Edit enquiry destination' : 'New enquiry destination' }}</h2>
            {{ $this->form }}
            <x-filament::button type="submit">Save destination</x-filament::button>
        </form>
    @endif
    @if($selectedProfile)
        <x-filament::button wire:click="newProfile" color="gray">New destination</x-filament::button>
    @endif
    <div class="site-builder-library-grid">
        @forelse($this->profiles() as $profile)
            <article class="site-builder-component-card" wire:key="profile-{{ $profile['id'] }}">
                <h2>{{ $profile['name'] }}</h2>
                <p>{{ $profile['active'] ? 'Accepting enquiries' : 'Inactive' }}</p>
                <p>{{ implode(', ', $profile['recipients']) ?: 'Inbox only' }}</p>
                <x-filament::button wire:click="edit('{{ $profile['id'] }}')" color="gray">Open destination</x-filament::button>
            </article>
        @empty
            <p>No enquiry destinations have been created yet.</p>
        @endforelse
    </div>
</x-filament-panels::page>
