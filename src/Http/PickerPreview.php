<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Http;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Filament\Composition\PickerCatalog;
use SiteBuilder\Filament\Composition\PickerPreviewTickets;

final class PickerPreview
{
    public function __invoke(Request $request, int $page, PickerCatalog $catalog, PickerPreviewTickets $tickets, CompositionCommands $composition, PageResponse $response): Response
    {
        $actor = Filament::auth()->user() ?? abort(403);
        try {
            $data = $request->validate([
                'entry' => ['required', 'string', 'max:255'], 'kind' => ['required', 'string', 'max:40'],
                'source' => ['required', 'string', 'max:255'], 'sourceVersion' => ['required', 'string', 'max:40'],
                'digest' => ['required', 'string', 'size:64'], 'locale' => ['required', 'string', 'max:12'],
                'variant' => ['nullable', 'string', 'max:100'],
                'layoutDigest' => ['required', 'string', 'size:64'],
                'ticket' => ['required', 'uuid'],
                'pageVersion' => ['required', 'string', 'regex:/^[0-9]{1,18}$/D'],
                'position' => ['required', 'in:start,end,before,after'], 'anchor' => ['nullable', 'string', 'max:191'], 'parentId' => ['nullable', 'string', 'max:191'],
            ]);
        } catch (ValidationException) {
            return $this->unavailable();
        }
        $ticket = $tickets->read($actor, $page, $data['ticket'], $data);
        if ($ticket === null) {
            return $this->unavailable();
        }
        $destination = Page::query()->findOrFail($page);
        Gate::forUser($actor)->authorize('preview', $destination);
        if ((string) $destination->write_version !== $data['pageVersion']) {
            return $this->unavailable();
        }
        try {
            $entry = $catalog->find($actor, $data['entry'], $data['locale'], $destination, ($data['parentId'] ?? null) === null ? TreeSlot::root() : TreeSlot::children($data['parentId']));
        } catch (ValidationException|InvalidArgumentException) {
            return $this->unavailable();
        }
        if (! $entry['previewAvailable'] || $entry['id'] !== $data['source'] || $entry['kind'] !== $data['kind']) {
            return $this->unavailable();
        }
        if (($data['variant'] ?? null) === null ? ! $entry['defaultVariantAvailable'] : ! array_key_exists($data['variant'], $entry['variants'])) {
            return $this->unavailable();
        }
        if (! hash_equals($data['layoutDigest'], $catalog->layoutDigest($destination))) {
            return $this->unavailable();
        }
        try {
            $preview = $composition->previewContext($actor, $page, new SourceSelection($data['kind'], $data['source'], $data['sourceVersion'], $data['digest'], $data['locale'], $data['variant'] ?? null), ReferenceMap::fromArray($ticket['map']), InsertPosition::in(($data['parentId'] ?? null) === null ? TreeSlot::root() : TreeSlot::children($data['parentId']), match ($data['position']) {
                'start' => TreePosition::start(), 'end' => TreePosition::end(),
                'before' => TreePosition::before($data['anchor'] ?? ''), 'after' => TreePosition::after($data['anchor'] ?? ''),
            }));
        } catch (WriteConflict|InvalidArgumentException|ValidationException) {
            return $this->unavailable();
        }

        return $response->previewDocument($actor, $page, $preview->document, $data['locale'], $preview->rootIds);
    }

    private function unavailable(): Response
    {
        return response('<!doctype html><html><head><title>Preview unavailable</title></head><body><p>This starting point changed or is unavailable. Review its current version before continuing.</p></body></html>', 409, PageResponse::privateHeaders());
    }
}
