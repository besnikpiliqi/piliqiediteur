{{--
    La bienvenue en tête d'un tableau de bord : « Bonjour » et le nom de
    la personne connectée, puis son rôle tel que l'écran le donne ($role,
    voir User::libelleDuRoleDans() et User::libelleDuRoleDeSociete()), et
    le bouton de déconnexion. Partagée par le tableau de bord d'un magasin
    (voir BienvenueApercu) et celui de l'espace de la société (voir
    TableauDeBord).
--}}
<x-filament::section>
    <div class="flex items-center gap-x-3">
        <x-filament-panels::avatar.user size="lg" :user="$compte" />

        <div class="flex-1">
            <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Bonjour {{ filament()->getUserName($compte) }}
            </h2>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $role }}
            </p>
        </div>

        <form action="{{ filament()->getLogoutUrl() }}" method="post" class="my-auto">
            @csrf

            <x-filament::button color="gray" icon="heroicon-m-arrow-left-on-rectangle" labeled-from="sm" tag="button" type="submit">
                Déconnexion
            </x-filament::button>
        </form>
    </div>
</x-filament::section>
