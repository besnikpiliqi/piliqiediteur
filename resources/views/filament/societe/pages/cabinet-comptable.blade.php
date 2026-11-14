{{-- Voir CabinetComptable. --}}
<x-filament-panels::page>
    @php($calendrier = \App\Domain\Calendrier\Calendrier::deParis())
    @php($lien = $this->lienEnCours())

    @if ($this->societe()->attendSonCode())
        <x-filament::section icon="heroicon-o-information-circle">
            <p>Lier un cabinet ne valide pas votre société : saisissez le code du courrier envoyé à votre siège.</p>
        </x-filament::section>
    @endif

    @if ($lien?->estDeclare())
        <x-filament::section icon="heroicon-o-user-group">
            <x-slot name="heading">Votre expert-comptable (sans espace {{ config('app.name') }})</x-slot>
            <x-slot name="description">{{ $lien->denomination_cabinet ?? 'Cabinet' }} — SIREN {{ $lien->siren_cabinet }} — déclaré le {{ $calendrier->afficher($lien->demande_le, 'd/m/Y') }}@if ($lien->demande_par_nom) par {{ $lien->demande_par_nom }}@endif — en attente de l'espace de son cabinet</x-slot>

            <p>{{ \App\Filament\Societe\Pages\CabinetComptable::TEXTE_EN_ATTENTE }}</p>

            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="text-start">
                        <th class="py-1 text-start">Nom</th>
                        <th class="py-1 text-start">Identifiant</th>
                        <th class="py-1 text-start">Adresse</th>
                        <th class="py-1 text-start">Statut</th>
                        <th class="py-1 text-start">Lien du mot de passe</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->personnesDeLaDeclaration() as $personne)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            <td class="py-1">{{ $personne->name }}</td>
                            <td class="py-1">{{ $personne->identifiantDeConnexion() }}</td>
                            <td class="py-1">{{ $personne->email }}</td>
                            <td class="py-1">{{ $personne->statut->getLabel() }}</td>
                            <td class="py-1">{{ $this->etatDuLienDe($personne) }}</td>
                            <td class="py-1"><x-filament::link :href="$this->urlDeLaFiche($personne)">Fiche</x-filament::link></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">
                {{ $this->form }}
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                {{ $this->enregistrerLesAutorisationsAction }}
                {{ $this->ajouterUnePersonneAction }}
                {{ $this->mettreFinAction }}
            </div>
        </x-filament::section>
    @endif

    @if ($lien?->estActif())
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

    <x-filament::section icon="heroicon-o-identification">
        <x-slot name="heading">Déclarer votre expert-comptable</x-slot>

        @if ($this->societe()->estValidee())
            <p>{{ \App\Filament\Societe\Pages\CabinetComptable::TEXTE_DE_LA_DECLARATION }}</p>
            <div class="mt-2">{{ $this->declarerAction }}</div>
        @else
            <p>{{ \App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction::MESSAGE_SOCIETE_NON_VALIDEE }}</p>
        @endif
    </x-filament::section>

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
