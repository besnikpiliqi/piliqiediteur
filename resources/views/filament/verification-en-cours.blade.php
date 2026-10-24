{{--
    Bandeau des deux panneaux tant que la société n'est pas validée (voir
    AppPanelProvider et SocietePanelProvider) : ce qui reste désactivé, et
    le magasin de démonstration pour s'essayer. Dans le magasin de
    démonstration, le rappel que ses documents n'ont aucune valeur.

    @param \App\Domain\Tenancy\Models\Societe|null $societe
    @param \App\Domain\Tenancy\Models\Magasin|null $magasin le magasin du panneau, null dans l'administration de la société
--}}
@if ($magasin?->est_demonstration)
    <x-filament::section icon="heroicon-o-academic-cap" icon-color="info" compact>
        <x-slot name="heading">
            Magasin de démonstration : documents marqués « Démonstration — sans valeur », aucun e-mail ni aucune transmission.
        </x-slot>

        <x-slot name="description">
            Ses ventes, ses numéros et ses clôtures lui sont propres : rien n'en passe à vos magasins réels.
        </x-slot>
    </x-filament::section>
@elseif ($societe !== null && $societe->attendSonCode())
    <x-filament::section icon="heroicon-o-shield-exclamation" icon-color="warning" compact>
        <x-slot name="heading">
            Société en attente du code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés.
        </x-slot>

        <x-slot name="description">
            Essayez {{ config('app.name') }} dans le magasin de démonstration, depuis le tableau de bord de l'administration de la société.
        </x-slot>
    </x-filament::section>
@endif
