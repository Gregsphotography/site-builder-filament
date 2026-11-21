<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Support;

use Dom\HTMLDocument;
use Illuminate\Support\Str;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Rendering\ContentOccurrences;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;

final class DraftAccessibility
{
    public function __construct(private ContentOccurrences $content, private ComponentRegistry $components) {}

    public function review(Document $document, RenderContext $context): array
    {
        if (! app(RenderFeatureRegistry::class)->renderable($document, null, $context)) {
            throw new \InvalidArgumentException('Resolve the unavailable draft content before reviewing accessibility.');
        }

        $findings = [];
        foreach ($this->content->visible($document, $context, regions: true) as $index => $entry) {
            $node = $entry['node'];
            $component = $this->components->resolve($node->type);
            $props = $entry['props'];
            // Dynamic collections obtain their rows from another source. Their
            // retained manual rows do not appear in the authored draft.
            if (($props['content_mode'] ?? null) === 'dynamic') {
                unset($props['items']);
            } elseif ($node->type === 'standard.testimonials' && ($props['variant'] ?? '') === 'single') {
                $props['items'] = array_slice($props['items'] ?? [], 0, 1);
            }
            $variant = $props['variant'] ?? '';
            $testimonialSlides = $node->type === 'standard.carousel' && $variant === 'testimonials';
            $linkedLogos = $node->type === 'standard.cards' && $variant === 'logos';
            if ($testimonialSlides || $linkedLogos) {
                foreach ($props['items'] ?? [] as $indexInList => $item) {
                    $item = (array) $item;
                    // Testimonial slides have no destination link. Logo cards
                    // with headings already expose their destination by name.
                    if ($testimonialSlides || filled($item['heading'] ?? null)) {
                        unset($item['url'], $item['page']);
                        $props['items'][$indexInList] = $item;
                    }
                }
            }
            $showImages = $node->type !== 'standard.timeline' || ($props['show_image'] ?? true);
            $section = Str::headline(Str::afterLast($node->type, '.')).' · Block '.($index + 1);
            foreach ($this->fields($component->properties(), $props, [], $showImages) as $finding) {
                $findings[] = $finding + ['section' => $section, 'node' => $node->id, 'sharedPart' => $entry['sourcePartId'], 'mounted' => $entry['mounted']];
            }
        }

        return $findings;
    }

    /** Walk registered authoring fields, including nested links and repeaters. */
    private function fields(array $properties, array $values, array $path, bool $showImages): iterable
    {
        foreach ($properties as $key => $property) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $field = [...$path, $key];
            if ($property->kind === 'html' && is_string($value)) {
                yield from $this->markup($value, $field);
            } elseif ($property->kind === 'object') {
                yield from $this->fields($property->properties, (array) $value, $field, $showImages);
            } elseif ($property->kind === 'list' && $property->items->kind === 'object') {
                foreach ($value as $index => $item) {
                    yield from $this->fields($property->items->properties, (array) $item, [...$field, $index], $showImages);
                }
            } elseif ($property->kind === 'media' && $property->mediaKind === 'image' && $showImages && filled($value) && ! ($values['decorative'] ?? false)) {
                $alt = match ($key) {
                    'image', 'portrait' => 'alt',
                    default => Str::beforeLast($key, '_image').'_alt',
                };
                if (isset($properties[$alt]) && blank($values[$alt] ?? null)) {
                    yield ['path' => [...$path, $alt], 'message' => isset($properties['decorative']) ? 'Describe this image or mark it decorative.' : 'Add alternative text for this image.'];
                }
            }
        }

        // A stored destination without its caption can become an unnamed link
        // or disappear entirely, depending on the installed component template.
        foreach (['', 'secondary_', 'view_all_'] as $prefix) {
            if (! isset($properties[$prefix.'url']) || (! filled($values[$prefix.'url'] ?? null) && ! filled($values[$prefix.'page'] ?? null) && ! filled($values[$prefix.'overlay'] ?? null))) {
                continue;
            }
            $label = $prefix.'label';
            if ($prefix === 'view_all_' && ! isset($values[$label])) {
                continue; // Listing components supply a translated default caption.
            }
            if ($prefix === '' && isset($properties['link_label'])) {
                $label = 'link_label';
                if (filled($values['title'] ?? null)) {
                    continue;
                }
            }
            if ($prefix === '' && isset($properties['value']) && isset($values['value'])) {
                $label = 'value';
            }
            if ($prefix === '' && $path !== [] && ! isset($properties[$label]) && isset($properties['title'])) {
                $label = 'title';
            }
            if (isset($properties[$label]) && blank($values[$label] ?? null)) {
                yield ['path' => [...$path, $label], 'message' => 'Give this link a label that describes its destination.'];
            }
        }
    }

    private function markup(string $html, array $field): iterable
    {
        if (stripos($html, '<a') === false && stripos($html, '<img') === false) {
            return;
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        foreach ($document->getElementsByTagName('img') as $index => $image) {
            // In authored HTML, alt="" is the standard explicit decorative choice.
            if ($image->hasAttribute('src') && ! $image->hasAttribute('alt')) {
                yield ['path' => $field, 'message' => 'Add alternative text to image '.($index + 1).' in this rich text, or mark it decorative.'];
            }
        }
        foreach ($document->getElementsByTagName('a') as $index => $anchor) {
            if (! $anchor->hasAttribute('href') || filled(Str::squish($anchor->textContent)) || filled($anchor->getAttribute('title')) || filled($anchor->getAttribute('aria-label'))) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($anchor->getAttribute('aria-labelledby') ?? ''), flags: PREG_SPLIT_NO_EMPTY) as $id) {
                if (filled(Str::squish($document->getElementById($id)?->textContent ?? ''))) {
                    continue 2;
                }
            }
            foreach ($anchor->getElementsByTagName('img') as $image) {
                if (filled($image->getAttribute('alt'))) {
                    continue 2;
                }
            }

            yield ['path' => $field, 'message' => 'Give link '.($index + 1).' in this rich text a name.'];
        }
    }
}
