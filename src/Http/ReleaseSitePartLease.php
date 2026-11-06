<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Http;

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SiteParts\SitePartLocks;

final class ReleaseSitePartLease
{
    public function __invoke(Request $request, string $part, SitePartLocks $locks): Response
    {
        $data = $request->validate(['token' => ['required', 'uuid'], 'targets' => ['sometimes', 'array', 'max:20'], 'targets.*.pageId' => ['required', 'integer', 'min:1'], 'targets.*.token' => ['required', 'uuid']]);
        $actor = Filament::auth()->user() ?? abort(403);
        foreach ($data['targets'] ?? [] as $target) {
            try {
                app(PageLocks::class)->release($actor, (int) $target['pageId'], $target['token']);
            } catch (WriteConflict|AuthorizationException|ModelNotFoundException) {
            }
        }
        try {
            $locks->release($actor, $part, $data['token']);
        } catch (WriteConflict) {
            return response('', 409, ['Cache-Control' => 'private, no-store']);
        }

        return response('', 204, ['Cache-Control' => 'private, no-store']);
    }
}
