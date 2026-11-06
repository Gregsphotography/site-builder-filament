<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Forms;

use InvalidArgumentException;

final readonly class BatchMediaSelection
{
    /** @param list<array{index:int,reference:string,alt:string,width:?int,height:?int}> $accepted @param array<int,string> $failures */
    public function __construct(public array $accepted, public array $failures)
    {
        $previous = -1;
        if (! array_is_list($accepted) || count($accepted) + count($failures) > 48) {
            throw new InvalidArgumentException('Bounded media selection required.');
        }
        foreach ($accepted as $item) {
            if (array_diff(array_keys($item), ['index', 'reference', 'alt', 'width', 'height']) !== [] || ! is_int($item['index'] ?? null) || $item['index'] <= $previous || $item['index'] >= 48 || ! is_string($item['reference'] ?? null) || $item['reference'] === '' || strlen($item['reference']) > 2048 || ! is_string($item['alt'] ?? null) || mb_strlen($item['alt']) > 2000) {
                throw new InvalidArgumentException('Invalid selected media.');
            }
            foreach (['width', 'height'] as $dimension) {
                if (($item[$dimension] ?? null) !== null && (! is_int($item[$dimension]) || $item[$dimension] < 1 || $item[$dimension] > 20000)) {
                    throw new InvalidArgumentException('Invalid selected media dimensions.');
                }
            }
            $previous = $item['index'];
        }
        foreach ($failures as $index => $message) {
            if (! is_int($index) || $index < 0 || $index >= 48 || ! is_string($message) || mb_strlen($message) > 255 || in_array($index, array_column($accepted, 'index'), true)) {
                throw new InvalidArgumentException('Invalid selection failure.');
            }
        }
    }

    public function toArray(): array
    {
        return ['accepted' => $this->accepted, 'failures' => $this->failures];
    }
}
