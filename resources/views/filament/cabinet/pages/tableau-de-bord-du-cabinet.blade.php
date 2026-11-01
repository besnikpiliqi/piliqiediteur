{{-- Voir TableauDeBordDuCabinet. --}}
<x-filament-panels::page>
    @php($cabinet = $this->cabinet())

    @include('filament.bienvenue', ['compte' => auth()->user(), 'role' => auth()->user()->libelleDuRoleDeSociete()])

    @if ($this->resteDesEtapes())
        <x-filament::section icon="heroicon-o-list-bullet">
            <x-slot name="heading">Vos étapes</x-slot>

            <ol class="space-y-3">
                @foreach ($this->etapes() as $etape)
                    <li class="flex items-start gap-3">
                        @if ($etape['faite'])
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-600" label="Faite" />
                        @else
                            <x-filament::icon icon="heroicon-o-ellipsis-horizontal-circle" class="h-6 w-6 text-gray-400" label="À faire" />
                        @endif
                        <div>
                            @if ($etape['url'] !== null)
                                <a href="{{ $etape['url'] }}" class="font-semibold underline">{{ $etape['titre'] }}</a>
                            @else
                                <span class="font-semibold">{{ $etape['titre'] }}</span>
                            @endif
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $etape['etat'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Vérification du cabinet</x-slot>

        <p>{{ $this->etatDeLaVerification() }}</p>
        <p>SIREN : {{ $cabinet->siren }}</p>
    </x-filament::section>
</x-filament-panels::page>
