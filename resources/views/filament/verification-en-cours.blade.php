{{--
    Bandeau des trois panneaux tant que la société n'est pas validée (voir
    AppPanelProvider, SocietePanelProvider et CabinetPanelProvider) : ce qui
    reste désactivé ; pour un cabinet d'expertise comptable, qui n'a aucun
    magasin, seulement qu'il attend son code.

    @param \App\Domain\Tenancy\Models\Societe|null $societe
--}}
@if ($societe !== null && $societe->attendSonCode() && $societe->estUnCabinet())
    <x-filament::section icon="heroicon-o-shield-exclamation" icon-color="warning" compact>
        <x-slot name="heading">
            Cabinet en attente du code de son courrier recommandé.
        </x-slot>
    </x-filament::section>
@elseif ($societe !== null && $societe->attendSonCode())
    <x-filament::section icon="heroicon-o-shield-exclamation" icon-color="warning" compact>
        <x-slot name="heading">
            Société en attente du code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés.
        </x-slot>
    </x-filament::section>
@endif
