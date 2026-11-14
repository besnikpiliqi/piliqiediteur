{{--
    Voir InscriptionSociete : le formulaire de la demande, avec la phrase
    qui dit qu'une société membre d'un assujetti unique ne vend pas encore
    dans PILIQI (décision P3.1), puis, une fois la demande partie, le seul
    message neutre, quoi que contiennent les informations saisies.
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

            <p class="text-sm text-gray-600 dark:text-gray-300">{{ \App\Filament\App\Pages\Auth\InscriptionSociete::INFORMATION_ASSUJETTI_UNIQUE }}</p>
        </x-filament-panels::form>
    @endif
</x-filament-panels::page.simple>
