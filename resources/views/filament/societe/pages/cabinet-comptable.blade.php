{{-- Voir CabinetComptable. --}}
<x-filament-panels::page>
    @php($calendrier = \App\Domain\Calendrier\Calendrier::deParis())
    @php($lien = $this->lienActif())

    @if ($this->societe()->attendSonCode())
        <x-filament::section icon="heroicon-o-information-circle">
            <p>Lier un cabinet ne valide pas votre société : saisissez le code du courrier envoyé à votre siège.</p>
        </x-filament::section>
    @endif

    @if ($lien !== null)
        @php($cabinet = $this->cabinetDu($lien))
        <x-filament::section icon="heroicon-o-briefcase">
            <x-slot name="heading">Votre cabinet : {{ $cabinet['societe']->raison_sociale ?? 'sans dénomination' }}</x-slot>

            <p>SIREN {{ $cabinet['societe']->siren }}@if ($cabinet['societe']->siege_ville) · {{ $cabinet['societe']->siege_ville }}@endif @if ($cabinet['numero_ordre']) · inscrit à l'Ordre sous le n° {{ $cabinet['numero_ordre'] }}@endif</p>
            <p>Lié depuis le {{ $calendrier->afficher($lien->actif_depuis, 'd/m/Y') }}.</p>
            <p>Ont accès à votre société : {{ implode(', ', $this->personnesQuiOntAcces($lien)) ?: 'personne pour le moment' }}.</p>

            <div class="mt-4">
                {{ $this->form }}
            </div>

            <div class="mt-4 flex gap-3">
                {{ $this->enregistrerLesAutorisationsAction }}
                {{ $this->mettreFinAction }}
            </div>
        </x-filament::section>
    @endif

    @if ($this->demandesRecues()->isNotEmpty() || $this->demandesEnvoyees()->isNotEmpty())
        <x-filament::section icon="heroicon-o-inbox">
            <x-slot name="heading">Demandes en cours</x-slot>

            @foreach ($this->demandesRecues() as $demande)
                @php($demandeur = $this->cabinetDu($demande))
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                    <p>{{ $demandeur['societe']->raison_sociale ?? 'Un cabinet' }} · SIREN {{ $demandeur['societe']->siren }}@if ($demandeur['societe']->siege_ville) · {{ $demandeur['societe']->siege_ville }}@endif demande à suivre votre société · reçue le {{ $calendrier->afficher($demande->demande_le, 'd/m/Y') }}, expire le {{ $calendrier->afficher($demande->expire_le, 'd/m/Y') }}</p>
                    <x-filament::button tag="a" :href="$this->urlDuRattachement($demande)">Répondre</x-filament::button>
                </div>
            @endforeach

            @foreach ($this->demandesEnvoyees() as $demande)
                @php($designe = $this->cabinetDu($demande))
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                    <p>Votre demande à {{ $designe['societe']->raison_sociale ?? 'un cabinet' }} · SIREN {{ $designe['societe']->siren }} · envoyée le {{ $calendrier->afficher($demande->demande_le, 'd/m/Y') }}, expire le {{ $calendrier->afficher($demande->expire_le, 'd/m/Y') }}</p>
                    {{ ($this->retirerLaDemandeAction)(['uuid' => $demande->uuid]) }}
                </div>
            @endforeach
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-magnifying-glass">
        <x-slot name="heading">Désigner votre cabinet</x-slot>
        <x-slot name="description">Cherchez un cabinet d'expertise comptable vérifié par son SIREN ou par son nom.</x-slot>

        <x-filament::input.wrapper>
            <x-filament::input type="search" wire:model.live.debounce.500ms="recherche" placeholder="SIREN ou nom du cabinet" />
        </x-filament::input.wrapper>

        @foreach ($this->resultats() as $resultat)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                <p>{{ $resultat->raison_sociale ?? 'Sans dénomination' }} · SIREN {{ $resultat->siren }}@if ($resultat->siege_ville) · {{ $resultat->siege_code_postal }} {{ $resultat->siege_ville }}@endif</p>
                {{ ($this->demanderLeLienAction)(['uuid' => $resultat->uuid]) }}
            </div>
        @endforeach

        <p class="mt-4">Votre expert-comptable n'est pas encore sur {{ config('app.name') }} ?</p>
        <div class="mt-2">{{ $this->inviterSonExpertAction }}</div>
    </x-filament::section>
</x-filament-panels::page>
