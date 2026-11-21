<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use SiteBuilder\Components\Albums\Album;
use SiteBuilder\Components\Albums\AlbumLibrary;
use SiteBuilder\Components\Media\MediaLibrary;
use SiteBuilder\Filament\Resources\Albums\AlbumResource;
use SiteBuilder\Filament\Resources\Albums\CreateAlbum;
use SiteBuilder\Filament\Resources\Albums\EditAlbum;
use SiteBuilder\Filament\Resources\Albums\ListAlbums;
use SiteBuilder\Filament\SiteBuilderPlugin;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class AlbumsUiTest extends EditorUiTestCase
{
    private ?string $storage = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('site-builder-components.album_routes', true);
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AlbumTestPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Components\ComponentsServiceProvider::class))->getFileName(), 2).'/database/migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        Filament::setCurrentPanel(Filament::getPanel('album-test'));
        $this->storage = sys_get_temp_dir().'/site-builder-albums-ui-'.Str::uuid();
        $this->app->useStoragePath($this->storage);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
        }

        parent::tearDown();
    }

    public function test_editor_creates_searches_and_publishes_an_ordered_album_without_changing_its_url_on_title_edits(): void
    {
        $media = app(MediaLibrary::class);
        $first = $media->store(UploadedFile::fake()->image('first.jpg'), ['en' => 'First photo']);
        $second = $media->store(UploadedFile::fake()->image('second.jpg'), ['en' => 'Second photo']);
        $create = Livewire::test(CreateAlbum::class)->fillForm([
            'title' => 'Summer trip', 'slug' => 'summer-trip', 'cover' => $first->path,
            'items' => [['image' => $first->path, 'alt' => 'First photo'], ['image' => $second->path, 'alt' => 'Second photo']],
        ])->call('create')->assertHasNoFormErrors();
        $album = Album::query()->sole();
        $create->assertRedirect(AlbumResource::getUrl('edit', ['record' => $album]));
        $this->assertNull($album->published);
        $other = app(AlbumLibrary::class)->create(['title' => 'Winter', 'slug' => 'winter']);
        Livewire::test(ListAlbums::class)->searchTable('Summer')->assertCanSeeTableRecords([$album])->assertCanNotSeeTableRecords([$other]);

        $editor = Livewire::test(EditAlbum::class, ['record' => $album->id]);
        $rows = $editor->get('data.items');
        $keys = array_keys($rows);
        $rows[$keys[1]]['caption'] = 'Glacier';
        $editor->set('data.items', [$keys[1] => $rows[$keys[1]], $keys[0] => $rows[$keys[0]]])
            ->callAction('publish')->assertHasNoFormErrors();
        $published = $album->fresh()->published;
        $this->assertSame([$second->path, $first->path], array_column($published['items'], 'image'));
        $this->assertSame('Glacier', $published['items'][0]['caption']);
        $this->assertSame($first->path, $published['cover']);
        $editor->set('data.title', 'A new title')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Summer trip', $album->fresh()->published['title']);
        $this->assertSame('A new title', $album->fresh()->draft['title']);
        $this->assertSame('summer-trip', $album->fresh()->draft['slug']);

        Livewire::test(ListAlbums::class)->assertSee('Unpublished changes');
        $editor->assertActionVisible('open')->set('data.title', 'Still editing')
            ->mountAction('unpublish')->assertActionMounted('unpublish')
            ->callMountedAction()->assertHasNoFormErrors()->assertActionHidden('open')
            ->assertSet('data.title', 'Still editing');
        $this->assertNull($album->fresh()->published_slug);
        $this->assertSame('A new title', $album->fresh()->draft['title']);
        $editor->callAction('publish')->assertHasNoFormErrors()->assertActionVisible('open');
        $this->assertSame('Still editing', $album->fresh()->published['title']);
    }

    public function test_stale_editors_and_denied_publishers_cannot_replace_a_draft_or_publish_it(): void
    {
        $album = app(AlbumLibrary::class)->create(['title' => 'Winter', 'slug' => 'winter']);
        $stale = Livewire::test(EditAlbum::class, ['record' => $album->id]);
        Livewire::test(EditAlbum::class, ['record' => $album->id])->set('data.title', 'Current')->call('save')->assertHasNoFormErrors();
        $stale->set('data.title', 'Stale')->call('save')->assertHasFormErrors(['title']);
        $this->assertSame('Current', $album->fresh()->draft['title']);
        $this->permissions['publish'] = false;
        Livewire::test(EditAlbum::class, ['record' => $album->id])->assertActionHidden('publish')
            ->call('mountAction', 'publish')->call('callMountedAction');
        $this->assertNull($album->fresh()->published);
        $this->permissions['create'] = false;
        Livewire::test(CreateAlbum::class)->assertForbidden();
        $this->permissions['update'] = false;
        Livewire::test(EditAlbum::class, ['record' => $album->id])->assertForbidden();
    }

    public function test_invalid_publication_identifies_the_photo_row_and_keeps_unsaved_form_edits(): void
    {
        $photo = app(MediaLibrary::class)->store(UploadedFile::fake()->image('lake.jpg'));
        $album = app(AlbumLibrary::class)->create([
            'title' => 'Lake', 'slug' => 'lake', 'items' => [['image' => $photo->path]],
        ]);
        $editor = Livewire::test(EditAlbum::class, ['record' => $album->id]);
        $row = array_key_first($editor->get('data.items'));
        $editor->set('data.title', 'Lake at sunset')->callAction('publish')
            ->assertHasErrors(['data.items.'.$row.'.alt'])->assertSet('data.title', 'Lake at sunset');
        $this->assertSame('Lake', $album->fresh()->draft['title']);
        $this->assertNull($album->fresh()->published);
        $editor->set('data.items.'.$row.'.alt', 'Sunset reflected in the lake')
            ->callAction('publish')->assertHasNoFormErrors();
        $this->assertSame('Lake at sunset', $album->fresh()->published['title']);
    }
}

class AlbumTestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('album-test')->path('album-test')->plugins([SiteBuilderPlugin::make()->mediaLibrary()]);
    }
}
