<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Support\ComponentExamples;

final class ComponentLibrary extends Page
{
    protected static ?string $navigationLabel = 'Component library';

    protected string $view = 'site-builder-filament::pages.component-library';

    #[Locked]
    public string $exampleType = '';

    #[Locked]
    public string $exampleLabel = '';

    #[Locked]
    public ?string $exampleHtml = null;

    #[Locked]
    public ?string $exampleNote = null;

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel() !== null && Filament::auth()->user() !== null && Gate::forUser(Filament::auth()->user())->allows('viewAny', ContentPage::class);
    }

    public function getTitle(): string
    {
        return 'Component library';
    }

    public function componentForms(): array
    {
        return app(ComponentFormRegistry::class)->all();
    }

    public function showExample(string $type): void
    {
        abort_unless(self::canAccess(), 403);
        $form = $this->componentForms()[$type] ?? abort(404);
        $example = app(ComponentExamples::class)->for($type);

        $this->exampleType = $type;
        $this->exampleLabel = $form->label();
        $this->exampleHtml = $example['html'] ?? null;
        $this->exampleNote = $example['note'] ?? null;

        $this->dispatch('open-modal', id: 'sb-component-example');
    }
}
