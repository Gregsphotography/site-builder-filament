<div class="sb-panel" x-show="panel === 'accessibility'" x-cloak>
    <h2>Accessibility · {{ strtoupper($this->locale) }}</h2>
    <p class="sb-help">Check the draft’s images and link names in this language before publishing.</p>
    <button type="button" class="sb-primary" @click="run('reviewAccessibility')" :disabled="busy || queue.blocked">Check this draft</button>
    @if($accessibilityReview && $accessibilityReview['version'] === $this->writeVersion && $accessibilityReview['locale'] === $this->locale)
        <p role="status">{{ count($accessibilityReview['findings']) === 0 ? 'No image or link findings in the checked fields.' : count($accessibilityReview['findings']).' '.\Illuminate\Support\Str::plural('finding', count($accessibilityReview['findings'])) }}</p>
        <ol class="sb-sections">
            @foreach($accessibilityReview['findings'] as $index => $finding)
                <li>
                    <strong>{{ $finding['section'] }}</strong> ·
                    {{ implode(' › ', array_map(fn ($key) => is_int($key) ? (string) ($key + 1) : \Illuminate\Support\Str::headline($key), $finding['path'])) }}
                    <p>{{ $finding['message'] }}</p>
                    @if(isset($finding['url']))
                        <a href="{{ $finding['url'] }}" target="_blank" rel="noopener">Open shared section</a>
                    @elseif($finding['mounted'])
                        <span>Shared content. Ask the site part editor to review this field.</span>
                    @else
                        <button type="button" @click="run('openAccessibilityFinding', {{ $index }})" :disabled="busy || queue.blocked">Go to field</button>
                    @endif
                </li>
            @endforeach
        </ol>
    @elseif($accessibilityReview)
        <p role="status">Check this draft again to see findings for the current version and language.</p>
    @endif
</div>
