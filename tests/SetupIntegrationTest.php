<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use SiteBuilder\Core\Installation\SetupAnswers;
use SiteBuilder\Core\Installation\SetupFilesystem;
use SiteBuilder\Core\Installation\SetupManager;
use SiteBuilder\Core\Installation\SetupRegistry;
use SiteBuilder\Filament\Pages\MediaLibrary;
use SiteBuilder\Filament\Support\ComponentMediaPicker;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class SetupIntegrationTest extends EditorUiTestCase
{
    public function test_optional_contributors_plan_only_installed_local_assets_and_preserve_explicit_media_denial(): void
    {
        $directory = sys_get_temp_dir().'/site-builder-editor-setup-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/public');
        $original = public_path();
        app()->usePublicPath(realpath($directory).'/public');
        app()->instance(SetupFilesystem::class, new SetupFilesystem($directory));
        try {
            $registry = app(SetupRegistry::class);
            $this->assertSame(['core', 'components', 'filament'], array_keys($registry->all()));
            $value = json_decode(file_get_contents(__DIR__.'/Fixtures/setup-answers.json'));
            $value->registration->panelId = Filament::getCurrentPanel()->getId();
            $value->authorization->mode = 'existing';
            $value->authorization->guard = Filament::getCurrentPanel()->getAuthGuard();
            $answers = new SetupAnswers($value);
            $manager = app(SetupManager::class);
            $plan = $manager->plan($answers);
            $this->assertSame('ready-to-apply', $plan->report['status']);
            $paths = array_column($plan->report['operations'], 'path');
            $this->assertContains('public/vendor/site-builder-components/gallery.js', $paths);
            $this->assertContains('public/vendor/site-builder-filament/site-builder.js', $paths);
            $this->assertTrue(array_any($paths, fn ($path) => str_starts_with($path ?? '', 'public/js/filament/')));
            $this->assertSame([], File::allFiles($directory));
            $receipt = $manager->apply($plan);
            $this->assertSame('complete', $receipt['status']);
            $this->assertSame('needs-action', $receipt['readiness']);
            $this->assertSame('ready-to-apply', $manager->plan($answers)->report['status']);
            $value->authorization = (object) ['mode' => 'deny', 'guard' => 'web', 'actorAlias' => null, 'actorKeys' => [], 'pageAbilities' => [], 'sitePartAbilities' => (object) [], 'mediaAbilities' => []];
            config(['site-builder-setup.answers' => $value]);
            app()->instance('site-builder.setup.active', true);
            $this->assertFalse(MediaLibrary::canAccess());
            $picker = new ComponentMediaPicker(app(\SiteBuilder\Components\Media\MediaLibrary::class));
            $this->assertSame([], $picker->options('en'));
        } finally {
            app()->usePublicPath($original);
            File::deleteDirectory($directory);
        }
    }
}
