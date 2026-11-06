<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\SiteParts\Concerns;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\SiteParts\SitePartDesignCommands;
use stdClass;

trait SitePartDesigns
{
    #[Locked]
    public string $designScope = 'page';

    #[Locked]
    public string $designBreakpoint = 'base';

    #[Locked]
    public array $designBaseline = [];

    public array $designData = [];

    #[Locked]
    public ?string $designThemeBaseline = null;

    public ?string $designThemeChoice = null;

    #[Locked]
    public array $designThemeReview = [];

    public bool $designThemeConfirmed = false;

    #[Unexposed]
    public function designForm(Schema $schema): Schema
    {
        $description = $this->designDescription();
        $fields = [];
        if ($description['available'] && $description['diagnostics'] === []) {
            foreach ($description['controls'] as $key => $control) {
                $resolved = $control->options[$description['resolved'][$key] ?? ''] ?? 'Theme default';
                $fields[] = Select::make($key)->label($control->label)->options($control->options)->placeholder('Inherit ('.$resolved.')')
                    ->helperText('Resolved: '.$resolved.' · '.($description['sources'][$key] ?? 'Theme'))->live()
                    ->disableOptionWhen(fn ($value): bool => $this->designOptionDisabled($key, (string) $value, $description))
                    ->disabled($this->readOnly || ($this->designBreakpoint !== 'base' && ! $control->responsive));
            }
        }

        return $schema->components($fields)->statePath('designData');
    }

    public function changeDesignTarget(string $scope, string $breakpoint = 'base', int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($scope, $breakpoint): void {
            $this->flushSection();
            if (! in_array($scope, ['page', 'node'], true) || ($scope === 'node' && ($this->kind === 'design' || $this->selectedId === null))) {
                throw ValidationException::withMessages(['design' => 'Choose resource defaults or a current physical section.']);
            }
            $target = $scope === 'page' ? DesignTarget::document() : DesignTarget::node($this->selectedId);
            $description = app(DesignResolver::class)->describe($this->document(), $target, $this->context());
            if (! array_key_exists($breakpoint, $description['breakpoints'])) {
                throw ValidationException::withMessages(['design' => 'Choose a responsive target supported by this theme.']);
            }
            $this->designScope = $scope;
            $this->designBreakpoint = $breakpoint;
            $this->fillDesign();
        }, false);
    }

    public function saveDesign(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $this->message = 'Site-part draft design saved.';
        });
    }

    public function resetDesignKey(string $key, int $requestId = 0): array
    {
        return $this->perform($requestId, function () use ($key): void {
            $description = $this->designDescription();
            if (! $description['available'] || ! isset($description['controls'][$key])) {
                throw ValidationException::withMessages(['design' => 'Choose an available design control.']);
            } $this->designData[$key] = null;
            $this->flushSection();
            $this->fillDesign();
            $this->message = 'Resource override reset to inheritance.';
        });
    }

    public function resetDesignBreakpoint(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection(false);
            $this->accept(app(SitePartDesignCommands::class)->reset($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $this->designTarget(), null, $this->historyContext()));
            $this->fillDesign();
            $this->message = 'Resource responsive target reset.';
        });
    }

    public function resetDesignScope(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection(false);
            $this->accept(app(SitePartDesignCommands::class)->resetScope($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $this->designTarget(), $this->historyContext()));
            $this->fillDesign();
        });
    }

    public function prepareDesignTheme(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            $report = app(SitePartDesignCommands::class)->themeReport($this->actor(), $this->partId, $this->chosenTheme())->toArray();
            $this->designThemeReview = ['version' => $this->writeVersion, 'digest' => $this->draftDigest, 'choice' => $this->designThemeChoice, 'report' => $report];
            $this->designThemeConfirmed = false;
        });
    }

    public function applyDesignTheme(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->flushSection();
            if (! $this->designThemeConfirmed || ($this->designThemeReview['choice'] ?? false) !== $this->designThemeChoice || ($this->designThemeReview['version'] ?? null) !== $this->writeVersion || ! hash_equals($this->designThemeReview['digest'] ?? '', $this->draftDigest) || ! ($this->designThemeReview['report']['compatible'] ?? false)) {
                throw ValidationException::withMessages(['theme' => 'Review and confirm the theme against this accepted site-part draft.']);
            }
            $this->accept(app(SitePartDesignCommands::class)->setTheme($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $this->chosenTheme(), true, $this->historyContext()));
            $this->fillDesign(true);
            $this->message = 'Reviewed site-part theme saved to its draft.';
        });
    }

    public function discardDesignThemeChoice(int $requestId = 0): array
    {
        return $this->perform($requestId, function (): void {
            $this->designThemeChoice = $this->designThemeBaseline;
            $this->designThemeReview = [];
            $this->designThemeConfirmed = false;
        });
    }

    private function flushDesign(): void
    {
        if ($this->same($this->designData, $this->designBaseline)) {
            return;
        }
        $description = $this->designDescription();
        if (! $description['available'] || $description['diagnostics'] !== [] || array_diff(array_keys($this->designData), array_keys($description['controls'])) !== []) {
            throw ValidationException::withMessages(['design' => 'Unavailable stored design is retained. Review a compatible theme or explicitly reset this scope.']);
        }
        $this->withGuard(function () use ($description): void {
            $values = $this->designForm->getState();
            $changes = [];
            foreach ($description['controls'] as $key => $control) {
                if (! $this->same($this->designBaseline[$key] ?? null, $this->designData[$key] ?? null) && array_key_exists($key, $values)) {
                    $changes[$key] = $values[$key] === '' ? null : $values[$key];
                }
            }
            $this->accept(app(SitePartDesignCommands::class)->patch($this->actor(), $this->partId, $this->leaseToken, $this->writeVersion, $this->designTarget(), $changes, $this->historyContext()));
            $this->fillDesign();
        });
    }

    private function fillDesign(bool $resetChoice = false): void
    {
        $description = $this->designDescription();
        $this->cacheSchema('designForm', $this->designForm(Schema::make($this)));
        $this->designForm->fill($description['available'] && $description['diagnostics'] === [] ? $description['explicit'] : []);
        $this->designBaseline = $this->designData;
        $theme = DesignData::payload($this->document()->extensions)?->theme ?? null;
        $new = $theme === null ? null : $theme->id.'@'.$theme->version.':'.$theme->preset;
        if ($resetChoice || $this->designThemeChoice === $this->designThemeBaseline) {
            $this->designThemeChoice = $new;
        }
        $this->designThemeBaseline = $new;
        if ($resetChoice) {
            $this->designThemeReview = [];
            $this->designThemeConfirmed = false;
        }
    }

    private function designTarget(): DesignTarget
    {
        return $this->designScope === 'node' && $this->selectedId !== null ? DesignTarget::node($this->selectedId, $this->designBreakpoint) : DesignTarget::document($this->designBreakpoint);
    }

    private function designDescription(): array
    {
        return app(DesignResolver::class)->describe($this->document(), $this->designTarget(), $this->context());
    }

    private function chosenTheme(): ?ThemeIdentity
    {
        if ($this->designThemeChoice === null || $this->designThemeChoice === '') {
            if ($this->kind === 'design') {
                throw ValidationException::withMessages(['theme' => 'Site-design resources require an explicit pinned theme.']);
            }

            return null;
        }
        foreach (app(DesignCatalog::class)->themes($this->context()) as $theme) {
            if ($theme['available'] && $theme['key'] === $this->designThemeChoice) {
                return new ThemeIdentity($theme['id'], $theme['version'], $theme['preset']);
            }
        }
        throw ValidationException::withMessages(['theme' => 'Choose an available registered theme.']);
    }

    private function designOptionDisabled(string $key, string $option, array $description): bool
    {
        $theme = $description['theme'];
        if ($theme === null) {
            return true;
        }
        $document = $this->document();
        $root = DesignData::payload($document->extensions) ?? (object) ['version' => 1, 'theme' => (object) $theme->toArray()];
        $document = DesignData::replace($document, null, $root);
        $nodeId = $this->designScope === 'node' ? $this->selectedId : null;
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
        $values = app(DesignResolver::class)->resolve($document, $node, $this->context())->states[$this->designBreakpoint]['values'] ?? $description['resolved'];
        $values[$key] = $option;

        return app(DesignCatalog::class)->definition($theme)->combinationErrors($theme, $values, $node) !== [];
    }
}
