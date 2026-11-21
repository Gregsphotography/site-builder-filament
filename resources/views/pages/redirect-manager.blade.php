<x-filament-panels::page>
    @unless ($available)
        <p>Publish the core migrations with <code>php artisan site-builder:install</code> and run <code>php artisan migrate</code> to enable redirect management and the 404 report.</p>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $editing ? __('Edit redirect') : __('Add redirect') }}</x-slot>
            <form wire:submit="save" class="sb-create" x-data x-on:redirect-form-opened.window="$nextTick(() => $refs.source.focus())">
                <div><label for="redirect-language">{{ __('Language') }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="redirect-language" wire:model.live="locale">
                        @foreach ($locales as $code)<option value="{{ $code }}">{{ config('site-builder.locales.'.$code.'.label', strtoupper($code)) }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </div>
                <div><label for="redirect-source">{{ __('Old path within this language') }}</label>
                    <x-filament::input.wrapper><x-filament::input id="redirect-source" x-ref="source" wire:model="source" type="text" maxlength="255" placeholder="old/services.html" required /></x-filament::input.wrapper>
                    @error('source') <p role="alert">{{ $message }}</p> @enderror
                </div>
                <div><label for="redirect-search">{{ __('Find a destination by page title') }}</label>
                    <x-filament::input.wrapper><x-filament::input id="redirect-search" wire:model.live.debounce.300ms="targetSearch" type="search" maxlength="120" /></x-filament::input.wrapper>
                </div>
                <div><label for="redirect-destination">{{ __('Published destination') }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="redirect-destination" wire:model.number="target" required>
                        <option value="">{{ __('Choose a page') }}</option>
                        @foreach ($options as $page)<option value="{{ $page->id }}">{{ $page->title }} (#{{ $page->id }})</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                    @error('target') <p role="alert">{{ $message }}</p> @enderror
                </div>
                <div><label for="redirect-status">{{ __('Status') }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="redirect-status" wire:model.number="status">
                        <option value="301">301 · {{ __('Permanent') }}</option>
                        <option value="302">302 · {{ __('Temporary') }}</option>
                        <option value="307">307 · {{ __('Temporary, preserve method') }}</option>
                        <option value="308">308 · {{ __('Permanent, preserve method') }}</option>
                    </x-filament::input.select></x-filament::input.wrapper>
                </div>
                @error('status') <p role="alert">{{ $message }}</p> @enderror
                <div><x-filament::button type="submit">{{ __('Save redirect') }}</x-filament::button>
                    @if ($editing)
                        <x-filament::button type="button" color="gray" wire:click="newRule">{{ __('Cancel') }}</x-filament::button>
                        <x-filament::button type="button" color="danger" wire:click="remove" wire:confirm="{{ __('Remove this redirect?') }}">{{ __('Remove redirect') }}</x-filament::button>
                    @endif
                </div>
            </form>
        </x-filament::section>
    @endunless
    <x-filament::section>
        <x-slot name="heading">{{ __('Redirects') }}</x-slot>
        <ul>
            @forelse ($rules->take(20) as $rule)
                <li wire:key="redirect-{{ $rule->id }}">
                    <code>{{ $displayPath($rule->locale, $rule->from_slug) }}</code> → {{ $rule->page->title }} · {{ $rule->status }}
                    @if (!in_array($rule->locale, $locales, true) || $rule->page->status !== 'published' || !$rule->page->slugs->contains('locale', $rule->locale)) ({{ __('Destination offline') }}) @endif
                    @if ($available && $gate->allows('publish', $rule->page))
                        <x-filament::button color="gray" size="sm" wire:click="edit({{ $rule->id }})">{{ __('Edit') }}</x-filament::button>
                    @endif
                </li>
            @empty
                <li>{{ __('No redirects on this page.') }}</li>
            @endforelse
        </ul>
        @if ($rulesPage > 1)<x-filament::button color="gray" wire:click="$set('rulesPage', {{ $rulesPage - 1 }})">{{ __('Previous redirects') }}</x-filament::button>@endif
        @if ($rules->count() > 20)<x-filament::button color="gray" wire:click="$set('rulesPage', {{ $rulesPage + 1 }})">{{ __('Next redirects') }}</x-filament::button>@endif
    </x-filament::section>
    @if ($available)
        <x-filament::section>
            <x-slot name="heading">{{ __('Missing pages (404)') }}</x-slot>
            <p>{{ __('Public requests grouped by language and path. Counts exclude query strings and visitor details.') }}</p>
            @unless (config('site-builder.missing_paths.enabled', true))<p>{{ __('Recording is disabled.') }}</p>@endunless
            <ul>
                @forelse ($missing->take(20) as $miss)
                    <li wire:key="miss-{{ $miss->id }}">
                        <code>{{ $displayPath($miss->locale, $miss->slug) }}</code> · {{ __('Requests') }} {{ $miss->hits }} · {{ __('Last seen') }} {{ $miss->last_seen_at }}
                        @if (in_array($miss->locale, $locales, true))
                            <x-filament::button size="sm" color="gray" wire:click="resolveMissing({{ $miss->id }})">{{ __('Create redirect') }}</x-filament::button>
                        @endif
                    </li>
                @empty
                    <li>{{ __('No missing pages on this page.') }}</li>
                @endforelse
            </ul>
            @if ($missingPage > 1)<x-filament::button color="gray" wire:click="$set('missingPage', {{ $missingPage - 1 }})">{{ __('Previous missing pages') }}</x-filament::button>@endif
            @if ($missing->count() > 20)<x-filament::button color="gray" wire:click="$set('missingPage', {{ $missingPage + 1 }})">{{ __('Next missing pages') }}</x-filament::button>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
