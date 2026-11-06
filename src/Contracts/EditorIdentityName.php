<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

/** Optional colleague labels for opaque, provider-qualified account identifiers. */
interface EditorIdentityName
{
    public function forActor(string $userId, ?string $userType): ?string;
}
