<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Discovery\DiscoveryMetadata;
use SiteBuilder\Filament\Support\OptionalComponents;

trait DiscoversDraft
{
    public array $discoveryData = [];

    #[Locked]
    public array $discoveryBaseline = [];

    #[Unexposed]
    public function discoveryForm(Schema $schema): Schema
    {
        $existing = $this->documentJson === '' ? null : DiscoveryMetadata::values($this->document(), $this->locale)['image'] ?? null;
        $images = app(OptionalComponents::class)->picker()?->options($this->locale) ?? [];

        return $schema->components([
            Textarea::make('summary')->label('Listing and search summary')->maxLength(2000)->rows(3)->helperText('Leave blank to use a short excerpt from the published page.'),
            Select::make('image')->label('Listing thumbnail')->options([...($existing ? [$existing => 'Current thumbnail'] : []), ...$images])->searchable(),
            TextInput::make('alt')->label('Thumbnail alternative text')->maxLength(2000),
            TagsInput::make('tags')->label('Topics')->nestedRecursiveRules(['string', 'max:80'])->rules(['array', 'max:32']),
            TextInput::make('date')->label('Editorial date')->type('date')->nullable()->rules(['nullable', 'date_format:Y-m-d']),
            Toggle::make('listable')->label('Include in content listings'),
            Toggle::make('searchable')->label('Include in site search'),
        ])->statePath('discoveryData')->disabled($this->readOnly);
    }

    public function saveDiscoverySettings(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->flushDiscovery();
            $this->message = 'Discovery settings saved to the draft. Publish the page to make them live.';
        });
    }

    private function fillDiscovery(): void
    {
        if ($this->documentJson === '') {
            return;
        }
        $values = DiscoveryMetadata::values($this->document(), $this->locale);
        $this->cacheSchema('discoveryForm', $this->discoveryForm(Schema::make($this)));
        $this->discoveryForm->fill(['summary' => '', 'image' => null, 'alt' => '', 'tags' => [], 'date' => null, 'listable' => true, 'searchable' => true, ...$values]);
        $this->discoveryBaseline = $this->discoveryData;
    }

    private function flushDiscovery(): void
    {
        if ($this->same($this->discoveryBaseline, $this->discoveryData)) {
            return;
        }
        $this->editable();
        $data = $this->discoveryForm->getState();
        $this->saveDocument(DiscoveryMetadata::with($this->document(), $this->locale, $data), 'Edit discovery settings');
        $this->discoveryBaseline = $this->discoveryData;
    }
}
