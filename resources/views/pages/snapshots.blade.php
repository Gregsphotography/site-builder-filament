@if($this->snapshotEligible())
<section class="sb-capture" aria-label="Copy from an authorized source">
    <h2>Copy from a source</h2>
    <p class="sb-help">Edit these fields directly, or explicitly copy an authorized menu or site identity. Published content keeps its saved values.</p>
    <button type="button" @click="run('loadSnapshotSources')" :disabled="busy || queue.blocked">Find authorized sources</button>
    @foreach($snapshotChoices as $choice)
        <button type="button" @click="run('reviewSnapshotSource', @js($choice['provider']), @js($choice['id']))" :disabled="busy || queue.blocked">Review {{ $choice['label'] }}</button>
    @endforeach
    @if($snapshotReview)
    <div class="sb-snapshot-review" data-snapshot-review tabindex="-1" role="region" aria-label="Source copy review">
        <p>{{ $snapshotReview['warning'] }}</p>
        @foreach($snapshotReview['references']['occurrences'] as $entry)
            <label>{{ $entry['kind'] }} · {{ $entry['path'] }} · {{ $entry['status'] }}
                <input type="text" wire:model="snapshotReferenceValues.{{ $entry['key'] }}" aria-label="Destination reference for {{ $entry['path'] }}">
            </label>
            @if($entry['status'] === 'unverified')<label><input type="checkbox" wire:model="snapshotKeepUnverified.{{ $entry['key'] }}"> Explicitly keep this unverified host reference</label>@endif
        @endforeach
        @if($snapshotReview['references']['occurrences'])<button type="button" @click="run('reviewSnapshotMappings')" :disabled="busy || queue.blocked">Review reference changes</button>@endif
        @foreach($snapshotReview['changes'] as $change)
            <p><strong>{{ $change['path'] }}</strong></p><pre>{{ ($change['beforePresent'] ?? true) ? json_encode($change['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '(unset)' }}</pre><p>becomes</p><pre>{{ json_encode($change['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endforeach
        @if(!$snapshotReview['references']['blocking'])
            @if(!$snapshotReview['changes'])<p>No fields change in this draft.</p>@endif
            <label><input type="checkbox" wire:model="snapshotConfirmed"> Replace the reviewed draft fields</label>
            <button type="button" @click="run('applySnapshotSource')" :disabled="busy || queue.blocked">Copy into draft</button>
        @else<p>Resolve missing, denied or unverified references before copying.</p>@endif
        <button type="button" @click="run('cancelSnapshotSource')" :disabled="busy || queue.blocked">Cancel source copy</button>
    </div>
    @endif
</section>
@endif
