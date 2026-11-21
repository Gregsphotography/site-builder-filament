<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use Livewire\Livewire;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Resources\SiteParts\CreateSitePart;
use SiteBuilder\Filament\Resources\SiteParts\SitePartEditor;
use SiteBuilder\Filament\Resources\SiteParts\SitePartResource;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

final class AccessibilityReviewTest extends EditorUiTestCase
{
    public function test_review_uses_the_selected_language_and_saved_draft_and_targets_its_input(): void
    {
        $image = new Node('portrait', 'standard.image', props: new JsonObject(['image' => '/images/staff.jpg', 'decorative' => false]), translations: new JsonObject(['en' => ['alt' => 'Studio team'], 'de' => ['alt' => '']]));
        $decor = new Node('texture', 'standard.image', props: new JsonObject(['image' => '/images/texture.jpg', 'decorative' => true]));
        $hidden = new Node('hidden', 'standard.image', props: new JsonObject(['image' => '/images/hidden.jpg']), visibility: 'hidden');
        $page = $this->page('team', new Document([$image, $decor, $hidden]));
        $page->forceFill(['status' => 'published', 'published_content' => new Document])->save();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->assertSee('Accessibility')
            ->call('reviewAccessibility')->assertSet('accessibilityReview.findings', [])
            ->call('changeLocale', 'de')->assertSee('Check this draft again')
            ->call('reviewAccessibility')->assertSee('Describe this image or mark it decorative.')
            ->assertSet('accessibilityReview.locale', 'de')->assertSet('accessibilityReview.findings.0.node', 'portrait')
            ->call('openAccessibilityFinding', 0)->assertSet('selectedId', 'portrait');
        $this->assertCount(1, $editor->get('accessibilityReview.findings'));
        $editor->assertReturned(fn (array $result): bool => $result['accessibilityField'] === 'alt');
        $editor->set('formData.alt', 'Das Studioteam')->call('reviewAccessibility')->assertSet('accessibilityReview.findings', []);
        $this->assertCount(0, $page->fresh()->published_content->nodes);
    }

    public function test_nested_link_names_and_rich_text_content_are_checked_and_repeater_targets_follow_field_keys(): void
    {
        $content = new Node('details', 'standard.text', translations: new JsonObject(['en' => [
            'body' => '<p><a href="/one"><img src="/icon.svg" alt="Opening hours"></a><a href="/two" title="Prices"></a><a href="/three">&nbsp;</a><img src="/diagram.jpg"><img src="/decorative.jpg" alt=""></p>',
            'items' => [['url' => '/named', 'label' => 'Find us'], ['url' => '/hours', 'label' => '']],
        ]]));
        $page = $this->page('links', new Document([$content]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewAccessibility')
            ->assertSee('Give link 3 in this rich text a name.')->assertSee('Add alternative text to image 2 in this rich text')
            ->assertSee('Give this link a label')->assertDontSee('Give link 1 in this rich text')->assertDontSee('Give link 2 in this rich text');
        $findings = $editor->get('accessibilityReview.findings');
        $this->assertCount(3, $findings);
        $this->assertSame(['items', 1, 'label'], $findings[2]['path']);
        $editor->call('openAccessibilityFinding', 2);
        $row = array_keys($editor->get('formData.items'))[1];
        $editor->assertReturned(fn (array $result): bool => $result['accessibilityField'] === 'items.'.$row.'.label');
        $editor->set('formData.items.'.$row.'.label', 'Opening hours')->call('reviewAccessibility');
        $this->assertCount(2, $editor->get('accessibilityReview.findings'));

        $this->permissions['view:'.$page->id] = false;
        $editor->call('reviewAccessibility')->assertForbidden();
    }

    public function test_shared_findings_link_to_the_source_draft_only_if_the_editor_can_view_it(): void
    {
        $this->installOptional('site-parts-migrations');
        Livewire::test(CreateSitePart::class)->set('data.name', 'Shared details')->set('data.kind', 'section')->call('create');
        $part = SitePart::query()->sole();
        $resource = Livewire::test(SitePartEditor::class, ['record' => $part->id])->call('addTreeSection', 'standard.text')
            ->set('formData.items', [['url' => '/contact', 'label' => '']])->call('publish')->assertSet('state', 'saved');
        $page = $this->page();
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewSitePart', $part->id, $part->fresh()->current_revision_id)
            ->set('sitePartConfirmed', true)->call('applySitePart')->call('reviewAccessibility');
        $parameters = ['locale' => 'en', 'node' => $resource->get('selectedId'), 'field' => 'items', 'fieldPath' => ['items', 0, 'label']];
        $link = SitePartResource::getUrl('edit', ['record' => $part->id] + $parameters);
        $editor->assertSee(e($link), false);
        $source = Livewire::withQueryParams($parameters)->test(SitePartEditor::class, ['record' => $part->id]);
        $key = array_key_first($source->get('formData.items'));
        $source->assertSet('initialField', 'formData.items.'.$key.'.label');
        $this->permissions['view:'.$part->id] = false;
        $editor->call('reviewAccessibility')->assertDontSee(e($link), false)->assertSee('Ask the site part editor');
    }

    public function test_contact_destinations_use_their_rendered_value_and_preserve_fallback_names(): void
    {
        $contact = new Node('contact', 'standard.contact-details', translations: new JsonObject(['en' => [
            'items' => [['label' => '', 'value' => 'Reception', 'url' => 'tel:123'], ['label' => 'Email', 'value' => '', 'url' => 'mailto:team@example.test']],
        ]]));
        $page = $this->page('contact', new Document([$contact]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('changeLocale', 'de')->call('reviewAccessibility');
        $this->assertCount(1, $editor->get('accessibilityReview.findings'));
        $editor->assertSet('accessibilityReview.findings.0.path', ['items', 1, 'value'])->call('openAccessibilityFinding', 0);
        $editor->assertReturned(fn (array $result): bool => $result['accessibilityField'] === 'items');
    }

    public function test_variant_checks_follow_the_displayed_images_and_link_names(): void
    {
        $logos = new Node('logos', 'standard.cards', props: new JsonObject(['variant' => 'logos']), translations: new JsonObject(['en' => ['items' => [['heading' => 'Our partner', 'url' => '/partner']]]]));
        $slides = new Node('slides', 'standard.carousel', props: new JsonObject(['variant' => 'testimonials', 'show_image' => false]), translations: new JsonObject(['en' => ['items' => [['title' => '', 'url' => '/private', 'image' => '/team.jpg', 'alt' => '']]]]));
        $timeline = new Node('events', 'standard.timeline', props: new JsonObject(['show_image' => false]), translations: new JsonObject(['en' => ['items' => [['image' => '/past.jpg']]]]));
        $page = $this->page('variants', new Document([$logos, $slides, $timeline]));
        $editor = Livewire::test(PageEditor::class, ['record' => $page->id])->call('reviewAccessibility');
        $this->assertCount(1, $editor->get('accessibilityReview.findings'));
        $editor->assertSet('accessibilityReview.findings.0.node', 'slides')->assertSet('accessibilityReview.findings.0.path', ['items', 0, 'alt']);
        $page->increment('write_version');
        $editor->call('openAccessibilityFinding', 0)->assertSet('state', 'conflict');
    }
}
