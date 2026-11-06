<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Installation;

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentAsset;
use SiteBuilder\Core\Installation\DoctorCheck;
use SiteBuilder\Core\Installation\SetupAnswers;
use SiteBuilder\Core\Installation\SetupContribution;
use SiteBuilder\Core\Installation\SetupContributor;
use SiteBuilder\Core\Installation\SetupDiscovery;
use SiteBuilder\Core\Installation\SetupFailure;
use SiteBuilder\Core\Installation\SetupFile;
use SiteBuilder\Core\Installation\SetupFilesystem;
use SiteBuilder\Filament\Pages\MediaLibrary;

final class FilamentSetupContributor implements SetupContributor
{
    public function __construct(private SetupFilesystem $fs) {}

    public function id(): string
    {
        return 'filament';
    }

    public function discover(): SetupDiscovery
    {
        $panels = [];
        foreach (Filament::getPanels() as $id => $panel) {
            $panels[$id] = ['guard' => $panel->getAuthGuard(), 'path' => $panel->getPath(), 'plugin' => $panel->hasPlugin('site-builder'), 'media' => in_array(MediaLibrary::class, $panel->getPages(), true)];
        }

        return new SetupDiscovery(['panels' => $panels, 'version' => InstalledVersions::getPrettyVersion('filament/filament'), 'livewire' => InstalledVersions::getPrettyVersion('livewire/livewire')]);
    }

    private function selected(SetupAnswers $answers): bool
    {
        return $answers->get('registration.panelId') !== null;
    }

    public function plan(SetupAnswers $answers, SetupDiscovery $discovery): SetupContribution
    {
        if (! $this->selected($answers)) {
            return new SetupContribution;
        }
        $files = SetupContribution::assets(__DIR__.'/../../resources/dist', 'public/vendor/site-builder-filament');
        foreach ([...FilamentAsset::getAlpineComponents(), ...FilamentAsset::getScripts(), ...FilamentAsset::getStyles(), ...FilamentAsset::getThemes(), ...FilamentAsset::getFonts()] as $asset) {
            $source = $asset->getPath();
            if ($asset->isRemote() || ! is_string($source) || $asset->getPackage() === 'app') {
                continue;
            }
            $destination = $asset->getPublicPath();
            if (! str_starts_with($destination, $this->fs->root.'/public/')) {
                throw new SetupFailure('asset-target-unavailable', 'Filament assets must have an app-relative public destination.', 2);
            }
            $target = substr($destination, strlen($this->fs->root) + 1);
            if (is_dir($source)) {
                $files = [...$files, ...SetupContribution::assets($source, $target)];
            } elseif (is_file($source)) {
                $files[] = new SetupFile($target, file_get_contents($source), 'Publish the installed local Filament runtime asset.');
            }
        }
        $unique = [];
        foreach ($files as $file) {
            if (isset($unique[$file->path]) && $unique[$file->path]->contents !== $file->contents) {
                throw new SetupFailure('asset-conflict', 'Installed assets declare conflicting targets.', 3);
            } $unique[$file->path] = $file;
        }

        return new SetupContribution(array_values($unique));
    }

    public function checks(SetupAnswers $answers, SetupDiscovery $discovery): array
    {
        if (! $this->selected($answers)) {
            return [new DoctorCheck('packages.filament', 'not-applicable', 'editor-declined', 'The optional editor was not selected.'), new DoctorCheck('panel.plugin', 'not-applicable', 'editor-declined', 'No panel integration selected.'), new DoctorCheck('assets.filament', 'not-applicable', 'editor-declined', 'No editor assets selected.')];
        }
        $panel = $discovery->facts['panels'][$answers->get('registration.panelId')] ?? null;
        $valid = $panel !== null && $panel['guard'] === $answers->get('authorization.guard');
        $plugin = $valid && $panel['plugin'] && (! $answers->get('features.media') || $answers->get('storage.mode') !== 'local-public' || $panel['media']);
        $assets = $this->plan($answers, $discovery)->files;
        $current = array_all($assets, fn ($file) => $this->fs->hash($file->path) === hash('sha256', $file->contents));
        $compatible = version_compare(ltrim($discovery->facts['version'] ?? '', 'v'), '5.11.0', '>=') && version_compare(ltrim($discovery->facts['version'] ?? '', 'v'), '6.0.0', '<') && version_compare(ltrim($discovery->facts['livewire'] ?? '', 'v'), '4.4.8', '>=') && version_compare(ltrim($discovery->facts['livewire'] ?? '', 'v'), '5.0.0', '<');

        return [new DoctorCheck('packages.filament', $compatible ? 'pass' : 'unavailable', 'editor-range', 'Selected editor supports Filament ^5.11 and Livewire ^4.4.8.'),
            new DoctorCheck('panel.plugin', ! $valid ? 'unavailable' : ($plugin ? 'pass' : 'action'), ! $valid ? 'panel-or-guard-unavailable' : 'panel-plugin-state', 'An existing panel and its configured guard must match the explicit selection.', ! $valid ? 'Install/configure a panel first using the host Filament instructions; setup does not run filament:install or create accounts.' : ($plugin ? null : 'Manually add SiteBuilderPlugin::make() to the selected panel; opt into mediaLibrary() only when local media was selected.')),
            new DoctorCheck('assets.filament', $current ? 'pass' : 'action', 'editor-assets', 'Installed local editor/runtime assets must match published hashes.', $current ? null : 'Apply the reviewed editor assets; existing host assets remain protected.')];
    }
}
