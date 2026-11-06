<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests;

use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\TestCase;
use SiteBuilder\Filament\Forms\NodeFormState;

class NodeFormStateTest extends TestCase
{
    public function test_patches_only_changed_dehydrated_fields_without_copying_fallback_or_losing_unmounted_data(): void
    {
        $node = new Node('stable', 'test.block', props: new JsonObject(['tone' => 'brand', 'unmounted' => (object) ['empty' => (object) [], 'list' => []]]), translations: new JsonObject(['en' => (object) ['heading' => 'Default', 'body' => '<p>Default body</p>'], 'de' => (object) ['heading' => null, 'future' => (object) []], 'xx' => (object) ['heading' => 'Retain me']]));
        $mapper = app(NodeFormState::class);
        $definition = new RenderBlock;
        $projected = $mapper->project($node, $definition, 'de');
        $this->assertNull($projected['heading']);
        $this->assertArrayNotHasKey('body', $projected);
        $before = ['heading' => null, 'body' => ['type' => 'doc', 'content' => []], 'tone' => 'brand'];
        $after = [...$before, 'heading' => 'Geändert'];
        $patched = $mapper->patch($node, $definition, 'de', ['heading' => 'Geändert', 'body' => '<p></p>'], $before, $after);
        $this->assertSame('Geändert', $patched->translations->toObject()->de->heading);
        $this->assertFalse(property_exists($patched->translations->toObject()->de, 'body'));
        $this->assertInstanceOf(\stdClass::class, $patched->translations->toObject()->de->future);
        $this->assertSame('Default', $patched->translations->toObject()->en->heading);
        $this->assertSame('Retain me', $patched->translations->toObject()->xx->heading);
        $this->assertTrue(app(CanonicalJson::class)->equals($node->props, $patched->props));
    }

    public function test_reordered_repeater_rows_keep_their_own_unknown_fields_and_json_container_shapes(): void
    {
        $rows = [(object) ['label' => 'One', 'url' => '/one', 'future' => (object) ['object' => (object) [], 'list' => [], 'null' => null]], (object) ['label' => 'Two', 'url' => '/two', 'future' => 'row two']];
        $node = new Node('stable', 'test.block', translations: new JsonObject(['en' => (object) ['items' => $rows], 'de' => (object) ['items' => [(object) ['label' => 'Eins']]]]));
        $baseline = ['items' => ['row-one' => ['label' => 'One', 'url' => '/one'], 'row-two' => ['label' => 'Two', 'url' => '/two']]];
        $current = ['items' => ['row-two' => ['label' => 'Two edited', 'url' => '/two'], 'row-one' => ['label' => 'One', 'url' => '/one'], 'new-row' => ['label' => 'Three', 'url' => '/three']]];
        $values = ['items' => [['label' => 'Two edited', 'url' => '/two'], ['label' => 'One', 'url' => '/one'], ['label' => 'Three', 'url' => '/three']]];
        $patched = app(NodeFormState::class)->patch($node, new RenderBlock, 'en', $values, $baseline, $current);
        $items = $patched->translations->toObject()->en->items;
        $this->assertSame('row two', $items[0]->future);
        $this->assertSame('Two edited', $items[0]->label);
        $this->assertInstanceOf(\stdClass::class, $items[1]->future->object);
        $this->assertSame([], $items[1]->future->list);
        $this->assertNull($items[1]->future->null);
        $this->assertSame('Three', $items[2]->label);
        $this->assertSame('Eins', $patched->translations->toObject()->de->items[0]->label);
        $empty = app(NodeFormState::class)->patch($patched, new RenderBlock, 'en', ['items' => []], $current, ['items' => []]);
        $this->assertSame([], $empty->translations->toObject()->en->items);
        $this->assertCount(1, $empty->translations->toObject()->de->items);
    }

    public function test_trusted_clone_provenance_retains_unmounted_row_data(): void
    {
        $original = (object) ['label' => 'One', 'future' => (object) ['object' => (object) [], 'list' => [], 'null' => null]];
        $node = new Node('stable', 'test.block', translations: new JsonObject(['en' => (object) ['items' => [$original]]]));
        $before = ['items' => ['first' => ['label' => 'One']]];
        $current = ['items' => ['first' => ['label' => 'One'], 'clone' => ['label' => 'Edited clone']]];
        $patch = app(NodeFormState::class)->patch($node, new RenderBlock, 'en', ['items' => [['label' => 'One'], ['label' => 'Edited clone']]], $before, $current, ['items' => ['clone' => 'first']]);
        $rows = $patch->translations->toObject()->en->items;
        $this->assertSame('Edited clone', $rows[1]->label);
        $this->assertTrue(app(CanonicalJson::class)->equals($rows[0]->future, $rows[1]->future));
        $this->assertInstanceOf(\stdClass::class, $rows[1]->future->object);
        $this->assertSame([], $rows[1]->future->list);
        $this->assertNull($rows[1]->future->null);
    }
}
