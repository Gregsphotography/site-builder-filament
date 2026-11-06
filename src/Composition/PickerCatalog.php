<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Composition\StartingPointDescriptor;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\HasDefaultChildren;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;

/** Presentation metadata augments existing host forms without changing their contract. */
final class PickerCatalog
{
    private array $withoutPreview = [];

    public function __construct(private StartingPointRegistry $sources, private ComponentRegistry $components, private ComponentFormRegistry $forms, private SavedSectionCommands $saved) {}

    public function entries(Authenticatable $actor, string $locale): array
    {
        return $this->page($actor, $locale)['entries'];
    }

    public function page(Authenticatable $actor, string $locale, ?string $savedSearch = null, ?string $savedCategory = null, ?string $cursor = null, ?Page $destination = null, ?TreeSlot $slot = null): array
    {
        $this->registerHostDefaults();
        $entries = [];
        foreach ($this->sources->all() as $descriptor) {
            $variants = array_is_list($descriptor->variants) ? array_combine($descriptor->variants, array_map(Str::headline(...), $descriptor->variants)) : $descriptor->variants;
            $defaultAvailable = true;
            $diagnostic = '';
            $type = str_starts_with($descriptor->id, 'component:') ? substr($descriptor->id, 10) : null;
            if ($destination !== null && $type !== null && ($component = $this->components->resolve($type)) !== null) {
                $context = new RenderContext($locale, 'preview', $destination->id, $destination->layout);
                $document = $destination->draft_content ?? new Document;
                $permitted = app(DesignCatalog::class)->permittedVariants($document, $context, $type);
                if ($permitted !== null) {
                    $theme = app(DesignResolver::class)->theme($document, $context);
                    $definition = $theme === null ? null : app(DesignCatalog::class)->definition($theme);
                    $default = $component->defaults()->props->get('variant');
                    $defaultAvailable = $definition !== null && $definition->available($context) && $definition->supports($type, $component->version(), is_string($default) ? $default : null);
                    $variantProperty = $component->properties()['variant'] ?? null;
                    $filtered = $variantProperty?->kind === 'enum' ? array_intersect_key($variants, array_fill_keys($permitted, true)) : ($defaultAvailable ? $variants : []);
                    if ($filtered !== $variants || ! $defaultAvailable) {
                        $diagnostic = $filtered === [] ? 'This component has no compatible choice in the current canvas theme.' : 'Only variants supported by the current canvas theme are offered.';
                    }
                    $variants = $filtered;
                }
            }
            $placementAvailable = true;
            if ($destination !== null) {
                $context = new RenderContext($locale, 'preview', $destination->id, $destination->layout);
                $document = $destination->draft_content ?? new Document;
                if ($type !== null && $this->components->resolve($type) !== null) {
                    $defaultAvailable = $defaultAvailable && app(TreeOperations::class)->canInsertType($document, $type, $slot ?? TreeSlot::root(), $context);
                    $variants = array_filter($variants, fn (string $label, string $variant): bool => app(TreeOperations::class)->canInsertType($document, $type, $slot ?? TreeSlot::root(), $context, $variant), ARRAY_FILTER_USE_BOTH);
                    $placementAvailable = $defaultAvailable || $variants !== [];
                } else {
                    try {
                        $source = $this->sources->snapshot($descriptor->id, $descriptor->version, $locale);
                        $placementAvailable = app(TreeOperations::class)->canInsertNodes($document, $source->document->nodes, $slot ?? TreeSlot::root(), $context);
                    } catch (\InvalidArgumentException|ValidationException) {
                        $placementAvailable = false;
                    }
                }
                if (! $placementAvailable) {
                    $diagnostic = 'This starting point does not fit the selected parent, cardinality, depth or canvas requirements.';
                }
            }
            $entries[] = [
                'key' => $descriptor->id, 'id' => $descriptor->id, 'kind' => $descriptor->sourceKind,
                'version' => (string) $descriptor->version, 'label' => __($descriptor->label, [], $locale),
                'description' => __($descriptor->description, [], $locale), 'category' => __($descriptor->category, [], $locale),
                'keywords' => $descriptor->keywords, 'variants' => $variants, 'defaultVariantAvailable' => $defaultAvailable,
                'variantDiagnostic' => $diagnostic, 'insertable' => $placementAvailable && ($defaultAvailable || $variants !== []),
                'previewAvailable' => ! isset($this->withoutPreview[$descriptor->id]),
                'legacy' => str_starts_with($descriptor->id, 'component:demo.'),
            ];
        }
        $nextCursor = null;
        if ($this->saved->available()) {
            $page = $this->saved->searchPage($actor, $savedSearch, $savedCategory, $cursor);
            $nextCursor = $page['nextCursor'];
            foreach ($page['entries'] as $section) {
                if (! Gate::forUser($actor)->allows('use', $section)) {
                    continue;
                }
                $entry = $this->savedEntry($section);
                if ($destination !== null) {
                    try {
                        $snapshot = $this->saved->snapshot($actor, $section->id, $section->current_version);
                        $entry['insertable'] = app(TreeOperations::class)->canInsertNodes($destination->draft_content ?? new Document, $snapshot->document->nodes, $slot ?? TreeSlot::root(), new RenderContext($locale, 'preview', $destination->id, $destination->layout));
                    } catch (\InvalidArgumentException|ValidationException) {
                        $entry['insertable'] = false;
                    }
                    if (! $entry['insertable']) {
                        $entry['variantDiagnostic'] = 'This saved section does not fit the selected parent or current canvas requirements.';
                    }
                }
                $entries[] = $entry;
            }
        }

        return ['entries' => $entries, 'nextCursor' => $nextCursor];
    }

    public function find(Authenticatable $actor, string $key, string $locale, ?Page $destination = null, ?TreeSlot $slot = null): array
    {
        if (str_starts_with($key, 'saved:') && $this->saved->available()) {
            $section = $this->saved->find($actor, substr($key, 6));
            if ($section !== null && Gate::forUser($actor)->allows('use', $section)) {
                $entry = $this->savedEntry($section);
                if ($destination !== null) {
                    $snapshot = $this->saved->snapshot($actor, $section->id, $section->current_version);
                    $entry['insertable'] = app(TreeOperations::class)->canInsertNodes($destination->draft_content ?? new Document, $snapshot->document->nodes, $slot ?? TreeSlot::root(), new RenderContext($locale, 'preview', $destination->id, $destination->layout));
                    if (! $entry['insertable']) {
                        $entry['variantDiagnostic'] = 'This saved section does not fit the selected parent or current canvas requirements.';
                    }
                }

                return $entry;
            }
            throw ValidationException::withMessages(['picker' => 'This saved section is unavailable or your permissions changed.']);
        }
        foreach ($this->page($actor, $locale, destination: $destination, slot: $slot)['entries'] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }
        throw ValidationException::withMessages(['picker' => 'This starting point is unavailable or your permissions changed.']);
    }

    public function snapshot(Authenticatable $actor, array $entry, string $locale, ?string $variant): SourceSnapshot
    {
        if (! ($entry['insertable'] ?? true) || ($variant === null && ! ($entry['defaultVariantAvailable'] ?? true))) {
            throw ValidationException::withMessages(['pickerVariant' => 'Choose a compatible variant before reviewing this starting point.']);
        }
        if ($variant !== null && ! array_key_exists($variant, $entry['variants'])) {
            throw ValidationException::withMessages(['pickerVariant' => 'Choose an available variant.']);
        }

        return $entry['kind'] === 'saved-section'
            ? $this->saved->snapshot($actor, $entry['id'], $entry['version'])
            : $this->sources->snapshot($entry['id'], $entry['version'], $locale, $variant);
    }

    public function layoutDigest(Page $page): string
    {
        return hash('sha256', json_encode([$page->layout, config('site-builder.layout'), config('site-builder-components.theme'), config('site-builder-components.assets'), app(CanonicalJson::class)->digest($page->draft_content?->extensions->get('site-builder.render'))], JSON_THROW_ON_ERROR));
    }

    private function savedEntry(object $section): array
    {
        return [
            'key' => 'saved:'.$section->id, 'id' => $section->id, 'kind' => 'saved-section',
            'version' => (string) $section->current_version, 'label' => $section->name,
            'description' => $section->description ?? '', 'category' => $section->category ?: 'Saved sections',
            'keywords' => $section->tags ?? [], 'variants' => [], 'previewAvailable' => true, 'legacy' => false,
            'defaultVariantAvailable' => true, 'variantDiagnostic' => '', 'insertable' => true,
        ];
    }

    private function registerHostDefaults(): void
    {
        foreach ($this->forms->all() as $type => $form) {
            $id = 'component:'.$type;
            if ($this->sources->find($id) !== null) {
                continue;
            }
            $definition = $this->components->resolve($type);
            if ($definition instanceof HasPlacementPolicy && ! $definition->placement()->rootAllowed) {
                continue;
            }
            $variants = $definition->properties()['variant'] ?? null;
            $variants = $variants?->kind === 'enum' ? array_combine($variants->options, array_map(Str::headline(...), $variants->options)) : [];
            $this->withoutPreview[$id] = true;
            $this->sources->register(new StartingPointDescriptor($id, $definition->version(), $form->label(), str_starts_with($type, 'demo.') ? 'Legacy' : $form->category(), 'A component registered by this website.', [$type], $variants), function (string $locale, ?string $variant) use ($definition): Document {
                $defaults = $definition->defaults();
                $props = $defaults->props->toObject();
                if ($variant !== null) {
                    $props->variant = $variant;
                }

                $normalize = function (Node $node, string $path) use (&$normalize, $definition): Node {
                    return new Node('example-'.substr(hash('sha256', $definition->type().'/'.$path), 0, 24), $node->type, $node->version, $node->props, $node->translations, $node->visibility, array_map(fn (Node $child, int $index): Node => $normalize($child, $path.'/'.$index), $node->children, array_keys($node->children)), $node->extensions);
                };
                $defaultsChildren = $definition instanceof HasDefaultChildren ? $definition->defaultChildren() : [];
                $children = array_map(fn (Node $child, int $index): Node => $normalize($child, (string) $index), $defaultsChildren, array_keys($defaultsChildren));

                return new Document([new Node('example-'.substr(hash('sha256', $definition->type()), 0, 20), $definition->type(), $definition->version(), new JsonObject($props), $defaults->translations, children: $children)]);
            });
        }
    }
}
