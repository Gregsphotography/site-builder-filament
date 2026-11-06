@if(($galleryMedia = $this->galleryBatchOptions()) !== null)
<details class="sb-snapshot-review" wire:ignore.self>
    <summary>Select multiple gallery images</summary>
    <p>Select library images, review the accepted order and any failures, then confirm before adding. Upload new images in the Media library; existing rows stay intact.</p>
    <label for="sb-gallery-media">Library images</label><select id="sb-gallery-media" multiple size="5" wire:model="mediaBatchReferences">@foreach($galleryMedia as $reference => $label)<option value="{{ $reference }}">{{ $label }}</option>@endforeach</select>
    <button type="button" @click="run('reviewMediaBatch')" :disabled="busy || queue.blocked || $wire.readOnly">Review image selection</button>
    @if($this->mediaBatchReview)
        <h3>Review selected images</h3><ol>@foreach($this->mediaBatchReview['selection']['accepted'] as $item)<li>{{ $item['alt'] ?: __('Image without alternative text — edit before publishing') }}</li>@endforeach</ol>
        @foreach($this->mediaBatchReview['selection']['failures'] as $index => $message)<p role="alert">{{ __('Selection :number: :message', ['number' => $index + 1, 'message' => $message]) }}</p>@endforeach
        <label><input type="checkbox" wire:model="mediaBatchConfirmed"> I reviewed the accepted images and any failed selections</label>
        <button type="button" @click="run('acceptMediaBatch')" :disabled="busy || queue.blocked || $wire.readOnly || !$wire.mediaBatchConfirmed">Add reviewed images</button>
        <button type="button" @click="run('cancelMediaBatch')" :disabled="busy">Cancel image selection</button>
    @endif
</details>
@endif
