<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Tests\Fixtures;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Filament\Forms\TreePresentation;

/** Explicit development measurement; excluded from the default *Test.php suite. */
class TreePresentationBenchmark extends EditorUiTestCase
{
    public function test_measure_bound_with_real_known_component_validation(): void
    {
        $measurements = [];
        foreach ([150, 500, 1000] as $count) {
            $nodes = [];
            for ($index = 0; $index < $count; $index++) {
                $nodes[] = new Node('measured-'.$index, 'standard.text', translations: new JsonObject(['en' => (object) ['heading' => 'Known block '.$index, 'body' => '<p>Measured authored content.</p>']]));
            }
            $selected = 'measured-'.intdiv($count, 2);
            $started = hrtime(true);
            $result = app(TreePresentation::class)->describe(new Document($nodes), new RenderContext('en', 'preview', layout: 'components.business'), $selected);
            $milliseconds = (hrtime(true) - $started) / 1e6;
            $this->assertSame($count, $result['nodeCount']);
            $this->assertSame($count, count($result['sections']));
            $this->assertLessThanOrEqual(5, $result['candidateChecks']);
            foreach ($result['sections'] as $row) {
                if ($row['id'] !== $selected) {
                    $this->assertFalse($row['canMoveUp']);
                    $this->assertFalse($row['canMoveDown']);
                    $this->assertFalse($row['canMoveIn']);
                    $this->assertFalse($row['canMoveOut']);
                    $this->assertFalse($row['canDuplicate']);
                    $this->assertFalse($row['canRemove']);
                }
            }
            $measurements[] = ['nodes' => $count, 'candidateChecks' => $result['candidateChecks'], 'milliseconds' => round($milliseconds, 2)];
        }
        fwrite(STDERR, json_encode(['treePresentation' => $measurements], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
