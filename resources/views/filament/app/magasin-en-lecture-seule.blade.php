{{--
    Bandeau d'un magasin en lecture seule (voir Magasin::estEnLectureSeule()
    et ControleDesDroits), en tête de chaque page du panneau : il dit ce
    qui l'y met, le magasin fermé ou suspendu, ou sa société.
--}}
@php($motif = $magasin?->motifDeLectureSeule())
@if ($motif !== null)
    <x-filament::section icon="heroicon-o-lock-closed" icon-color="warning" compact>
        <x-slot name="heading">
            {{ $motif }} : consultation et téléchargements seulement.
        </x-slot>

        <x-slot name="description">
            Les clôtures de caisse et les déclarations continuent.
        </x-slot>
    </x-filament::section>
@endif
