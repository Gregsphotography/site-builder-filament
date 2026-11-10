<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Livewire\Livewire;
use SiteBuilder\Components\Examples\StandardExamples;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Filament\Contracts\ComponentForm;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Forms\EditorContext;
use SiteBuilder\Filament\Pages\ComponentLibrary;
use SiteBuilder\Filament\Support\ComponentExamples;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class ComponentLibraryExampleTest extends EditorUiTestCase
{
    public function test_all_installed_standard_examples_have_a_preview_or_contextual_explanation(): void
    {
        foreach (array_keys(app(StandardExamples::class)->entries()) as $type) {
            $example = app(ComponentExamples::class)->for($type);
            $this->assertNotNull($example, "No preview rendered for {$type}");
            if (in_array($type, ['standard.anchor', 'standard.breadcrumbs', 'standard.table-of-contents'], true)) {
                $this->assertNull($example['html'], $type);
                $this->assertNotEmpty($example['note'], $type);
            } else {
                $this->assertStringContainsString('data-site-builder-node', $example['html'], $type);
            }
        }
    }

    public function test_standard_examples_render_without_reading_or_changing_a_page(): void
    {
        $page = $this->page();
        $original = json_encode($page->fresh()->draft_content, JSON_THROW_ON_ERROR);

        $library = Livewire::test(ComponentLibrary::class)
            ->assertSee('View example of Hero')
            ->call('showExample', 'standard.hero')
            ->assertDispatched('open-modal', id: 'sb-component-example')
            ->assertSet('exampleLabel', 'Hero')
            ->assertSee('sandbox="allow-same-origin"', false);

        $example = $library->get('exampleHtml');
        $this->assertStringContainsString('Make room for your next idea', $example);
        $this->assertStringContainsString('Content-Security-Policy', $example);
        $this->assertStringContainsString('/vendor/site-builder-components/components.css', $example);
        $this->assertStringNotContainsString('Existing page content', $example);
        $this->assertSame($original, json_encode($page->fresh()->draft_content, JSON_THROW_ON_ERROR));
        $this->assertSame(1, Page::query()->count());

        $library->call('showExample', 'standard.gallery')->assertSet('exampleLabel', 'Image gallery');
        $this->assertStringContainsString('A few moments worth sharing', $library->get('exampleHtml'));
        $this->assertStringContainsString('/vendor/site-builder-components/examples/landscape', $library->get('exampleHtml'));
    }

    public function test_contextual_and_legacy_examples_explain_what_they_show(): void
    {
        $library = Livewire::test(ComponentLibrary::class)->call('showExample', 'standard.column')
            ->assertSet('exampleNote', 'A column is shown here within a columns layout.');
        $this->assertStringContainsString('data-site-builder-node', $library->get('exampleHtml'));

        $library->call('showExample', 'demo.hero')
            ->assertSet('exampleNote', 'Example of the equivalent standard component; this component may look different.');
        $this->assertStringContainsString('Make room for your next idea', $library->get('exampleHtml'));

        foreach (['standard.tab-panel' => 'A tab panel is shown here within a tabs layout.', 'standard.accordion-item' => 'An accordion item is shown here within an accordion.'] as $type => $note) {
            if (isset(app(ComponentFormRegistry::class)->all()[$type])) {
                $library->call('showExample', $type)->assertSet('exampleNote', $note);
                $this->assertNotNull($library->get('exampleHtml'));
            }
        }
    }

    public function test_unavailable_examples_are_explained_and_unknown_types_are_rejected(): void
    {
        app(ComponentRegistry::class)->register(RenderBlock::class);
        app(ComponentFormRegistry::class)->register(new class implements ComponentForm
        {
            public function type(): string
            {
                return 'test.block';
            }

            public function label(): string
            {
                return 'Custom block';
            }

            public function icon(): string
            {
                return 'heroicon-o-star';
            }

            public function category(): string
            {
                return 'Custom';
            }

            public function schema(EditorContext $context): array
            {
                return [];
            }
        });

        Livewire::test(ComponentLibrary::class)->call('showExample', 'test.block')
            ->assertSet('exampleHtml', null)->assertSee('No example is available for this component yet.')
            ->call('showExample', 'not.registered')->assertNotFound();

        $this->app->forgetInstance('SiteBuilder\\Components\\Examples\\StandardExamples');
        $this->app->offsetUnset('SiteBuilder\\Components\\Examples\\StandardExamples');
        $this->assertNull(app(ComponentExamples::class)->for('standard.hero'));
    }

    public function test_example_requests_recheck_the_library_permission(): void
    {
        $library = Livewire::test(ComponentLibrary::class)->call('showExample', 'standard.hero');
        $this->permissions['viewAny'] = false;
        $library->call('showExample', 'standard.gallery')->assertForbidden();
    }
}
