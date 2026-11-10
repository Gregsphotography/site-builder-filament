<?php

declare(strict_types=1);

namespace SiteBuilder\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Filament\Support\OptionalForms;

final class FormProfiles extends Page
{
    protected static ?string $navigationLabel = 'Enquiry destinations';

    protected static ?string $title = 'Enquiry destinations';

    protected string $view = 'site-builder-filament::pages.form-profiles';

    #[Locked]
    public ?string $selectedProfile = null;

    #[Locked]
    public int $profileVersion = 0;

    public array $data = [];

    public static function canAccess(): bool
    {
        $actor = Filament::getCurrentPanel() === null ? null : Filament::auth()->user();

        return $actor !== null && app(OptionalForms::class)->available() && in_array(self::class, Filament::getCurrentPanel()->getPages(), true) && Gate::forUser($actor)->allows('viewAny', OptionalForms::PROFILE);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill(['active' => true, 'recipients_text' => '']);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255), Textarea::make('recipients_text')->label('Notification email addresses')->helperText('One address per line. Leave empty to save enquiries in the inbox without email.')->rows(4)->maxLength(3000), Toggle::make('active')->label('Accept new enquiries')->default(true)])->statePath('data')->disabled(fn (): bool => ! $this->canManage());
    }

    #[Unexposed]
    public function canManage(): bool
    {
        if (! self::canAccess()) {
            return false;
        }
        $class = OptionalForms::PROFILE;
        $target = $this->selectedProfile === null ? $class : $class::query()->find($this->selectedProfile);

        return $target !== null && Gate::forUser(Filament::auth()->user())->allows($this->selectedProfile === null ? 'create' : 'update', $target);
    }

    public function save(): void
    {
        abort_unless($this->canManage(), 403);
        $data = $this->form->getState();
        $values = ['name' => $data['name'], 'active' => (bool) ($data['active'] ?? false), 'recipients' => preg_split('/[\r\n,]+/', trim($data['recipients_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)];
        $values['recipients'] = array_map(trim(...), $values['recipients']);
        try {
            $profiles = app(OptionalForms::class)->profiles();
            $actor = Filament::auth()->user();
            $this->selectedProfile === null ? $profiles->create($actor, $values) : $profiles->update($actor, $this->selectedProfile, $this->profileVersion, $values);
        } catch (WriteConflict) {
            throw ValidationException::withMessages(['data.name' => 'This profile changed in another session. Your entries are retained; reopen the profile before retrying.']);
        }
        $this->selectedProfile = null;
        $this->profileVersion = 0;
        $this->form->fill(['active' => true, 'recipients_text' => '']);
        Notification::make()->title('Enquiry destination saved')->success()->send();
    }

    public function edit(string $id): void
    {
        abort_unless(self::canAccess(), 403);
        $class = OptionalForms::PROFILE;
        $profile = $class::query()->findOrFail($id);
        Gate::forUser(Filament::auth()->user())->authorize('view', $profile);
        $this->selectedProfile = $profile->id;
        $this->profileVersion = $profile->write_version;
        $this->form->fill(['name' => $profile->name, 'active' => $profile->active, 'recipients_text' => implode("\n", $profile->recipients)]);
    }

    public function newProfile(): void
    {
        abort_unless(self::canAccess(), 403);
        Gate::forUser(Filament::auth()->user())->authorize('create', OptionalForms::PROFILE);
        $this->selectedProfile = null;
        $this->profileVersion = 0;
        $this->form->fill(['active' => true, 'recipients_text' => '']);
    }

    #[Unexposed]
    public function profiles(): array
    {
        abort_unless(self::canAccess(), 403);
        $class = OptionalForms::PROFILE;

        return $class::query()->orderBy('name')->get()->filter(fn ($profile): bool => Gate::forUser(Filament::auth()->user())->allows('view', $profile))->map(fn ($profile): array => ['id' => $profile->id, 'name' => $profile->name, 'active' => $profile->active, 'recipients' => $profile->recipients])->all();
    }
}
