<div class="sb-panel" x-show="panel === 'reviews'" x-cloak>
    <h2>Client review and publication</h2>
    @if ($reviewLinksEnabled)
        <p>Share a fixed copy of the current draft for approval. You can keep editing while your client reviews it.</p>
    @else
        <p>Client review links are disabled in the site preview configuration.</p>
    @endif
    @if ($canShareReview)<button type="button" @click="run('requestClientReview')" :disabled="busy || $wire.readOnly || queue.blocked">Create client review link</button>@endif
    <button type="button" wire:click="$refresh" wire:loading.attr="disabled">Refresh reviews</button>
    @forelse($clientReviews as $entry)
        @php($review = $entry['record'])
        <section class="sb-capture" wire:key="review-{{ $review->id }}">
            <h3>Draft {{ $review->draft_version }} · {{ $review->review_title }}</h3>
            <p>{{ ucfirst(str_replace('_', ' ', $review->status === 'pending' && $review->expires_at <= now()->timestamp ? 'expired' : $review->status)) }}@if ($review->reviewer) · {{ $review->reviewer }}@endif</p>
            @if ($review->feedback)<p>{{ $review->feedback }}</p>@endif
            @if ($review->failure)<p role="alert">{{ $review->failure }}</p>@endif
            @if ($entry['url'])
                <label>Client review link <input type="url" readonly value="{{ $entry['url'] }}" @focus="$el.select()"></label>
                <p><a href="{{ $entry['url'] }}" target="_blank" rel="noreferrer">Open review</a> · Expires {{ \Carbon\CarbonImmutable::createFromTimestamp($review->expires_at, config('app.timezone'))->format('Y-m-d H:i T') }}</p>
            @endif
            @if ($entry['time'])<p>Scheduled time: {{ $entry['time'] }}</p>@endif
            @if ($canPublish && in_array($review->status, ['approved', 'scheduled'], true))
                <button type="button" @click="run('publishApprovedVersion', @js($review->id))" :disabled="busy || $wire.readOnly || queue.blocked">Publish approved version now</button>
                <label>Publication time ({{ config('app.timezone') }}) <input type="datetime-local" wire:model="releaseTimes.{{ $review->id }}"></label>
                <button type="button" @click="run('scheduleApprovedVersion', @js($review->id))" :disabled="busy || $wire.readOnly || queue.blocked">{{ $review->status === 'scheduled' ? 'Reschedule' : 'Schedule approved version' }}</button>
            @endif
            @if (($canManageReview && in_array($review->status, ['pending', 'approved'], true)) || ($canPublish && $review->status === 'scheduled'))
                <button type="button" @click="run('cancelClientReview', @js($review->id))" :disabled="busy || $wire.readOnly || queue.blocked">Cancel review{{ $review->status === 'scheduled' ? ' and schedule' : '' }}</button>
            @endif
        </section>
    @empty
        <p>No client reviews yet.</p>
    @endforelse
</div>
