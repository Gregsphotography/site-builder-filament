<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Livewire\Livewire;
use SiteBuilder\Filament\Forms\ComponentFormRegistry;
use SiteBuilder\Filament\Resources\Pages\PageEditor;
use SiteBuilder\Filament\Tests\Fixtures\EditorUiTestCase;

class ComponentIllustrationTest extends EditorUiTestCase
{
    public function test_installed_standard_forms_have_decorative_drawings_and_unknown_forms_have_a_fallback(): void
    {
        $fallback = $this->normalizedIllustration('host.unknown');
        $forms = app(ComponentFormRegistry::class)->all();
        $standard = array_filter($forms, fn ($form): bool => str_starts_with($form->type(), 'standard.'));

        $this->assertNotEmpty($standard);
        foreach ($standard as $form) {
            $drawing = $this->normalizedIllustration($form->type());
            $this->assertNotSame($fallback, $drawing, $form->type().' should have its own illustration.');
            $this->assertStringContainsString('aria-hidden="true"', $drawing);
            $this->assertStringContainsString('focusable="false"', $drawing);
        }

        $this->assertSame($fallback, $this->normalizedIllustration(null));
        $this->assertSame($this->normalizedIllustration('standard.hero'), $this->normalizedIllustration('demo.hero'));
    }

    public function test_picker_uses_the_corresponding_component_drawing_and_a_fallback_for_templates(): void
    {
        $page = $this->page();
        $html = Livewire::test(PageEditor::class, ['record' => $page->id])->call('openPicker')->html();
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);

        foreach (['Video' => 'standard.video', 'Image gallery' => 'standard.gallery', 'Home' => null] as $label => $type) {
            $matches = $xpath->query('//button[@data-picker-entry and .//strong[normalize-space(.)="'.$label.'"]]//*[local-name()="svg"]');
            $this->assertSame(1, $matches->length, 'Expected one picker drawing for '.$label);
            $this->assertSame($this->svg($this->illustration($type))->C14N(), $matches->item(0)->C14N());
        }
    }

    private function illustration(?string $type): string
    {
        return view('site-builder-filament::pages.component-illustration', ['type' => $type])->render();
    }

    private function normalizedIllustration(?string $type): string
    {
        return preg_replace('/>\s+</', '><', trim($this->illustration($type)));
    }

    private function svg(string $html): DOMElement
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $element = $document->getElementsByTagName('svg')->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }
}
