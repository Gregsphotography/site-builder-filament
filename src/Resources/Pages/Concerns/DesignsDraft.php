<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Pages\Concerns;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignCommands;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Filament\Composition\PickerCatalog;
use stdClass;

trait DesignsDraft
{
    #[Locked]
    public string $designScope = 'node';

    #[Locked]
    public string $designBreakpoint = 'base';

    #[Locked]
    public array $designBaseline = [];

    #[Locked]
    public ?string $designThemeBaseline = null;

    #[Locked]
    public bool $designInitialized = false;

    #[Locked]
    public array $designThemeReview = [];

    public array $designData = [];

    public ?string $designThemeChoice = null;

    public bool $designThemeConfirmed = false;

    private ?string $designPendingSignature = null;

    private array $designPendingValues = [];

    #[Unexposed]
    public function designForm(Schema $schema): Schema
    {
        $description = $this->designDescription();
        $fields = [];
        if (! $description['available']) {
            return $schema->components([])->statePath('designData')->columns(1);
        }
        foreach ($description['controls'] as $key => $control) {
            $resolved = $description['resolved'][$key] ?? null;
            $label = $control->options[$resolved] ?? $resolved ?? 'Theme default';
            $source = $description['sources'][$key] ?? 'Theme';
            $fields[] = Select::make($key)->label($control->label)->options($control->options)->placeholder('Inherit ('.$label.')')
                ->helperText('Resolved: '.$label.' · '.$source)
                ->live()->disableOptionWhen(fn ($value): bool => $this->designOptionDisabled($key, (string) $value, $description))
                ->disabled($this->readOnly || ! $description['available'] || ($this->designBreakpoint !== 'base' && ! $control->responsive));
        }

        return $schema->components($fields)->statePath('designData')->columns(1);
    }

    public function changeDesignTarget(string $scope, string $breakpoint = 'base', int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($scope, $breakpoint): void {
            $this->flushSection();
            if (! in_array($scope, ['page', 'node'], true)) {
                throw ValidationException::withMessages(['design' => 'Choose a supported design scope and responsive target.']);
            }
            $target = $scope === 'page' ? DesignTarget::document() : ($this->selectedId === null ? null : DesignTarget::node($this->selectedId));
            $description = $target === null ? [] : app(DesignResolver::class)->describe($this->document(), $target, $this->designRenderContext());
            if (! array_key_exists($breakpoint, $description['breakpoints'] ?? ['base' => 'All sizes'])) {
                throw ValidationException::withMessages(['design' => 'Choose a responsive target supported by this theme.']);
            }
            $this->designScope = $scope;
            $this->designBreakpoint = $breakpoint;
            $this->fillDesign();
        });
    }

    public function saveDesign(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->message = 'Draft design saved.';
        });
    }

    public function resetDesignKey(string $key, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($key): void {
            $description = $this->designDescription();
            if (! $description['available'] || ! isset($description['controls'][$key])) {
                throw ValidationException::withMessages(['design' => 'This control is unavailable for the selected scope.']);
            }
            $this->designData[$key] = null;
            $this->flushSection();
            $this->fillDesign();
            $this->message = 'Override reset to inheritance.';
        });
    }

    public function resetDesignBreakpoint(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection(false);
            $this->accept(app(DesignCommands::class)->reset($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->designTarget(), history: $this->draftHistoryContext()));
            $this->fillDesign();
            $this->message = 'Responsive target overrides reset.';
        });
    }

    public function resetDesignScope(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection(false);
            $this->accept(app(DesignCommands::class)->resetScope($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->designTarget(), history: $this->draftHistoryContext()));
            $this->fillDesign();
            $this->message = 'Design overrides reset at every size.';
        });
    }

    public function prepareDesignTheme(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            $this->designThemeChoice = $this->normalizedThemeChoice();
            $theme = $this->chosenDesignTheme($this->designThemeChoice);
            $report = app(DesignCommands::class)->themeReport($this->actor(), $this->pageId, $theme)->toArray();
            $this->designThemeReview = ['choice' => $this->designThemeChoice, 'pageVersion' => $this->writeVersion, 'draftDigest' => $this->draftDigest,
                'layoutDigest' => app(PickerCatalog::class)->layoutDigest($this->page()), 'report' => $report];
            $this->designThemeConfirmed = false;
        });
    }

    public function applyDesignTheme(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->editable();
            $this->flushSection();
            if (! $this->designThemeConfirmed || $this->designThemeReview === [] || $this->normalizedThemeChoice() !== $this->designThemeReview['choice']
                || $this->writeVersion !== $this->designThemeReview['pageVersion'] || ! hash_equals($this->draftDigest, $this->designThemeReview['draftDigest'])
                || ! hash_equals(app(PickerCatalog::class)->layoutDigest($this->page()), $this->designThemeReview['layoutDigest'])) {
                throw ValidationException::withMessages(['designTheme' => 'Review and confirm this theme against the current accepted draft before applying it.']);
            }
            if (! ($this->designThemeReview['report']['compatible'] ?? false)) {
                throw ValidationException::withMessages(['designTheme' => 'Resolve the reported incompatible settings before switching themes.']);
            }
            $this->accept(app(DesignCommands::class)->setTheme($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->chosenDesignTheme($this->normalizedThemeChoice()), true, $this->draftHistoryContext()));
            $this->fillDesign(true);
            $this->designThemeReview = [];
            $this->designThemeConfirmed = false;
            $this->message = 'Draft canvas theme saved.';
        });
    }

    public function discardDesignThemeChoice(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->designThemeChoice = $this->designThemeBaseline;
            $this->designThemeReview = [];
            $this->designThemeConfirmed = false;
            $this->message = 'Pending theme choice discarded.';
        });
    }

    private function flushDesign(): void
    {
        if (! $this->designInitialized || $this->same($this->designBaseline, $this->designData)) {
            return;
        }
        $this->editable();
        $description = $this->designDescription();
        if (! $description['available'] || array_diff(array_keys($this->designData), array_keys($description['controls'])) !== []) {
            throw ValidationException::withMessages(['design' => 'Design controls are unavailable or contain an unknown setting. Your input is retained.']);
        }
        $result = $this->withEditingGuard(function () use ($description): MutationResult {
            $values = $this->designForm->getState();
            $changes = [];
            foreach ($description['controls'] as $key => $control) {
                if ($this->same($this->designBaseline[$key] ?? null, $this->designData[$key] ?? null)) {
                    continue;
                }
                if ($this->designBreakpoint !== 'base' && ! $control->responsive) {
                    throw ValidationException::withMessages(['designData.'.$key => 'This control is supported at all sizes only.']);
                }
                if (! array_key_exists($key, $values)) {
                    continue;
                }
                $changes[$key] = $values[$key] === '' ? null : $values[$key];
            }

            return app(DesignCommands::class)->patch($this->actor(), $this->pageId, $this->leaseToken, $this->writeVersion, $this->designTarget(), $changes, $this->draftHistoryContext());
        });
        $this->accept($result);
        $this->fillDesign();
    }

    private function fillDesign(bool $resetThemeChoice = false): void
    {
        if ($resetThemeChoice) {
            $this->designThemeReview = [];
            $this->designThemeConfirmed = false;
        }
        $description = $this->designDescription();
        $this->cacheSchema('designForm', $this->designForm(Schema::make($this)));
        $this->designForm->fill($description['available'] ? $description['explicit'] : []);
        $this->designBaseline = $this->designData;
        $oldBaseline = $this->designThemeBaseline;
        $this->designThemeBaseline = $this->storedDesignThemeChoice();
        if ($resetThemeChoice || ! $this->designInitialized || $this->normalizedThemeChoice() === $oldBaseline) {
            $this->designThemeChoice = $this->designThemeBaseline;
        }
        $this->designInitialized = true;
    }

    private function designDescription(): array
    {
        if ($this->documentJson === '' || ($this->designScope === 'node' && $this->selectedId === null)) {
            return ['available' => false, 'theme' => null, 'controls' => [], 'explicit' => [], 'resolved' => [], 'sources' => [], 'breakpoints' => ['base' => 'All sizes'], 'diagnostics' => ['Select a supported section to edit its design.']];
        }

        $description = app(DesignResolver::class)->describe($this->document(), $this->designTarget(), $this->designRenderContext());
        $description['canResetScope'] = $description['available'];
        if ($description['diagnostics'] !== []) {
            $description['available'] = false;
        }

        return $description;
    }

    private function designOptionDisabled(string $key, string $option, array $description): bool
    {
        $theme = $description['theme'];
        $definition = $theme === null ? null : app(DesignCatalog::class)->definition($theme);
        if ($definition === null || ! $description['available']) {
            return true;
        }
        $signature = hash('sha256', json_encode([$this->draftDigest, $this->designScope, $this->selectedId, $this->designBreakpoint, $this->designData], JSON_THROW_ON_ERROR));
        if ($this->designPendingSignature !== $signature) {
            $document = $this->document();
            $root = DesignData::payload($document->extensions) ?? (object) ['version' => 1, 'theme' => (object) $theme->toArray()];
            $document = DesignData::replace($document, null, $root);
            $nodeId = $this->designScope === 'page' ? null : $this->selectedId;
            $payload = $nodeId === null ? $root : (DesignData::payload(DesignData::find($document, $nodeId)->extensions) ?? (object) ['version' => 1]);
            $map = $this->designBreakpoint === 'base' ? ($payload->base ?? new stdClass) : ($payload->responsive->{$this->designBreakpoint} ?? new stdClass);
            foreach ($description['controls'] as $controlKey => $control) {
                $pending = $this->designData[$controlKey] ?? null;
                if ($pending === null || $pending === '') {
                    unset($map->{$controlKey});
                } elseif (is_string($pending) && array_key_exists($pending, $control->options)) {
                    $map->{$controlKey} = $pending;
                }
            }
            if ($this->designBreakpoint === 'base') {
                $payload->base = $map;
            } else {
                $payload->responsive ??= new stdClass;
                $payload->responsive->{$this->designBreakpoint} = $map;
            }
            $document = DesignData::replace($document, $nodeId, $payload);
            $node = $nodeId === null ? null : DesignData::find($document, $nodeId);
            $resolved = app(DesignResolver::class)->resolve($document, $node, $this->designRenderContext());
            $this->designPendingValues = $resolved->states[$this->designBreakpoint]['values'] ?? $description['resolved'];
            $this->designPendingSignature = $signature;
        }
        $values = $this->designPendingValues;
        $values[$key] = $option;

        return $definition->combinationErrors($theme, $values, $this->designScope === 'page' ? null : $this->selectedNode()) !== [];
    }

    private function designTarget(): DesignTarget
    {
        if ($this->designScope === 'page') {
            return DesignTarget::document($this->designBreakpoint);
        }
        if ($this->selectedId === null) {
            throw ValidationException::withMessages(['design' => 'Select a section first.']);
        }

        return DesignTarget::node($this->selectedId, $this->designBreakpoint);
    }

    private function designRenderContext(): RenderContext
    {
        return new RenderContext($this->locale, 'preview', $this->pageId, $this->page()->layout);
    }

    private function designThemes(): array
    {
        return app(DesignCatalog::class)->themes($this->designRenderContext());
    }

    private function normalizedThemeChoice(): ?string
    {
        return $this->designThemeChoice === '' ? null : $this->designThemeChoice;
    }

    private function chosenDesignTheme(?string $key): ?ThemeIdentity
    {
        if ($key === null) {
            return null;
        }
        foreach ($this->designThemes() as $theme) {
            if ($theme['key'] === $key && $theme['available']) {
                return new ThemeIdentity($theme['id'], $theme['version'], $theme['preset']);
            }
        }
        throw ValidationException::withMessages(['designTheme' => 'Choose an available registered canvas theme.']);
    }

    private function storedDesignThemeChoice(): ?string
    {
        if ($this->documentJson === '') {
            return null;
        }
        $render = $this->document()->extensions->get('site-builder.render');
        $stored = $render instanceof stdClass && ($render->design ?? null) instanceof stdClass ? ($render->design->theme ?? null) : null;
        if (! $stored instanceof stdClass) {
            return null;
        }
        foreach ($this->designThemes() as $theme) {
            if ($theme['id'] === ($stored->id ?? null) && $theme['version'] === ($stored->version ?? null) && $theme['preset'] === ($stored->preset ?? null)) {
                return $theme['key'];
            }
        }

        return '__unavailable__';
    }

    private function assertDesignThemeSettled(): void
    {
        if ($this->normalizedThemeChoice() !== $this->designThemeBaseline) {
            throw ValidationException::withMessages(['designTheme' => 'Review and apply the pending theme choice, or discard it, before publishing.']);
        }
    }

    private function designViewData(): array
    {
        $description = $this->designDescription();
        $themes = $this->designThemes();
        $pageDescription = $this->documentJson === '' ? [] : app(DesignResolver::class)->describe($this->document(), DesignTarget::document(), $this->designRenderContext());
        $controls = [];
        foreach ($description['controls'] as $key => $control) {
            $controls[] = ['key' => $key, 'label' => $control->label, 'responsive' => $control->responsive, 'explicit' => array_key_exists($key, $description['explicit'])];
        }

        $changes = [];
        foreach ($this->designThemeReview['report']['changedDefaults'] ?? [] as $key => $change) {
            if (is_string($change)) {
                $changes[] = $change;
            } elseif (is_array($change)) {
                $before = $change['before'] ?? $change['from'] ?? 'Inherited';
                $after = $change['after'] ?? $change['to'] ?? 'Inherited';
                $changes[] = Str::headline(is_string($key) ? $key : ($change['key'] ?? 'Default')).': '.$this->designChangeValue($before, $themes).' → '.$this->designChangeValue($after, $themes);
                if (is_string($change['message'] ?? null) && $change['message'] !== '') {
                    $changes[] = $change['message'];
                }
            }
        }

        return ['designDescription' => $description, 'designControls' => $controls, 'designThemes' => $themes,
            'designCanChooseTheme' => ($pageDescription['available'] ?? false) && array_any($themes, fn (array $theme): bool => $theme['available']),
            'designThemeChanges' => $changes, 'designBreakpoints' => $description['breakpoints'] ?? ['base' => 'All sizes'],
            'designTargetLabel' => ($description['breakpoints'] ?? ['base' => 'All sizes'])[$this->designBreakpoint] ?? $this->designBreakpoint,
            'designScopeLabel' => $this->designScope === 'page' ? 'Page defaults' : 'Selected section'];
    }

    private function designChangeValue(mixed $value, array $themes): string
    {
        if (is_array($value) && isset($value['id'], $value['version'], $value['preset'])) {
            foreach ($themes as $theme) {
                if ($theme['id'] === $value['id'] && $theme['version'] === $value['version'] && $theme['preset'] === $value['preset']) {
                    return $theme['label'].' v'.$theme['version'];
                }
            }

            return $value['id'].' v'.$value['version'].' / '.$value['preset'];
        }

        return is_scalar($value) ? (string) $value : ($value === null ? 'Inherited' : 'Registered defaults');
    }
}
