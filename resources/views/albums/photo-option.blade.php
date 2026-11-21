<span style="display:inline-flex; align-items:center; gap:.5rem">
    @if($thumbnail)
        <img src="{{ $thumbnail }}" alt="" loading="lazy" width="40" height="40" style="width:2.5rem; height:2.5rem; object-fit:cover">
    @endif
    <span>{{ $name }}</span>
</span>
