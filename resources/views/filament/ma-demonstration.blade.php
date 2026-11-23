{{--
    La carte « Ma démonstration » (voir MaDemonstration et
    CarteDeLaDemonstration), pour le seul propriétaire. La fenêtre du lien
    reçoit le lien par un événement du navigateur, le montre une seule fois
    et l'oublie à sa fermeture.
--}}
<div @if ($carte !== null && $carte['a_suivre']) wire:poll.15s @endif>
    @if ($carte !== null)
        <x-filament::section icon="heroicon-o-play-circle">
            <x-slot name="heading">Ma démonstration</x-slot>

            <div class="space-y-3" data-carte-de-la-demonstration="{{ $carte['etat'] }}">
                @foreach ($carte['phrases'] as $phrase)
                    <p>{{ $phrase }}</p>
                @endforeach

                <div class="flex flex-wrap items-center gap-3">
                    @if ($carte['etat'] === \App\Domain\Demonstration\Services\CarteDeLaDemonstration::ADRESSE_A_CONFIRMER)
                        <x-filament::button disabled>Créer ma démonstration</x-filament::button>
                        <x-filament::button tag="a" :href="$profil" color="gray">Renvoyer le lien de confirmation</x-filament::button>
                    @endif
                    @if ($carte['peut_creer'])
                        {{ $this->creerAction }}
                    @endif
                    @if ($carte['peut_ouvrir'])
                        <form method="POST" action="{{ route('ma-demonstration.ouvrir') }}" target="_blank">
                            @csrf
                            <x-filament::button type="submit">Ouvrir ma démonstration</x-filament::button>
                        </form>
                    @endif
                    @if ($carte['peut_renouveler'])
                        {{ $this->renouvelerAction }}
                    @endif
                    @if ($carte['peut_recommencer'])
                        {{ $this->recommencerAction }}
                    @endif
                </div>

                @foreach ($carte['notes'] as $note)
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $note }}</p>
                @endforeach
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ \App\Domain\Demonstration\Services\CarteDeLaDemonstration::PHRASE_DU_JOURNAL }}</p>
            </div>
        </x-filament::section>

        <div x-data="{ lien: '', copie: false }"
             x-on:piliqi-lien-de-la-demonstration.window="lien = $event.detail.lien; copie = false; $dispatch('open-modal', { id: 'lien-de-la-demonstration' })"
             x-on:modal-closed.window="if ($event.detail.id === 'lien-de-la-demonstration') { lien = ''; copie = false }">
            <x-filament::modal id="lien-de-la-demonstration" width="xl">
                <x-slot name="heading">Le lien de votre démonstration</x-slot>

                <div class="space-y-3">
                    <p class="font-semibold" style="overflow-wrap: anywhere" x-text="lien" data-lien-de-la-demonstration></p>
                    <x-filament::button x-on:click="navigator.clipboard.writeText(lien); copie = true">Copier le lien</x-filament::button>
                    <p class="text-sm" x-show="copie">Lien copié.</p>
                    <p>{{ $phraseDuLien }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $phraseDuSecret }}</p>
                </div>
            </x-filament::modal>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
