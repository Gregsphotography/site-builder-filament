<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Models\Page as ContentPage;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\SeoAssessment;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Filament\Resources\Pages\PageResource;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;

final class SeoOverview extends Page
{
    protected static ?string $navigationLabel = 'SEO & translations';

    protected static ?string $title = 'SEO & translations';

    protected string $view = 'site-builder-filament::pages.seo-overview';

    public string $language = '';

    public string $publication = '';

    public string $finding = '';

    public int $pageNumber = 1;

    public static function canAccess(): bool
    {
        return PageResource::canViewAny();
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['language', 'publication', 'finding'], true)) {
            $this->pageNumber = 1;
        }
    }

    #[Unexposed]
    public function report(): array
    {
        abort_unless(self::canAccess(), 403);
        $gate = Gate::forUser(Filament::auth()->user());
        $locales = app(LocaleCatalog::class);
        $language = $locales->has($this->language) ? $this->language : null;
        $pages = PageResource::getEloquentQuery()->with('slugs');
        if (in_array($this->publication, ['published', 'draft'], true)) {
            $pages->where('status', $this->publication);
        }
        $rows = app(SeoAssessment::class)->rows(
            $pages->lazyById(100)->filter(fn (ContentPage $page) => $gate->allows('view', $page)),
            $language,
        );
        $rows = array_values(array_filter($rows, fn (array $row): bool => match ($this->finding) {
            '' => true,
            'clear' => $row['issues'] === [],
            'shared_title' => $row['usesPageTitle'],
            default => in_array($this->finding, array_column($row['issues'], 'code'), true),
        }));
        $last = max(1, (int) ceil(count($rows) / 20));
        $current = max(1, min($last, $this->pageNumber));

        $visible = array_slice($rows, ($current - 1) * 20, 20);
        $partIds = collect($visible)->pluck('issues')->collapse()->pluck('sharedPart')->filter()->unique();
        $parts = $partIds->isEmpty() ? collect() : SitePart::findMany($partIds)->filter(fn (SitePart $part): bool => $gate->allows('view', $part))->keyBy('id');
        foreach ($visible as &$row) {
            foreach ($row['issues'] as &$issue) {
                $partId = $issue['sharedPart'] ?? null;
                $issue['url'] = $partId && $parts->has($partId)
                    ? SitePartResource::getUrl('edit', ['record' => $partId, 'locale' => $row['locale'], 'node' => $issue['node'], 'field' => $issue['field']])
                    : PageResource::getUrl('edit', ['record' => $row['id'], 'locale' => $row['locale']] + $issue['target']);
            }
            unset($issue);
        }
        unset($row);

        return ['rows' => $visible, 'total' => count($rows), 'current' => $current, 'last' => $last];
    }
}
