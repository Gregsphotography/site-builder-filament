<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Illuminate\Support\Str;
use Livewire\Livewire;
use SiteBuilder\Filament\Pages\FormInbox;
use SiteBuilder\Filament\Pages\FormProfiles;
use SiteBuilder\Filament\Support\OptionalForms;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class FormsUiTest extends EditorUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => realpath(dirname((new \ReflectionClass(\SiteBuilder\Components\ComponentsServiceProvider::class))->getFileName(), 2).'/database/forms-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        config(['site-builder-forms.enabled' => true, 'site-builder-forms.installation_id' => '019a0000-0000-7000-8000-000000000099']);
    }

    public function test_profile_management_requires_permissions_and_stale_edits_retain_input(): void
    {
        $editor = Livewire::test(FormProfiles::class)->set('data.name', 'Contact desk')->set('data.recipients_text', 'contact@example.test')->set('data.active', true)->call('save')->assertHasNoErrors();
        $class = OptionalForms::PROFILE;
        $profile = $class::query()->sole();
        $this->assertSame(['contact@example.test'], $profile->recipients);
        $editor->call('edit', $profile->id)->set('data.name', 'My pending change');
        $profile->forceFill(['name' => 'Another admin changed it', 'write_version' => 1])->save();
        $editor->call('save')->assertHasErrors('data.name')->assertSet('data.name', 'My pending change');
        $this->assertSame('Another admin changed it', $profile->fresh()->name);
        $this->permissions['create'] = false;
        $editor->call('newProfile')->assertForbidden();
    }

    public function test_inbox_escapes_visitor_content_and_checks_each_record_action(): void
    {
        $class = OptionalForms::SUBMISSION;
        $record = $class::query()->create(['profile_id' => (string) Str::uuid(), 'page_id' => null, 'locale' => 'en', 'form_address' => 'body/contact', 'form_title' => 'Contact', 'form_digest' => str_repeat('a', 64), 'request_token' => (string) Str::uuid(), 'fields' => ['message' => ['label' => 'Message', 'type' => 'textarea', 'value' => '<img onerror="bad()">']]]);
        $editor = Livewire::test(FormInbox::class)->call('open', $record->id)->assertSee('&lt;img onerror=', false)->assertDontSee('<img onerror=', false);
        $this->assertNotNull($record->fresh()->read_at);
        $editor->call('markUnread', $record->id)->assertOk();
        $this->assertNull($record->fresh()->read_at);
        $editor->call('archive', $record->id)->assertOk();
        $this->assertNotNull($record->fresh()->archived_at);
        $this->permissions['delete'] = false;
        $editor->call('delete', $record->id)->assertForbidden();
        $this->assertSame(1, $class::query()->count());
    }
}
