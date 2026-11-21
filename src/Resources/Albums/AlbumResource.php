<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Albums;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use SiteBuilder\Components\Albums\Album;
use SiteBuilder\Components\Albums\AlbumLibrary;
use SiteBuilder\Components\Media\MediaAsset;
use SiteBuilder\Components\Media\MediaLibrary;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Filament\Pages\MediaLibrary as MediaLibraryPage;

final class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static ?string $slug = 'albums';

    public static function canViewAny(): bool
    {
        return MediaLibraryPage::canAccess() && app(AlbumLibrary::class)->available()
            && Gate::forUser(Filament::auth()->user())->allows('viewAny', Album::class);
    }

    public static function canCreate(): bool
    {
        return self::canViewAny() && Gate::forUser(Filament::auth()->user())->allows('create', Album::class);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && Gate::forUser(Filament::auth()->user())->check(['view', 'update'], $record);
    }

    public static function form(Schema $schema): Schema
    {
        $recent = null;
        $recentPhotos = static function () use (&$recent): array {
            return $recent ??= self::photoOptions();
        };

        return $schema->components([
            TextInput::make('title')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, ?string $old, string $operation, Get $get, Set $set): void {
                    if ($operation === 'create' && (blank($get('slug')) || $get('slug') === Str::slug($old ?? ''))) {
                        $set('slug', Str::slug($state ?? ''));
                    }
                }),
            TextInput::make('slug')->label('URL slug')->required()->maxLength(160)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D')->helperText('Use lowercase letters, numbers and hyphens.'),
            self::photoSelect('cover', $recentPhotos)->label('Cover photo')->placeholder('Use the first photo'),
            Repeater::make('items')->label('Photos')->defaultItems(0)->maxItems(48)
                ->helperText('Upload new photos in the Media library. Use the arrows to change their order here.')
                ->reorderableWithButtons()->addActionLabel('Add photo')->schema([
                    self::photoSelect('image', $recentPhotos)->label('Photo')->required()->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $alt = $state === null ? [] : MediaAsset::query()->where('mime', 'like', 'image/%')->where('path', $state)->first()?->alt;
                            $set('alt', $alt[app(LocaleCatalog::class)->default()] ?? '');
                        }),
                    TextInput::make('alt')->label('Alternative text')->maxLength(2000),
                    Toggle::make('decorative')->label('Decorative image')->default(false),
                    TextInput::make('caption')->maxLength(2000),
                ])->columnSpanFull(),
            Select::make('columns')->options(['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4'])->default('three')->required(),
            Toggle::make('lightbox')->label('Open photos in a lightbox')->default(true),
        ]);
    }

    private static function photoSelect(string $field, Closure $recentPhotos): Select
    {
        return Select::make($field)->searchable()->preload()->allowHtml()
            ->options($recentPhotos)
            ->getSearchResultsUsing(fn (string $search): array => self::photoOptions($search))
            ->getOptionLabelUsing(function (string $value): ?string {
                $asset = MediaAsset::query()->where('mime', 'like', 'image/%')->where('path', $value)->first();

                return $asset ? self::photoLabel($asset) : null;
            })->searchPrompt('Search the media library by image name');
    }

    private static function photoOptions(string $search = ''): array
    {
        $query = MediaAsset::query()->where('mime', 'like', 'image/%');
        if ($search === '') {
            $query->latest()->orderBy('id')->limit(20);
        } else {
            $query->whereLike('name', '%'.$search.'%')->orderBy('name')->orderBy('id')->limit(40);
        }

        return $query->get()->mapWithKeys(fn (MediaAsset $asset): array => [$asset->path => self::photoLabel($asset)])->all();
    }

    private static function photoLabel(MediaAsset $asset): string
    {
        return view('site-builder-filament::albums.photo-option', [
            'name' => $asset->name, 'thumbnail' => app(MediaLibrary::class)->url($asset->path),
        ])->render();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->state(fn (Album $record): string => $record->draft['title'])
                ->searchable(['draft->title'])->sortable(['draft->title']),
            TextColumn::make('status')->state(fn (Album $record): string => match (true) {
                $record->published_at === null => 'Draft',
                $record->published_version !== $record->write_version => 'Unpublished changes',
                default => 'Published',
            })->badge(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Album $record): ?string => self::canEdit($record) ? self::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                Action::make('edit')->label('Edit album')->visible(fn (Album $record): bool => self::canEdit($record))
                    ->url(fn (Album $record): string => self::getUrl('edit', ['record' => $record])),
                Action::make('open')->label('View published album')->openUrlInNewTab()
                    ->url(fn (Album $record): ?string => self::publicUrl($record))->visible(fn (Album $record): bool => self::publicUrl($record) !== null),
            ])->emptyStateHeading('No albums yet');
    }

    public static function publicUrl(Album $album): ?string
    {
        return $album->published_slug !== null && Route::has('site-builder.album')
            ? route('site-builder.album', ['slug' => $album->published_slug]) : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListAlbums::route('/'), 'create' => CreateAlbum::route('/create'), 'edit' => EditAlbum::route('/{record}/edit')];
    }
}
