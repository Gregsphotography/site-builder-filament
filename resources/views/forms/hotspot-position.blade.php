@php
    $parent = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
    $xPath = $parent.'.x';
    $yPath = $parent.'.y';
    $reference = $getLivewire()->formData['image'] ?? null;
    $image = is_string($reference) ? app(\SiteBuilder\Core\Contracts\MediaResolver::class)->url($reference) : null;
    if ($image !== null && !app(\SiteBuilder\Core\Rendering\SafeUrl::class)->valid($image, true)) $image = null;
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if($image)
        <div x-data="{
            x: $wire.entangle(@js($xPath)), y: $wire.entangle(@js($yPath)), dragging: false,
            place(event) { const rect = this.$refs.frame.getBoundingClientRect(); this.x = Math.round(Math.max(0, Math.min(100, (event.clientX - rect.left) / rect.width * 100)) * 100) / 100; this.y = Math.round(Math.max(0, Math.min(100, (event.clientY - rect.top) / rect.height * 100)) * 100) / 100; },
            step(event) { const delta = event.shiftKey ? .1 : 1; if (event.key === 'ArrowLeft') this.x = Math.max(0, Number(this.x) - delta); if (event.key === 'ArrowRight') this.x = Math.min(100, Number(this.x) + delta); if (event.key === 'ArrowUp') this.y = Math.max(0, Number(this.y) - delta); if (event.key === 'ArrowDown') this.y = Math.min(100, Number(this.y) + delta); }
        }">
            <div class="sb-hotspot-placement" x-ref="frame" @click="place($event); $dispatch('change')" @pointermove="if(dragging) place($event)" @pointerup="dragging = false; $dispatch('change')" @pointercancel="dragging = false">
                <img src="{{ $image }}" alt="" draggable="false">
                <button type="button" aria-label="Move this hotspot" :style="{ left: (Number(x) || 0) + '%', top: (Number(y) || 0) + '%' }" @pointerdown.prevent="dragging = true; $el.setPointerCapture($event.pointerId)" @click.stop @keydown.left.prevent="step($event)" @keydown.right.prevent="step($event)" @keydown.up.prevent="step($event)" @keydown.down.prevent="step($event)">+</button>
            </div>
            <p class="sb-help">Click the image or drag the marker. Arrow keys move it by 1%; hold Shift for smaller steps.</p>
        </div>
    @else<p class="sb-help">Choose an image to place this hotspot visually.</p>@endif
</x-dynamic-component>
