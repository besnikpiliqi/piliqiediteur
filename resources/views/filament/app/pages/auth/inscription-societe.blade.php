{{--
    Voir InscriptionSociete : le formulaire de la demande, puis, une fois
    la demande partie, le seul message neutre, quoi que contiennent les
    informations saisies.
--}}
<x-filament-panels::page.simple>
    @if (filament()->hasLogin())
        <x-slot name="subheading">
            {{ __('filament-panels::pages/auth/register.actions.login.before') }}

            {{ $this->loginAction }}
        </x-slot>
    @endif

    @if ($this->demandeEnvoyee)
        <x-filament::section icon="heroicon-o-envelope">
            <x-slot name="heading">{{ \App\Filament\App\Pages\Auth\InscriptionSociete::MESSAGE_NEUTRE }}</x-slot>
        </x-filament::section>
    @else
        <x-filament-panels::form id="form" wire:submit="register">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endif
</x-filament-panels::page.simple>
