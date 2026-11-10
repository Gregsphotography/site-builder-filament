<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Illuminate\Contracts\Container\Container;
use SiteBuilder\Core\Rendering\DocumentRenderer;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PublicAssets;
use SiteBuilder\Core\Rendering\RenderContext;

/** Render only code-owned examples; the optional components package is not required. */
final readonly class ComponentExamples
{
    private const EXAMPLES = 'SiteBuilder\\Components\\Examples\\StandardExamples';

    private const THEMES = 'SiteBuilder\\Components\\Themes\\ThemeCatalog';

    public function __construct(private Container $container) {}

    /** @return array{html: ?string, note: ?string}|null */
    public function for(string $type): ?array
    {
        if (! $this->container->bound(self::EXAMPLES)) {
            return null;
        }

        $exampleType = match ($type) {
            'standard.column' => 'standard.columns',
            'standard.tab-panel' => 'standard.tabs',
            'standard.accordion-item' => 'standard.accordion',
            'demo.hero' => 'standard.hero',
            'demo.text' => 'standard.text',
            'demo.image-text' => 'standard.image-text',
            'demo.cta' => 'standard.cta',
            default => $type,
        };
        if (in_array($type, ['standard.content-listing', 'standard.search-box', 'standard.search-results'], true)) {
            return ['html' => null, 'note' => match ($type) {
                'standard.content-listing' => 'This listing uses a registered published content source. Configure discovery storage and publish some content to see real results.',
                'standard.search-box' => 'Choose a published results page. Visitors can use live suggestions or submit a normal search.',
                default => 'Results come from published content in the current language. Add this component to your search results page.',
            }];
        }
        $examples = $this->container->make(self::EXAMPLES);
        $entry = $examples->entries()[$exampleType] ?? null;
        if ($entry === null) {
            return null;
        }

        // These components need a real page or are intentionally invisible.
        $contextNote = match ($type) {
            'standard.anchor' => 'A named anchor is invisible on the page. It gives links a specific destination.',
            'standard.breadcrumbs' => 'Breadcrumbs are generated from the current page and its published ancestors.',
            'standard.table-of-contents' => 'The table of contents is generated from the headings on the page.',
            default => null,
        };
        if ($contextNote !== null) {
            return ['html' => null, 'note' => $contextNote];
        }

        // A split hero shows both its image and text in the compact preview.
        $variant = $exampleType === 'standard.hero' && isset($entry['variants']['split']) ? 'split' : null;

        $locale = $this->container->make(LocaleCatalog::class)->default();
        $html = $this->container->make(PublicAssets::class)->scope(function () use ($examples, $exampleType, $locale, $variant): string {
            $document = $examples->document($exampleType, $locale, $variant);
            $result = $this->container->make(DocumentRenderer::class)->render($document, new RenderContext($locale, 'preview', layout: 'components.minimal'));
            if ((string) $result->html === '') {
                return '';
            }

            $styleUrl = $this->container->bound(self::THEMES) ? $this->container->make(self::THEMES)->assetUrl() : null;

            return view('site-builder-filament::pages.component-example', ['locale' => $locale, 'styleUrl' => $styleUrl, 'content' => $result->html])->render();
        });
        if ($html === '') {
            return null;
        }

        $note = match (true) {
            $type === 'standard.column' => 'A column is shown here within a columns layout.',
            $type === 'standard.tab-panel' => 'A tab panel is shown here within a tabs layout.',
            $type === 'standard.accordion-item' => 'An accordion item is shown here within an accordion.',
            $type !== $exampleType => 'Example of the equivalent standard component; this component may look different.',
            $variant === 'split' => 'Shown in the split style.',
            default => null,
        };

        return ['html' => $html, 'note' => $note];
    }
}
