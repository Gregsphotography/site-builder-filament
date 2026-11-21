<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Routing\ManagedRedirects;
use SiteBuilder\Core\Routing\MissingPaths;
use SiteBuilder\Core\Routing\RedirectPath;
use SiteBuilder\Filament\Resources\Pages\PageResource;

final class RedirectManager extends Page
{
    protected static ?string $navigationLabel = 'Redirects & 404s';

    protected static ?string $title = 'Redirects & 404s';

    protected string $view = 'site-builder-filament::pages.redirect-manager';

    #[Locked]
    public ?int $editing = null;

    #[Locked]
    public ?string $version = null;

    public string $locale = '';

    public string $source = '';

    public string $targetSearch = '';

    public ?int $target = null;

    public int $status = 301;

    public int $rulesPage = 1;

    public int $missingPage = 1;

    public static function canAccess(): bool
    {
        return PageResource::canViewAny()
            && Gate::forUser(Filament::auth()->user())->allows(ManagedRedirects::GATE)
            && in_array(self::class, Filament::getCurrentPanel()->getPages(), true);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->locale = app(LocaleCatalog::class)->default();
    }

    public function updatedLocale(): void
    {
        $this->target = null;
        $this->targetSearch = '';
    }

    public function newRule(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->editing = null;
        $this->version = null;
        $this->target = null;
        $this->source = $this->targetSearch = '';
        $this->status = 301;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        abort_unless(self::canAccess(), 403);
        $rule = PageRedirect::findOrFail($id);
        $this->authorizeTarget($rule->page_id, allowTrash: true);
        $this->editing = $rule->id;
        $this->version = ManagedRedirects::version($rule);
        $this->source = $rule->from_slug;
        $this->locale = $rule->locale;
        $this->target = $rule->page_id;
        $this->targetSearch = '';
        $this->status = $rule->status;
        $this->resetValidation();
        $this->dispatch('redirect-form-opened');
    }

    public function resolveMissing(int $id): void
    {
        $this->newRule();
        abort_unless(app(MissingPaths::class)->available(), 404);
        $missing = DB::table('site_builder_missing_paths')->find($id) ?? abort(404);
        $this->locale = $missing->locale;
        $this->source = $missing->slug;
        $this->dispatch('redirect-form-opened');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->validate(['target' => ['required', 'integer'], 'source' => ['required', 'string', 'max:255']]);
        $this->authorizeTarget($this->target);
        if ($this->editing !== null) {
            $rule = PageRedirect::findOrFail($this->editing);
            $this->authorizeTarget($rule->page_id, allowTrash: true);
        }
        app(ManagedRedirects::class)->save(Filament::auth()->user(), $this->locale, $this->source, $this->target, $this->status, $this->editing, $this->version);
        $this->newRule();
        $this->rulesPage = 1;
        Notification::make()->title('Redirect saved')->success()->send();
    }

    public function remove(): void
    {
        abort_unless(self::canAccess() && $this->editing !== null && $this->version !== null, 403);
        $rule = PageRedirect::findOrFail($this->editing);
        $this->authorizeTarget($rule->page_id, allowTrash: true);
        app(ManagedRedirects::class)->remove(Filament::auth()->user(), $this->editing, $this->version);
        $this->newRule();
        $this->rulesPage = 1;
        Notification::make()->title('Redirect removed')->success()->send();
    }

    private function authorizeTarget(int $id, bool $allowTrash = false): void
    {
        $query = PageResource::getEloquentQuery();
        $page = ($allowTrash ? $query->withoutGlobalScope('active') : $query)->findOrFail($id);
        $gate = Gate::forUser(Filament::auth()->user());
        $gate->authorize('view', $page);
        $gate->authorize('publish', $page);
    }

    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        $gate = Gate::forUser(Filament::auth()->user());
        $misses = app(MissingPaths::class);
        $available = $misses->available();
        $missing = collect();
        if ($available) {
            $misses->prune();
            $missing = DB::table('site_builder_missing_paths')->orderByDesc('hits')->orderByDesc('last_seen_at')->orderBy('id')
                ->offset((min(500000, max(1, $this->missingPage)) - 1) * 20)->limit(21)->get();
        }
        $rules = PageRedirect::query()->whereIn('page_id', PageResource::getEloquentQuery()->withoutGlobalScope('active')->select('id'))
            ->with(['page' => fn ($query) => $query->withoutGlobalScope('active')->with('slugs')])->lazyByIdDesc(100)
            ->filter(fn (PageRedirect $rule): bool => $rule->page !== null && $gate->allows('view', $rule->page))
            ->skip((min(500000, max(1, $this->rulesPage)) - 1) * 20)->take(21)->collect();
        $targets = PageResource::getEloquentQuery()->where('status', 'published')->whereHas('slugs', fn ($query) => $query->where('locale', $this->locale));
        $search = trim(mb_substr($this->targetSearch, 0, 120));
        if ($search !== '') {
            $targets->where('title', 'like', '%'.$search.'%');
        }
        $options = $targets->lazyById(50)->filter(fn (ContentPage $page): bool => $gate->allows('view', $page) && $gate->allows('publish', $page))->take(25)->collect()->keyBy('id');
        if ($this->target !== null && ! $options->has($this->target)) {
            $selected = PageResource::getEloquentQuery()->where('status', 'published')->whereHas('slugs', fn ($query) => $query->where('locale', $this->locale))->find($this->target);
            if ($selected && $gate->allows('view', $selected) && $gate->allows('publish', $selected)) {
                $options->put($selected->id, $selected);
            }
        }

        return ['available' => $available, 'missing' => $missing, 'rules' => $rules, 'options' => $options, 'gate' => $gate, 'locales' => app(LocaleCatalog::class)->all(), 'displayPath' => RedirectPath::forDisplay(...)];
    }
}
