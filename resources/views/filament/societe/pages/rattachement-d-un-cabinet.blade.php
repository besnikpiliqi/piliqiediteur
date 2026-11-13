{{-- Voir RattachementDUnCabinet. --}}
<x-filament-panels::page>
    @php($demande = $this->demande())

    @if ($demande === null)
        <x-filament::section>
            <p>{{ \App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE }}</p>
        </x-filament::section>
    @else
        @php($cabinet = $this->cabinet($demande))
        <x-filament::section icon="heroicon-o-briefcase">
            <x-slot name="heading">{{ $cabinet['societe']->raison_sociale ?? 'Un cabinet' }} demande à suivre votre société</x-slot>

            <p>Cabinet d'expertise comptable · SIREN {{ $cabinet['societe']->siren }}@if ($cabinet['societe']->siege_ville) · {{ $cabinet['societe']->siege_code_postal }} {{ $cabinet['societe']->siege_ville }}@endif @if ($cabinet['numero_ordre']) · inscrit à l'Ordre sous le n° {{ $cabinet['numero_ordre'] }}@endif</p>
            <p>S'il est accepté, le cabinet consultera vos magasins réels selon les autorisations que vous choisissez, que vous réglerez ensuite à tout moment dans « Cabinet comptable ».</p>

            @if ($this->cabinetSuspendu($demande))
                <p class="mt-2 font-semibold">{{ \App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction::MESSAGE_CABINET_SUSPENDU }}</p>
            @endif

            @if ($this->texteDeLaDeclaration($demande) !== null)
                <p class="mt-2 font-semibold">{{ $this->texteDeLaDeclaration($demande) }}</p>
            @endif

            @if ($this->cabinetActuel() !== null)
                <p class="mt-2 font-semibold">Accepter remplace votre cabinet actuel, {{ $this->cabinetActuel() }}, dont l'accès s'arrête aussitôt.</p>
            @endif

            @if ($this->societe()->attendSonCode())
                <p class="mt-2">Accepter ce rattachement ne valide pas votre société : saisissez le code du courrier envoyé à votre siège.</p>
            @endif

            {{-- Un cabinet suspendu ne se lie pas : seul « Refuser » se montre. --}}
            <div class="mt-4 flex flex-wrap gap-3">
                @if (! $this->cabinetSuspendu($demande))
                    {{ $this->accepterParDefautAction }}
                    {{ $this->accepterEnAjustantAction }}
                @endif
                {{ $this->refuserAction }}
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
