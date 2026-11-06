<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;

final class SitePartResource extends Resource
{
    protected static ?string $model = SitePart::class;

    protected static ?string $slug = 'site-parts';

    public static function canViewAny(): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && app(SitePartCatalog::class)->available() && Gate::forUser($actor)->allows('viewAny', SitePart::class);
    }

    public static function canCreate(): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && app(SitePartCatalog::class)->available() && array_any(['section', 'header', 'footer', 'design'], fn (string $kind): bool => Gate::forUser($actor)->allows('create', [SitePart::class, $kind]));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(), TextColumn::make('kind')->badge(),
            TextColumn::make('write_version')->label('Draft version'), TextColumn::make('updated_at')->dateTime(),
        ])->recordUrl(fn (SitePart $record): ?string => Gate::forUser(Filament::auth()->user())->allows('view', $record) ? self::getUrl('edit', ['record' => $record->id]) : null)
            ->recordActions([Action::make('edit')->label('Open site-part editor')->url(fn (SitePart $record): string => self::getUrl('edit', ['record' => $record->id]))->visible(fn (SitePart $record): bool => Gate::forUser(Filament::auth()->user())->allows('view', $record))])
            ->toolbarActions([])->emptyStateHeading('Reusable site parts')->emptyStateDescription('Publish an independent section, header, footer or site design, then explicitly pin it in page drafts.');
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteParts::route('/'), 'create' => CreateSitePart::route('/create'), 'edit' => SitePartEditor::route('/{record}/edit')];
    }
}
