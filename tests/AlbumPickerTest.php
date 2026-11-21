<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use SiteBuilder\Components\Albums\AlbumLibrary;
use SiteBuilder\Components\Media\MediaLibrary;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class AlbumPickerTest extends EditorUiTestCase
{
    public function test_page_editor_searches_published_album_titles_and_saves_the_stable_reference(): void
    {
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Components\ComponentsServiceProvider::class))->getFileName(), 2).'/database/migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        config(['filesystems.disks.public.visibility' => 'public']);
        Storage::fake('public');
        $photo = app(MediaLibrary::class)->store(UploadedFile::fake()->image('lake.jpg'));
        $albums = app(AlbumLibrary::class);
        $album = $albums->create(['title' => 'Summer trip', 'slug' => 'summer', 'items' => [['image' => $photo->path, 'alt' => 'Lake']]]);
        $album = $albums->publish($album, $album->write_version);
        $albums->saveDraft($album, [...$album->draft, 'title' => 'Private new title'], $album->write_version);
        $albums->create(['title' => 'Private new album', 'slug' => 'private']);

        $page = $this->page('album-picker', new Document([new Node('content', 'standard.section')]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])
            ->call('addTreeSection', 'standard.album', 'content')->assertSet('state', 'saved')->assertSee('Published album');
        $select = $editor->instance()->sectionForm->getComponents()[0];
        $this->assertSame([$album->id => 'Summer trip'], $select->getSearchResults('Summer'));
        $this->assertSame([], $select->getSearchResults('Private'));
        $editor->set('formData.album', $album->id)->call('saveSelected')->assertHasNoErrors()->assertSet('state', 'saved');
        $this->assertSame($album->id, $page->fresh()->draft_content->nodes[0]->children[0]->props->get('album'));
    }
}
