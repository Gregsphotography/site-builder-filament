<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Filament\Facades\Filament;

/** No runtime dependency on the optional standard component pack. */
final class OptionalForms
{
    public const PROFILE = 'SiteBuilder\\Components\\Forms\\NotificationProfile';

    public const SUBMISSION = 'SiteBuilder\\Components\\Forms\\Submission';

    public const NOTIFICATION = 'SiteBuilder\\Components\\Forms\\FormNotification';

    public function runtime(): ?object
    {
        $class = 'SiteBuilder\\Components\\Forms\\FormRuntime';

        return app()->bound($class) ? app($class) : null;
    }

    public function available(): bool
    {
        return $this->runtime()?->available() === true;
    }

    public function profiles(): object
    {
        return app('SiteBuilder\\Components\\Forms\\Profiles');
    }

    public function outbox(): object
    {
        return app('SiteBuilder\\Components\\Forms\\NotificationOutbox');
    }

    public function profileOptions(): array
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && $this->available() ? $this->profiles()->options($actor) : [];
    }
}
