<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Http;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\PageLocks;

final class ReleaseLease
{
    public function __invoke(Request $request, string $page, PageLocks $locks): Response
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        abort_unless(ctype_digit($page), 404);
        try {
            $locks->release(Filament::auth()->user() ?? abort(403), (int) $page, $data['token']);
        } catch (WriteConflict) {
            return response('', 409, ['Cache-Control' => 'private, no-store']);
        }

        return response('', 204, ['Cache-Control' => 'private, no-store']);
    }
}
