@foreach($nodes as $section)
    <li wire:key="section-{{ $section['id'] }}" data-node-id="{{ $section['id'] }}" data-parent-id="{{ $section['parentId'] }}" @class(['sb-selected' => $this->selectedId === $section['id']])>
        <button type="button" class="sb-section-select" @click="run('selectNode', @js($section['id']))" :disabled="busy || queue.blocked" aria-pressed="{{ $this->selectedId === $section['id'] ? 'true' : 'false' }}"
            :draggable="$wire.selectedId===@js($section['id']) && !$wire.readOnly && !queue.blocked && !busy" @dragstart.stop="draggedNode = @js($section['id']); $event.dataTransfer.effectAllowed = 'move'" @dragend="draggedNode = null"
            @dragover.prevent @drop.prevent.stop="if (draggedNode) run('moveTreeSection', draggedNode, $event.shiftKey && @js($section['acceptsChildren']) ? @js($section['id']) : @js($section['parentId']), $event.shiftKey && @js($section['acceptsChildren']) ? 'end' : 'before', $event.shiftKey && @js($section['acceptsChildren']) ? null : @js($section['id']))"
            @keydown.alt.up.prevent="treeAction(@js($section['id']), 'moveSection', -1)" @keydown.alt.down.prevent="treeAction(@js($section['id']), 'moveSection', 1)" @keydown.alt.right.prevent="treeAction(@js($section['id']), 'moveSectionIn')" @keydown.alt.left.prevent="treeAction(@js($section['id']), 'moveSectionOut')">
            <span>{{ $section['ordinal'] }}. {{ $section['label'] }}</span>
            @if($section['hidden'])<small>Hidden</small>@endif
            @unless($section['supported'])<small>Unsupported · data retained</small>@endunless
        </button>
        @if($this->selectedId===$section['id'])<div class="sb-section-tools" role="group" aria-label="Structure tools for {{ $section['ordinal'] }}. {{ $section['label'] }}">
            <button type="button" @click="run('moveSection', @js($section['id']), -1)" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canMoveUp'])" aria-label="Move {{ $section['label'] }} up">↑</button>
            <button type="button" @click="run('moveSection', @js($section['id']), 1)" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canMoveDown'])" aria-label="Move {{ $section['label'] }} down">↓</button>
            <button type="button" @click="run('moveSectionIn', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canMoveIn'])" aria-label="Move {{ $section['label'] }} into the preceding container">Move in</button>
            <button type="button" @click="run('moveSectionOut', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canMoveOut'])" aria-label="Move {{ $section['label'] }} after its parent">Move out</button>
            @if($section['supported'])
                <button type="button" @click="run('duplicateSection', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canDuplicate'])" aria-label="Duplicate {{ $section['label'] }}">Duplicate</button>
                <button type="button" @click="run('hideSection', @js($section['id']), @js(!$section['hidden']))" :disabled="busy || $wire.readOnly || queue.blocked">{{ $section['hidden'] ? 'Show' : 'Hide' }}</button>
            @endif
            <button type="button" @click="run('removeSection', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked || @js(!$section['canRemove'])" aria-label="Remove {{ $section['label'] }}">Remove</button>
            <button type="button" @click="openPicker('before', @js($section['id']), @js($section['parentId']))" @dragover.prevent @drop.prevent.stop="if (draggedNode) run('moveTreeSection', draggedNode, @js($section['parentId']), 'before', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked" aria-label="Add before {{ $section['label'] }}">Add before</button>
            <button type="button" @click="openPicker('after', @js($section['id']), @js($section['parentId']))" @dragover.prevent @drop.prevent.stop="if (draggedNode) run('moveTreeSection', draggedNode, @js($section['parentId']), 'after', @js($section['id']))" :disabled="busy || $wire.readOnly || queue.blocked" aria-label="Add after {{ $section['label'] }}">Add after</button>
            @if($section['canAddChild'])<button type="button" @click="openPicker('end', null, @js($section['id']))" @dragover.prevent @drop.prevent.stop="if (draggedNode) run('moveTreeSection', draggedNode, @js($section['id']), 'end', null)" :disabled="busy || $wire.readOnly || queue.blocked" aria-label="Add inside {{ $section['label'] }}">Add inside</button>@endif
            @if($section['type'] === 'standard.columns' && $section['canAddChild'])<button type="button" @click="run('addTreeSection', 'standard.column', @js($section['id']), 'end', null)" :disabled="busy || $wire.readOnly || queue.blocked">Add column</button>@endif
            <div class="sb-tree-destination"><label for="sb-tree-parent-{{ $section['id'] }}">Move selected section to parent</label><select id="sb-tree-parent-{{ $section['id'] }}" wire:model.live="treeMoveParentId" :disabled="busy || queue.blocked || $wire.readOnly">@foreach($treeMoveDestinations as $parentId=>$label)<option value="{{ $parentId }}">{{ $label }}</option>@endforeach</select><button type="button" @click="run('moveToParent')" :disabled="busy || queue.blocked || $wire.readOnly || @js(!$canMoveToParent)">Move to end of chosen parent</button></div>
        </div>@endif
        @if($section['children'])<ol class="sb-tree-children" aria-label="Children of {{ $section['ordinal'] }}. {{ $section['label'] }}">@include('site-builder-filament::pages.tree', ['nodes' => $section['children']])</ol>@endif
    </li>
@endforeach
