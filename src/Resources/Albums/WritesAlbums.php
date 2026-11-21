<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Resources\Albums;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Components\Albums\Album;
use SiteBuilder\Core\Exceptions\WriteConflict;

trait WritesAlbums
{
    /** Translate service errors to Filament's keyed repeater rows. */
    protected function writeAlbum(callable $operation): Album
    {
        try {
            return $operation();
        } catch (WriteConflict) {
            throw ValidationException::withMessages(['data.title' => 'Another editor changed this album. Reload it to see the latest version before saving.']);
        } catch (ValidationException $error) {
            $rows = array_keys($this->data['items'] ?? []);
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $field = preg_replace_callback('/^items\.(\d+)(?=\.|$)/', fn (array $match): string => 'items.'.($rows[$match[1]] ?? $match[1]), $field);
                $errors['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
