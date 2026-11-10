<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use SiteBuilder\Filament\Support\OptionalForms;

final class FormInbox extends Page
{
    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?string $title = 'Enquiries';

    protected string $view = 'site-builder-filament::pages.form-inbox';

    #[Locked]
    public ?string $selectedSubmission = null;

    public int $pageNumber = 1;

    public string $statusFilter = 'active';

    public static function canAccess(): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && app(OptionalForms::class)->available() && in_array(self::class, Filament::getCurrentPanel()->getPages(), true) && Gate::forUser($actor)->allows('viewAny', OptionalForms::SUBMISSION);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    #[Unexposed]
    public function entries(): array
    {
        abort_unless(self::canAccess(), 403);
        $class = OptionalForms::SUBMISSION;
        $query = $class::query()->orderByDesc('created_at')->orderByDesc('id');
        match ($this->statusFilter) {
            'archived' => $query->whereNotNull('archived_at'), 'unread' => $query->whereNull('archived_at')->whereNull('read_at'), default => $query->whereNull('archived_at'),
        };

        return $query->skip((max(1, $this->pageNumber) - 1) * 25)->take(25)->get()->filter(fn ($entry): bool => Gate::forUser(Filament::auth()->user())->allows('view', $entry))->map(fn ($entry): array => ['id' => $entry->id, 'title' => $entry->form_title, 'locale' => $entry->locale, 'created' => $entry->created_at->format('Y-m-d H:i'), 'unread' => $entry->read_at === null])->all();
    }

    public function open(string $id): void
    {
        $record = $this->authorized($id, 'view');
        $this->selectedSubmission = $record->id;
        if (Gate::forUser(Filament::auth()->user())->allows('update', $record)) {
            $record->forceFill(['read_at' => now()])->save();
        }
    }

    public function markUnread(string $id): void
    {
        $this->authorized($id, 'update')->forceFill(['read_at' => null])->save();
    }

    public function archive(string $id): void
    {
        $record = $this->authorized($id, 'update');
        $record->forceFill(['archived_at' => $record->archived_at === null ? now() : null])->save();
    }

    public function delete(string $id): void
    {
        $record = $this->authorized($id, 'delete');
        DB::transaction(function () use ($record): void {
            $class = OptionalForms::NOTIFICATION;
            $class::query()->where('submission_id', $record->id)->delete();
            $record->delete();
        });
        $this->selectedSubmission = null;
    }

    public function retry(string $id): void
    {
        $this->authorized($id, 'retry');
        $queued = app(OptionalForms::class)->outbox()->retry(Filament::auth()->user(), $id);
        Notification::make()->title($queued ? 'Notification queued' : 'Queue unavailable; enquiry is retained')->send();
    }

    #[Unexposed]
    public function selected(): ?array
    {
        if ($this->selectedSubmission === null) {
            return null;
        }
        $record = $this->authorized($this->selectedSubmission, 'view');
        $class = OptionalForms::NOTIFICATION;
        $notification = $class::query()->where('submission_id', $record->id)->first();

        return ['id' => $record->id, 'title' => $record->form_title, 'fields' => $record->fields, 'archived' => $record->archived_at !== null, 'status' => $notification?->status ?? 'inbox only', 'error' => $notification?->last_error, 'canUpdate' => Gate::forUser(Filament::auth()->user())->allows('update', $record), 'canDelete' => Gate::forUser(Filament::auth()->user())->allows('delete', $record), 'canRetry' => $notification?->status === 'failed' && Gate::forUser(Filament::auth()->user())->allows('retry', $record)];
    }

    private function authorized(string $id, string $ability): object
    {
        abort_unless(self::canAccess(), 403);
        $class = OptionalForms::SUBMISSION;
        $record = $class::query()->findOrFail($id);
        Gate::forUser(Filament::auth()->user())->authorize($ability, $record);

        return $record;
    }
}
