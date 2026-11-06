<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Contracts;

use Filament\Schemas\Components\Component;
use SiteBuilder\Filament\Forms\EditorContext;

interface ComponentForm
{
    public function type(): string;

    public function label(): string;

    public function icon(): string;

    public function category(): string;

    /** @return array<Component> */
    public function schema(EditorContext $context): array;
}
