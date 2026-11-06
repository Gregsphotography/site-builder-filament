<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

/** Hosts may expose an authorized colleague label without coupling core leases to their user model. */
interface EditorName
{
    public function forUser(int $userId): ?string;
}
