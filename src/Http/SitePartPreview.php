<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Http;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteBuilder\Core\SiteParts\SitePartResponse;

final class SitePartPreview
{
    public function __invoke(Request $request, string $part, SitePartResponse $response): Response
    {
        $data = $request->validate(['locale' => ['required', 'string', 'max:12'], 'revision' => ['nullable', 'uuid']]);

        return $response->preview(Filament::auth()->user() ?? abort(403), $part, $data['locale'], $data['revision'] ?? null);
    }
}
