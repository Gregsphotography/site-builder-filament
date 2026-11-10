<x-filament-panels::page>
    <label>Show enquiries
        <select wire:model.live="statusFilter" wire:change="$set('pageNumber', 1)"><option value="active">Active</option><option value="unread">Unread</option><option value="archived">Archived</option></select>
    </label>
    <div class="site-builder-library-grid">
        @forelse($this->entries() as $entry)
            <article class="site-builder-component-card" wire:key="enquiry-{{ $entry['id'] }}">
                <h2>{{ $entry['title'] }} @if($entry['unread']) · Unread @endif</h2>
                <p>{{ $entry['created'] }} · {{ $entry['locale'] }}</p>
                <x-filament::button wire:click="open('{{ $entry['id'] }}')">Open enquiry</x-filament::button>
            </article>
        @empty
            <p>No enquiries in this view.</p>
        @endforelse
    </div>
    <div>
        @if($pageNumber > 1)<x-filament::button wire:click="$set('pageNumber', {{ $pageNumber - 1 }})" color="gray">Newer</x-filament::button>@endif
        @if(count($this->entries()) === 25)<x-filament::button wire:click="$set('pageNumber', {{ $pageNumber + 1 }})" color="gray">Older</x-filament::button>@endif
    </div>
    @if($detail = $this->selected())
        <x-filament::section :heading="$detail['title']" wire:key="selected-{{ $detail['id'] }}">
            <p>Notification: {{ $detail['status'] === 'sent' ? 'Sent to mail transport' : $detail['status'] }}</p>
            @if($detail['error'])<p role="status">{{ $detail['error'] }}</p>@endif
            <dl>
                @foreach($detail['fields'] as $field)
                    <dt><strong>{{ $field['label'] }}</strong></dt><dd style="white-space:pre-wrap">{{ is_bool($field['value']) ? ($field['value'] ? 'Yes' : 'No') : $field['value'] }}</dd>
                @endforeach
            </dl>
            @if($detail['canUpdate'])
                <x-filament::button wire:click="markUnread('{{ $detail['id'] }}')" color="gray">Mark unread</x-filament::button>
                <x-filament::button wire:click="archive('{{ $detail['id'] }}')" color="gray">{{ $detail['archived'] ? 'Restore to active' : 'Archive' }}</x-filament::button>
            @endif
            @if($detail['canRetry'])<x-filament::button wire:click="retry('{{ $detail['id'] }}')">Retry notification</x-filament::button>@endif
            @if($detail['canDelete'])<x-filament::button wire:click="delete('{{ $detail['id'] }}')" wire:confirm="Permanently delete this enquiry?" color="danger">Delete enquiry</x-filament::button>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
