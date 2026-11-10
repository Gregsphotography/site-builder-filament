<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Filament\Support\OptionalComponents;

final class CreatePage extends Page
{
    protected static string $resource = PageResource::class;

    protected string $view = 'site-builder-filament::pages.create';

    public array $data = [];

    public function mount(): void
    {
        Gate::forUser(Filament::auth()->user() ?? abort(403))->authorize('create', ContentPage::class);
        $this->form->fill();
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $keys = app(LayoutRegistry::class)->keys();
        $templates = app(OptionalComponents::class)->templateOptions(app(LocaleCatalog::class)->default());

        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->label('URL path ('.app(LocaleCatalog::class)->default().')')->required()->maxLength(255)->helperText('For example: about or guides/getting-started. Use home for the root.'),
            Select::make('layout')->options(array_combine($keys, $keys))->placeholder('Default layout'),
            Select::make('template')->label('Start with a page template')->options($templates)->placeholder('Blank page')
                ->helperText('Copies a starting point into this new draft. Edit every section independently.')->visible($templates !== []),
        ])->statePath('data');
    }

    public function create(): void
    {
        $actor = Filament::auth()->user() ?? abort(403);
        $data = $this->form->getState();
        $locale = app(LocaleCatalog::class)->default();
        $template = $data['template'] ?? null;
        $components = app(OptionalComponents::class);
        if ($template !== null && $template !== '' && ! array_key_exists($template, $components->templateOptions($locale))) {
            throw ValidationException::withMessages(['data.template' => 'Choose an available page template.']);
        }
        $document = $template ? $components->template($template, $locale) : null;
        try {
            $result = app(PageCommands::class)->create($actor, ['title' => $data['title'], 'layout' => $data['layout'] ?: null], [$locale => $data['slug']], $document);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['slugs'])) {
                $errors['data.slug'] = $errors['slugs'];
                unset($errors['slugs']);
            }
            throw ValidationException::withMessages($errors);
        }
        $this->redirect(PageResource::getUrl('edit', ['record' => $result->page->id]));
    }
}
