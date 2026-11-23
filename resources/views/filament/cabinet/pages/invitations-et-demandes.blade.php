{{-- Voir InvitationsEtDemandes. --}}
<x-filament-panels::page>
    @php($calendrier = \App\Domain\Calendrier\Calendrier::deParis())

    @unless ($this->cabinet()->estUnCabinetVerifie())
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <p>{{ \App\Domain\Cabinets\Exceptions\CabinetNonVerifieException::MESSAGE }}</p>
        </x-filament::section>
    @endunless

    <x-filament::section icon="heroicon-o-paper-airplane">
        <x-slot name="heading">Invitations</x-slot>
        <x-slot name="description">Les entreprises pas encore inscrites que votre cabinet a invitées. Une invitation close disparaît 48 heures après sa fin, une invitation refusée 7 jours après.</x-slot>

        @forelse ($this->invitations() as $invitation)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                <div>
                    <p class="font-semibold">SIREN {{ $invitation->siren }} · {{ $invitation->email }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Envoyée le {{ $calendrier->afficher($invitation->created_at, 'd/m/Y') }} par {{ $invitation->invitee_par_nom }} · expire le {{ $calendrier->afficher($invitation->expire_le, 'd/m/Y') }} · {{ $invitation->etat->getLabel() }}</p>
                </div>
                @if ($invitation->estOuverte())
                    {{ ($this->retirerLInvitationAction)(['uuid' => $invitation->uuid]) }}
                @endif
            </div>
        @empty
            <p>Aucune invitation.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-arrow-up-right">
        <x-slot name="heading">Demandes de rattachement envoyées</x-slot>
        <x-slot name="description">Les SIREN déjà inscrits que votre cabinet a invités : leur propriétaire répond dans son espace. Vous n'en voyez que le SIREN saisi tant que le lien n'est pas actif. Une demande refusée, retirée ou expirée reste ici 7 jours, le temps pendant lequel ce SIREN ne peut pas recevoir de nouvelle demande.</x-slot>

        @forelse ($this->demandesEnvoyees() as $demande)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                <div>
                    <p class="font-semibold">SIREN {{ $demande['siren'] }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Envoyée le {{ $calendrier->afficher($demande['demande_le'], 'd/m/Y') }} · @if ($demande['en_cours']) expire le {{ $calendrier->afficher($demande['expire_le'], 'd/m/Y') }} · {{ $demande['etat'] }} @else {{ $demande['etat'] }} le {{ $calendrier->afficher($demande['fin'], 'd/m/Y') }} @endif</p>
                </div>
                @if ($demande['en_cours'])
                    {{ ($this->retirerLaDemandeAction)(['uuid' => $demande['uuid']]) }}
                @endif
            </div>
        @empty
            <p>Aucune demande.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-arrow-down-left">
        <x-slot name="heading">Demandes reçues des clients</x-slot>
        <x-slot name="description">Les entreprises qui ont désigné votre cabinet. Une demande sans réponse expire après 7 jours.</x-slot>

        @forelse ($this->demandesRecues() as $demande)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                <div>
                    <p class="font-semibold">{{ $demande['client']->raison_sociale ?? 'Sans dénomination' }} · SIREN {{ $demande['client']->siren }}@if ($demande['client']->siege_ville) · {{ $demande['client']->siege_ville }}@endif</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Reçue le {{ $calendrier->afficher($demande['demande_le'], 'd/m/Y') }} · expire le {{ $calendrier->afficher($demande['expire_le'], 'd/m/Y') }}</p>
                </div>
                <div class="flex gap-2">
                    {{ ($this->accepterLaDemandeAction)(['uuid' => $demande['uuid']]) }}
                    {{ ($this->refuserLaDemandeAction)(['uuid' => $demande['uuid']]) }}
                </div>
            </div>
        @empty
            <p>Aucune demande reçue.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
