<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Models\Page;

final class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $slug = 'pages';

    public static function canViewAny(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->user() !== null && Gate::forUser(Filament::auth()->user())->allows('viewAny', Page::class);
    }

    public static function canCreate(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->user() !== null && Gate::forUser(Filament::auth()->user())->allows('create', Page::class);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('updated_at')->dateTime(),
        ])->recordUrl(fn (Page $record): ?string => Gate::forUser(Filament::auth()->user())->allows('view', $record) ? self::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([Action::make('edit')->label('Open editor')->url(fn (Page $record): string => self::getUrl('edit', ['record' => $record]))->visible(fn (Page $record): bool => Gate::forUser(Filament::auth()->user())->allows('view', $record))])->toolbarActions([])
            ->emptyStateHeading('Your pages will appear here')
            ->emptyStateDescription('Create a page, add your content and publish when you are ready.');
    }

    public static function getPages(): array
    {
        return ['index' => ListPages::route('/'), 'create' => CreatePage::route('/create'), 'edit' => PageEditor::route('/{record}/edit')];
    }
}
