<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartPayload;

final class CreateSitePart extends Page
{
    protected static string $resource = SitePartResource::class;

    protected string $view = 'site-builder-filament::site-parts.create';

    public array $data = [];

    public function mount(): void
    {
        abort_unless(SitePartResource::canCreate(), 403);
        $this->form->fill(request()->query('kind') === 'overlay' ? ['kind' => 'overlay'] : []);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $actor = Filament::auth()->user() ?? abort(403);
        $kinds = array_filter(['section' => 'Reusable section', 'header' => 'Site header', 'footer' => 'Site footer', 'design' => 'Site design defaults', 'overlay' => 'Popup or drawer'], fn (string $label, string $kind): bool => Gate::forUser($actor)->allows('create', [SitePart::class, $kind]), ARRAY_FILTER_USE_BOTH);
        $themes = array_filter(app(DesignCatalog::class)->themes($this->context()), fn (array $theme): bool => $theme['available']);

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->rows(3),
            Select::make('kind')->options($kinds)->required()->live(),
            Select::make('theme')->label('Initial design theme and preset')->options(array_column($themes, 'label', 'key'))->visible(fn (): bool => ($this->data['kind'] ?? null) === 'design')
                ->helperText('Design resources need an available registered theme in the configured site-part preview layout.'),
        ])->statePath('data');
    }

    public function create(): void
    {
        $actor = Filament::auth()->user() ?? abort(403);
        abort_unless(app(SitePartCatalog::class)->available(), 404);
        $data = $this->form->getState();
        $payload = null;
        if ($data['kind'] === 'design') {
            $theme = array_find(app(DesignCatalog::class)->themes($this->context()), fn (array $theme): bool => $theme['available'] && $theme['key'] === ($data['theme'] ?? null));
            if ($theme === null) {
                throw ValidationException::withMessages(['data.theme' => 'Choose an available registered theme for these site design defaults.']);
            }
            $payload = SitePartPayload::design(new ThemeIdentity($theme['id'], $theme['version'], $theme['preset']));
        }
        $result = app(SitePartCommands::class)->create($actor, $data['kind'], ['name' => $data['name'], 'description' => $data['description'] ?? null], $payload);
        $this->redirect(SitePartResource::getUrl('edit', ['record' => $result->part->id]));
    }

    private function context(): RenderContext
    {
        return new RenderContext(config('app.locale', 'en'), 'preview', layout: config('site-builder.site_parts.preview_layout') ?? config('site-builder.layout'));
    }
}
