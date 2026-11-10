<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Discovery\ContentSources;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Contracts\TypedMediaPicker;
use SiteBuilder\Filament\Support\OptionalForms;

/** Optional forms follow the installed core schema; no component-pack dependency. */
final readonly class StandardComponentForm implements ComponentForm
{
    private const LABELS = [
        'form' => 'Contact / enquiry form',
        'content-listing' => 'Dynamic content listing', 'search-box' => 'Site search', 'search-results' => 'Search results', 'carousel' => 'Carousel', 'timeline' => 'Timeline', 'image-comparison' => 'Image comparison', 'image-hotspots' => 'Image hotspots',
        'video' => 'Video', 'location' => 'Location and map',
        'tabs' => 'Tabs', 'tab-panel' => 'Tab panel', 'accordion' => 'Accordion', 'accordion-item' => 'Disclosure item',
        'table' => 'Table', 'table-of-contents' => 'Table of contents', 'breadcrumbs' => 'Breadcrumbs',
        'heading' => 'Heading', 'buttons' => 'Button or button group', 'icon' => 'Icon', 'icon-list' => 'Icon list',
        'social-links' => 'Social links', 'divider' => 'Divider', 'anchor' => 'Named anchor',
        'hero' => 'Hero', 'text' => 'Text', 'image' => 'Image', 'image-text' => 'Image and text',
        'cards' => 'Cards and logos', 'cta' => 'Call to action', 'faq' => 'Questions and answers',
        'contact-details' => 'Contact details', 'section' => 'Section', 'container' => 'Container',
        'brand' => 'Site branding', 'navigation' => 'Navigation', 'gallery' => 'Image gallery', 'testimonials' => 'Testimonials',
        'columns' => 'Columns', 'column' => 'Column',
    ];

    public function __construct(private ComponentDefinition $definition) {}

    public static function supports(string $type): bool
    {
        return in_array($type, array_map(fn (string $type): string => 'standard.'.$type, array_keys(self::LABELS)), true)
            || in_array($type, ['demo.hero', 'demo.text', 'demo.image-text', 'demo.cta'], true);
    }

    public function type(): string
    {
        return $this->definition->type();
    }

    public function label(): string
    {
        return self::LABELS[explode('.', $this->type(), 2)[1]];
    }

    public function icon(): string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    public function category(): string
    {
        return in_array($this->type(), ['standard.section', 'standard.container', 'standard.columns', 'standard.column'], true) ? 'Layout' : 'Content';
    }

    public function schema(EditorContext $context): array
    {
        return $this->fields($this->definition->properties(), $context, [
            'media' => $this->references($this->definition->properties(), $context->originalValues, 'media'),
            'page' => $this->references($this->definition->properties(), $context->originalValues, 'page'),
            'revision' => $this->references($this->definition->properties(), $context->originalValues, 'revision'),
        ]);
    }

    /** @param array<string, PropertyDefinition> $properties @return array<Component> */
    private function fields(array $properties, EditorContext $context, array $originalReferences): array
    {
        $fields = [];
        foreach ($properties as $name => $property) {
            $field = match ($property->kind) {
                'text' => match (true) {
                    $name === 'source' => Select::make($name)->options([...($this->type() === 'standard.search-results' ? ['*' => 'All registered sources'] : []), ...app(ContentSources::class)->options()]),
                    $this->type() === 'standard.form' && $name === 'profile' => Select::make($name)->label('Approved enquiry destination')->options(fn (): array => app(OptionalForms::class)->profileOptions())->live()->afterStateUpdated(fn ($state, Set $set) => $set('installation', app(OptionalForms::class)->runtime()?->installation()))->helperText('Choose a destination you are permitted to use. Configure the optional forms runtime first.'),
                    $this->type() === 'standard.form' && $name === 'installation' => Hidden::make($name),
                    in_array($name, ['quote', 'caption', 'address', 'help'], true) => Textarea::make($name)->rows($name === 'quote' ? 5 : 3),
                    default => TextInput::make($name),
                },
                'html' => RichEditor::make($name)->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList']),
                'boolean' => Toggle::make($name),
                'enum', 'token' => Select::make($name)->options(array_combine($property->options, array_map(Str::headline(...), $property->options))),
                'media' => Select::make($name)->options([...array_fill_keys($originalReferences['media'], $property->mediaKind === 'image' ? 'Existing image' : 'Existing media'), ...($context->mediaPicker instanceof TypedMediaPicker ? $context->mediaPicker->optionsForKind($context->locale, $property->mediaKind) : ($property->mediaKind === 'image' ? ($context->mediaPicker?->options($context->locale) ?? []) : []))])->searchable()
                    ->disabled($context->readOnly || $context->mediaPicker === null || ($property->mediaKind !== 'image' && ! $context->mediaPicker instanceof TypedMediaPicker))
                    ->helperText('Choose '.$property->mediaKind.' media. A typed picker is required for videos and captions.'),
                'page' => $this->pageField($name, $originalReferences['page']),
                'revision' => $this->overlayField($name, $originalReferences['revision']),
                'object' => Group::make($this->fields($property->properties, $context, $originalReferences))->statePath($name),
                'list' => Repeater::make($name)->schema($this->fields($property->items->properties, $context, $originalReferences))->defaultItems(0)->reorderableWithButtons()
                    ->addable(! in_array('max:0', $property->rules, true))->helperText($this->type() === 'standard.testimonials' && $name === 'items' ? 'Single presentation shows the first item; every stored item is retained and validated.' : null),
                'number' => $this->type() === 'standard.image-hotspots' && in_array($name, ['x', 'y'], true) ? TextInput::make($name)->numeric()->minValue(0)->maxValue(100)->step(.01)->formatStateUsing(fn ($state) => $state === null ? 50 : $state / 100)->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100)) : TextInput::make($name)->numeric()->dehydrateStateUsing(self::number(...)),
                default => TextInput::make($name)->maxLength(match ($name) {
                    'heading' => 255, 'alt', 'caption' => 2000, default => null,
                }),
            };
            if (method_exists($field, 'label')) {
                $field->label(match ($name) {
                    'x' => $this->type() === 'standard.image-hotspots' ? 'Horizontal position (%)' : 'X', 'y' => $this->type() === 'standard.image-hotspots' ? 'Vertical position (%)' : 'Y',
                    'overlay' => 'Popup or drawer (takes priority)',
                    'interval' => 'Autoplay interval (seconds)',
                    'page' => 'Link to page', 'secondary_page' => 'Secondary link to page',
                    'url' => 'Link URL', 'secondary_url' => 'Secondary link URL',
                    'alt' => 'Alternative text', 'decorative' => 'Decorative image (empty alternative text)',
                    default => Str::headline($name),
                });
            }
            if (in_array($this->type(), ['standard.carousel', 'standard.timeline'], true)) {
                if (in_array($name, ['source', 'content_type', 'content_tag', 'sort', 'limit'], true)) {
                    $field->visible(fn (Get $get): bool => $get('content_mode') === 'dynamic');
                } elseif ($name === 'items') {
                    $field->visible(fn (Get $get): bool => $get('content_mode') !== 'dynamic');
                } elseif ($name === 'content_mode') {
                    $field->live();
                }
            }
            if ($name === 'source') {
                $field->required();
            }
            if ($this->type() === 'standard.carousel' && $name === 'autoplay') {
                $field->live();
            }
            if ($this->type() === 'standard.carousel' && $name === 'interval') {
                $field->visible(fn (Get $get): bool => (bool) $get('autoplay'));
            }
            if (in_array($property->kind, ['url', 'page'], true)) {
                $field->helperText($property->kind === 'page' ? 'An internal page takes priority over the URL. Unpublished pages cannot create a public link.' : 'Use an https:// URL, a site path, mailto: or tel:.');
            }
            if ($property->rules !== [] && method_exists($field, 'rules') && ! ($this->type() === 'standard.image-hotspots' && in_array($name, ['x', 'y'], true))) {
                $field->rules([...($property->nullable || $property->translated ? ['nullable'] : []), ...$property->rules]);
            }
            $fields[] = $field;
            if ($this->type() === 'standard.image-hotspots' && $name === 'y') {
                $fields[] = ViewField::make('_placement')->view('site-builder-filament::forms.hotspot-position')->dehydrated(false);
            }
        }

        return $fields;
    }

    /** Native numeric fields may dehydrate whole values as floats; new numeric edits use exact safe integers. */
    private static function number(mixed $state): int|float|null
    {
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return null;
        }
        $value = $state + 0;

        return is_float($value) && abs($value) <= 9007199254740991 && floor($value) === $value ? (int) $value : $value;
    }

    private function pageField(string $name, array $originalReferences): Select
    {
        return Select::make($name)->searchable()->getSearchResultsUsing(function (string $search): array {
            $actor = Filament::auth()->user();
            if ($actor === null) {
                return [];
            }

            return Page::query()->where('title', 'like', '%'.$search.'%')->orderBy('title')->limit(100)->get()
                ->filter(fn (Page $page): bool => Gate::forUser($actor)->allows('view', $page))
                ->mapWithKeys(fn (Page $page): array => [$page->id => $page->title])->all();
        })->getOptionLabelUsing(function ($value) use ($originalReferences): ?string {
            $actor = Filament::auth()->user();
            $page = is_numeric($value) ? Page::query()->find($value) : null;

            if ($actor !== null && $page !== null && Gate::forUser($actor)->allows('view', $page)) {
                return $page->title;
            }

            return in_array((int) $value, $originalReferences, true) ? 'Unavailable page (#'.(int) $value.')' : null;
        })->dehydrateStateUsing(fn ($state): ?int => $state === null || $state === '' ? null : (int) $state);
    }

    private function overlayField(string $name, array $original): Select
    {
        return Select::make($name)->label('Popup or drawer (takes priority)')->searchable()->getSearchResultsUsing(function (string $search): array {
            $actor = Filament::auth()->user();
            if ($actor === null || ! app(SitePartCatalog::class)->available()) {
                return [];
            }
            $entries = app(SitePartCatalog::class)->searchPage($actor, $search, 'overlay')['entries'];

            return $entries->filter(fn ($part) => $part->current_revision_id !== null && Gate::forUser($actor)->allows('use', $part))->mapWithKeys(fn ($part) => [$part->current_revision_id => $part->name])->all();
        })->getOptionLabelUsing(function ($value) use ($original): ?string {
            $revision = is_string($value) && app(SitePartCatalog::class)->available() ? SitePartRevision::query()->find($value) : null;
            $part = $revision === null ? null : SitePart::query()->find($revision->resource_id);
            $actor = Filament::auth()->user();
            if ($part !== null && $actor !== null && Gate::forUser($actor)->allows('view', $part)) {
                return $part->name.' · revision '.$revision->sequence;
            }

            return in_array($value, $original, true) ? 'Unavailable pinned popup' : null;
        })->dehydrateStateUsing(function ($state) use ($original): ?array {
            if ($state === null || $state === '') {
                return null;
            }
            $actor = Filament::auth()->user() ?? abort(403);
            if (in_array($state, $original, true)) {
                $revision = SitePartRevision::query()->findOrFail($state);
                $reference = $revision->reference();
            } else {
                $reference = app(SitePartCatalog::class)->reference($actor, $state);
            }

            return get_object_vars($reference->jsonSerialize());
        })->helperText('Publish a popup in the popup library first. This action pins its exact revision. Clear it to use the page or URL instead.');
    }

    /** Stored references stay selectable when another library owns them or their target is unavailable. */
    private function references(array $properties, array $values, string $kind): array
    {
        $references = [];
        foreach ($properties as $name => $property) {
            $value = $values[$name] ?? null;
            if ($property->kind === $kind && (($kind === 'media' && is_string($value) && $value !== '') || ($kind === 'page' && is_int($value) && $value > 0) || ($kind === 'revision' && is_string($value)))) {
                $references[] = $value;
            } elseif ($property->kind === 'object' && is_array($value)) {
                $references = [...$references, ...$this->references($property->properties, $value, $kind)];
            } elseif ($property->kind === 'list' && is_array($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $references = [...$references, ...$this->references($property->items->properties, $item, $kind)];
                    }
                }
            }
        }

        return array_values(array_unique($references, SORT_REGULAR));
    }
}
