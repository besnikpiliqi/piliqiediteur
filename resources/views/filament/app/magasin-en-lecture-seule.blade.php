{{--
    Bandeau d'un magasin suspendu ou fermé (voir StatutMagasin et
    ControleDesDroits), en tête de chaque page du panneau.
--}}
@if ($magasin?->statut->estEnLectureSeule())
    <x-filament::section icon="heroicon-o-lock-closed" icon-color="warning" compact>
        <x-slot name="heading">
            Magasin {{ mb_strtolower($magasin->statut->getLabel()) }} : consultation et téléchargements seulement.
        </x-slot>

        <x-slot name="description">
            Les clôtures de caisse et les déclarations continuent.
        </x-slot>
    </x-filament::section>
@endif
