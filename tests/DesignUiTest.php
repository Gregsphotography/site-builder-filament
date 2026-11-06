<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use SiteBuilder\Components\ComponentsServiceProvider;
use SiteBuilder\Core\Composition\StartingPointDescriptor;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignCommands;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\SiteBuilderFilamentServiceProvider;
use SiteBuilder\Filament\Tests\Fixtures\EditorPanelProvider;
use SiteBuilder\Filament\Tests\Fixtures\RestrictedDesignTheme;

class DesignUiTest extends DatabaseTestCase
{
    private array $permissions = [];

    protected function getPackageProviders($app): array
    {
        $components = class_exists(ComponentsServiceProvider::class) ? [ComponentsServiceProvider::class] : [];

        return [...parent::getPackageProviders($app), ...$components, BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, LivewireServiceProvider::class, SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class, SchemasServiceProvider::class, InfolistsServiceProvider::class, NotificationsServiceProvider::class, TablesServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class, SiteBuilderFilamentServiceProvider::class, EditorPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ComponentsServiceProvider::class)) {
            $this->markTestSkipped('The optional standard library supplies this integration test theme.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32)), 'site-builder.locales' => ['en' => [], 'de' => []], 'site-builder.layout' => 'components.business']);
        $this->artisan('vendor:publish', ['--tag' => 'site-builder-components-assets', '--force' => true])->assertExitCode(0);
        Filament::setCurrentPanel(Filament::getPanel('package-test'));
        $this->actingAs(new GenericUser(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Design editor', 'email' => 'design@example.test']));
        Gate::before(fn ($actor, string $ability): bool => $this->permissions[$ability] ?? true);
    }

    protected function tearDown(): void
    {
        Livewire::flushState();
        parent::tearDown();
    }

    public function test_design_is_private_shared_across_locales_and_resets_only_the_requested_inheritance_layer(): void
    {
        $page = $this->page();
        $page->forceFill(['status' => 'published', 'published_content' => $page->draft_content, 'published_at' => now()])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('formData.heading', 'Accepted English draft')
            ->set('designData.align', 'center')->call('saveDesign')->assertSet('state', 'saved')->assertSet('writeVersion', 2);
        $this->assertSame('center', $page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render')->design->base->align);
        $this->assertSame('Deutsch bleibt', $page->fresh()->draft_content->nodes[0]->translations->toObject()->de->heading);
        $this->assertFalse(property_exists($page->fresh()->draft_content->nodes[0]->translations->toObject()->de, 'body'));
        $this->assertInstanceOf(\stdClass::class, $page->fresh()->draft_content->nodes[0]->props->toObject()->future->object);
        $this->get('/design-page')->assertOk()->assertSee('Original heading')->assertDontSee('sb-d-align-center');
        $this->get($editor->get('previewUrl'))->assertOk()->assertSee('Accepted English draft')->assertSee('sb-d-align-center');
        $editor->call('changeDesignTarget', 'page', 'wide')->set('designData.space', 'lg')->call('saveDesign')->assertSet('state', 'saved')
            ->call('changeDesignTarget', 'node', 'wide')->assertSet('designData.align', null)->assertSee('Section at All sizes')->assertSee('Page at 72rem+');
        $editor->set('designData.align', 'end')->call('saveDesign')->call('resetDesignKey', 'align')->assertSet('state', 'saved');
        $nodeDesign = $page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render')->design;
        $this->assertSame('center', $nodeDesign->base->align);
        $this->assertFalse(property_exists($nodeDesign, 'responsive'));
        $editor->call('resetDesignScope')->call('publish')->assertSet('state', 'saved');
        $this->assertNull($page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render'));
        $this->assertSame('lg', $page->fresh()->draft_content->extensions->get('site-builder.render')->design->responsive->wide->space);
        $this->get('/design-page')->assertOk()->assertSee('sb-d-w-space-lg')->assertDontSee('sb-d-align-center');
        $revision = $page->revisions()->sole();
        $editor->set('designData.align', 'center')->call('saveDesign')->call('restoreRevision', $revision->id)->assertSet('state', 'saved');
        $this->assertNull($page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render'));
        $this->assertSame('kept', $page->fresh()->draft_content->nodes[0]->extensions->get('unrelated')->value);
    }

    public function test_trusted_combination_choices_are_disabled_and_atomic_pending_options_validate_together(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id]);
        $tone = array_find($editor->instance()->designForm->getComponents(), fn ($field): bool => $field->getName() === 'tone');
        $this->assertTrue($tone->isOptionDisabled('on-accent', 'On accent'));
        $this->assertFalse($tone->isOptionDisabled('muted', 'Muted'));
        $editor->set('designData.surface', 'accent')->set('designData.tone', 'on-accent')->call('saveDesign')->assertSet('state', 'saved')->assertSet('writeVersion', 1);
        $tone = array_find($editor->instance()->designForm->getComponents(), fn ($field): bool => $field->getName() === 'tone');
        $this->assertTrue($tone->isOptionDisabled('muted', 'Muted'));
        $this->assertFalse($tone->isOptionDisabled('on-accent', 'On accent'));
        $digest = app(CanonicalJson::class)->digest($page->fresh()->draft_content);
        $editor->set('designData.align', 'url(javascript:alert(1))')->call('saveDesign')->assertSet('state', 'invalid')->assertSet('designData.align', 'url(javascript:alert(1))');
        $this->assertSame($digest, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
        $editor->set('designData.align', null)->set('designData.surface', 'subtle')->set('designData.tone', 'muted')->call('saveDesign')->assertSet('state', 'saved')->assertSet('writeVersion', 2);
        $this->assertSame('muted', $page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render')->design->base->tone);
    }

    public function test_theme_review_has_real_names_and_requires_confirmation_against_the_current_draft(): void
    {
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('designThemeChoice', 'standard.base@1:editorial')
            ->call('publish')->assertSet('state', 'invalid')->call('applyDesignTheme')->assertSet('state', 'invalid');
        $this->assertNull($page->fresh()->published_content);
        $editor->call('prepareDesignTheme')->assertSet('designThemeReview.report.compatible', true)->assertSee('Editorial')->assertSee('Business')
            ->set('designThemeConfirmed', true)->call('applyDesignTheme')->assertSet('state', 'saved');
        $this->assertSame('editorial', $page->fresh()->draft_content->extensions->get('site-builder.render')->design->theme->preset);
        $this->assertSame('components.business', $page->fresh()->layout);
        $editor->set('designThemeChoice', 'standard.base@1:minimal')->call('prepareDesignTheme')->set('designThemeConfirmed', true);
        app(PageCommands::class)->saveMetadata(auth()->user(), $page->id, $editor->get('leaseToken'), $editor->get('writeVersion'), ['title' => 'Newer metadata']);
        $editor->call('applyDesignTheme')->assertSet('state', 'conflict')->assertSet('designThemeChoice', 'standard.base@1:minimal');
        $this->assertSame('editorial', $page->fresh()->draft_content->extensions->get('site-builder.render')->design->theme->preset);
    }

    public function test_unknown_render_features_disable_controls_and_known_incompatible_options_can_be_explicitly_reset(): void
    {
        $page = $this->page();
        $document = $page->draft_content;
        $node = $document->nodes[0];
        $unknown = new Node($node->id, $node->type, props: $node->props, translations: $node->translations, extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'future' => (object) ['version' => 9, 'opaque' => (object) []]]]));
        $page->forceFill(['draft_content' => new Document([$unknown])])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertDontSee('Save design to draft')->assertSet('designData', []);
        $before = app(CanonicalJson::class)->digest($page->fresh()->draft_content);
        $editor->set('designData.align', 'center')->call('saveDesign')->assertSet('state', 'invalid');
        $this->assertSame($before, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
        $second = $this->page('incompatible');
        $oldNode = $second->draft_content->nodes[0];
        $second->forceFill(['draft_content' => new Document([new Node($oldNode->id, $oldNode->type, props: $oldNode->props, translations: $oldNode->translations, extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'base' => (object) ['align' => 'future-alignment']]]]))], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'standard.base', 'version' => 1, 'preset' => 'business']]]]))])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $second->id])->assertDontSee('Save design to draft')->assertSee('Reset incompatible section overrides');
        $this->assertSame('future-alignment', $second->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render')->design->base->align);
        $editor->call('resetDesignScope')->assertSet('state', 'saved')->assertSee('Save design to draft');
        $this->assertNull($second->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render'));
    }

    public function test_custom_breakpoints_and_restricted_variants_are_taken_from_the_host_adapter(): void
    {
        $catalog = app(DesignCatalog::class);
        $catalog->register(new RestrictedDesignTheme);
        $catalog->bindDefault(fn (RenderContext $context): ?ThemeIdentity => $context->layout === 'test.custom' ? new ThemeIdentity('test.restricted', 1, 'one') : null);
        view()->addNamespace('test-blocks', __DIR__.'/Support/Core/Fixtures/views');
        app(LayoutRegistry::class)->register('test.custom', 'test-blocks::layout');
        $page = $this->page('custom');
        $page->update(['layout' => 'test.custom']);
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('100rem+')
            ->call('changeDesignTarget', 'node', 'cinema')->set('designData.align', 'center')->call('saveDesign')->assertSet('state', 'saved');
        $this->assertSame('center', $page->fresh()->draft_content->nodes[0]->extensions->get('site-builder.render')->design->responsive->cinema->align);
        $editor->call('openPicker')->call('reviewStartingPoint', 'component:standard.hero')->assertSet('pickerReview.reviewed', false)
            ->assertSet('pickerReview.variants', ['split' => 'Split']);
        $editor->call('reviewStartingPoint', 'component:standard.hero', 'centred')->assertSet('state', 'invalid');
        $editor->call('reviewStartingPoint', 'component:standard.hero', 'split')->assertSet('state', 'saved')->assertSet('pickerReferences.blocking', false);
    }

    public function test_http_picker_preview_projects_destination_design_and_checked_media_without_writing(): void
    {
        $sourceImage = '/vendor/site-builder-components/examples/workspace.svg';
        $mappedImage = '/vendor/site-builder-components/examples/landscape.svg';
        app(StartingPointRegistry::class)->register(new StartingPointDescriptor('component:designed-image', 1, 'Designed image'), fn (): Document => new Document([
            new Node('source-image', 'standard.image', props: new JsonObject(['variant' => 'default', 'image' => $sourceImage, 'decorative' => false]), translations: new JsonObject(['en' => (object) ['alt' => 'Source image']]), extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'base' => (object) ['border' => 'strong']]]])),
        ], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'standard.base', 'version' => 1, 'preset' => 'minimal'], 'base' => (object) ['space' => 'sm', 'align' => 'end']]]])));
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->set('designThemeChoice', 'standard.base@1:editorial')
            ->call('prepareDesignTheme')->set('designThemeConfirmed', true)->call('applyDesignTheme')->call('changeDesignTarget', 'page')
            ->set('designData.space', 'xl')->set('designData.align', 'start')->call('saveDesign')->call('openPicker')
            ->call('reviewStartingPoint', 'component:designed-image')->assertSet('pickerReferences.blocking', false);
        $beforeVersion = $page->fresh()->write_version;
        $beforeDigest = app(CanonicalJson::class)->digest($page->fresh()->draft_content);
        $this->get($editor->get('pickerPreviewUrl'))->assertOk()->assertSee('data-site-builder-theme="editorial"', false)
            ->assertSee('sb-d-space-xl')->assertSee('sb-d-align-start')->assertSee('sb-d-border-strong')->assertSee($sourceImage)
            ->assertDontSee('sb-d-space-sm')->assertDontSee('sb-d-align-end')->assertDontSee('Original heading')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('site-builder-preview-context');
        $reference = $editor->get('pickerReferences')['occurrences'][0];
        $editor->set('pickerReferenceValues.'.$reference['key'].'.value', $mappedImage)->call('reviewStartingPointReferences')->assertSet('pickerReferences.blocking', false);
        $url = $editor->get('pickerPreviewUrl');
        $this->get($url)->assertOk()->assertSee($mappedImage)->assertDontSee($sourceImage)->assertSee('sb-d-border-strong');
        $this->get($url.'&map=forged')->assertForbidden();
        $this->assertSame($beforeVersion, $page->fresh()->write_version);
        $this->assertSame($beforeDigest, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
        $this->assertSame(1, Page::query()->count());
        app(DesignCommands::class)->set(auth()->user(), $page->id, $editor->get('leaseToken'), $beforeVersion, DesignTarget::document(), 'align', 'end');
        $this->get($url)->assertStatus(409)->assertSee('Preview unavailable');
    }

    private function page(string $slug = 'design-page'): Page
    {
        $page = Page::factory()->create(['title' => 'Design page', 'layout' => 'components.business', 'draft_content' => new Document([
            new Node('first', 'standard.text', props: new JsonObject(['variant' => 'default', 'future' => (object) ['object' => (object) [], 'list' => []]]), translations: new JsonObject(['en' => (object) ['heading' => 'Original heading', 'body' => '<p>English body.</p>'], 'de' => (object) ['heading' => 'Deutsch bleibt']]), extensions: new JsonObject(['unrelated' => (object) ['value' => 'kept']])),
        ])]);
        $page->slugs()->create(['locale' => 'en', 'slug' => $slug]);

        return $page;
    }
}
