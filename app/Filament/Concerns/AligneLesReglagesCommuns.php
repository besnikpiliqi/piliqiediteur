<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Les pages où se règlent les réglages communs aux magasins d'une même
 * société (voir ReglagesCommunsDeLaSociete) : Identité du magasin et
 * Paramètres fiscaux. Un encadré en tête de la page dit ce que le magasin
 * doit encore aligner sur le magasin principal de son SIREN, d'après ce
 * qui est enregistré ; à l'enregistrement, chaque champ commun de la page
 * dont la valeur n'est pas celle du principal est refusé, avec la valeur
 * à prendre.
 *
 * Un magasin qui prend un SIREN déjà porté n'est pas encore de cette
 * société : son refus ne dit aucune valeur du principal, seulement les
 * réglages qui diffèrent, et il n'en reçoit pas plus de dix par heure
 * (REFUS_POUR_REJOINDRE_PAR_HEURE). Au-delà, tout SIREN saisi est refusé
 * pendant une heure, même avec les bons réglages : le contrôle ne sert pas
 * à deviner, essai après essai, les réglages d'une autre société.
 */
trait AligneLesReglagesCommuns
{
    /**
     * Les refus qu'un magasin reçoit, par heure, en prenant le SIREN d'une
     * société dont il n'a pas tous les réglages communs.
     */
    private const REFUS_POUR_REJOINDRE_PAR_HEURE = 10;

    /**
     * L'encadré des réglages à aligner, masqué quand le magasin est
     * aligné, seul de son SIREN, ou lui-même le principal.
     */
    protected function encadreDesReglagesAAligner(): Section
    {
        return Section::make('Réglages à aligner sur le magasin principal de votre société')
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->schema([
                Placeholder::make('reglages_a_aligner')
                    ->hiddenLabel()
                    ->content(fn (): ?string => $this->refusDEmission()),
            ])
            ->visible(fn (): bool => $this->refusDEmission() !== null);
    }

    /**
     * Refuse ce que la page enregistre quand un réglage commun n'y est pas
     * celui du principal, dans l'ordre : un magasin qui n'est pas aligné
     * ne quitte pas sa société (le SIREN dit ce qu'il doit d'abord
     * prendre) ; un magasin qui rejoint une société doit avoir tous ses
     * réglages communs (voir refuserDeRejoindreSansLesReglagesDuPrincipal())
     * ; un magasin de la société voit chaque champ commun de la page en
     * écart dire la valeur à prendre. $donnees s'appliquent à une copie du
     * magasin du panneau, que l'écran continue de montrer tel qu'il est
     * enregistré.
     *
     * @param  array<string, mixed>  $donnees  les champs de la page, tels qu'ils s'enregistrent
     *
     * @throws ValidationException
     */
    protected function refuserLesReglagesCommunsDifferents(array $donnees): void
    {
        $reglagesCommuns = app(ReglagesCommunsDeLaSociete::class);
        $apercu = (clone $this->magasinDesReglages())->fill($donnees);
        $refusDeQuitter = $reglagesCommuns->refusDeQuitterLaSociete($apercu);

        if ($refusDeQuitter !== null) {
            throw ValidationException::withMessages(['data.siren' => $refusDeQuitter]);
        }

        if (ReglagesCommunsDeLaSociete::rejoint($apercu)) {
            $this->refuserDeRejoindreSansLesReglagesDuPrincipal($apercu, $donnees);

            return;
        }

        $erreurs = [];

        foreach (array_intersect_key($reglagesCommuns->ecarts($apercu), $donnees) as $champ => $valeurDuPrincipal) {
            $erreurs["data.{$champ}"] = ReglagesCommunsDeLaSociete::messageDuChamp($apercu->siren, $valeurDuPrincipal);
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }
    }

    /**
     * Un SIREN déjà porté ne s'enregistre qu'avec tous les réglages
     * communs du principal de sa société : le SIREN nomme ceux qui
     * diffèrent et la page où chacun se change, chaque champ de la page en
     * écart le signale, sans aucune valeur du principal. Chaque refus
     * compte, pour une heure, dans la limite du magasin.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    private function refuserDeRejoindreSansLesReglagesDuPrincipal(Magasin $apercu, array $donnees): void
    {
        $cle = 'rejoindre-une-societe:'.$apercu->getKey();

        if (RateLimiter::tooManyAttempts($cle, self::REFUS_POUR_REJOINDRE_PAR_HEURE)) {
            throw ValidationException::withMessages(['data.siren' => 'Trop d\'essais : réessayez dans une heure.']);
        }

        $champs = array_keys(app(ReglagesCommunsDeLaSociete::class)->ecarts($apercu));

        if ($champs === []) {
            return;
        }

        RateLimiter::hit($cle, 3600);
        $erreurs = ['data.siren' => ReglagesCommunsDeLaSociete::messageDuSirenPourRejoindre($champs)];

        foreach (array_intersect($champs, array_keys($donnees)) as $champ) {
            $erreurs["data.{$champ}"] = ReglagesCommunsDeLaSociete::MESSAGE_DU_CHAMP_POUR_REJOINDRE;
        }

        throw ValidationException::withMessages($erreurs);
    }

    private function refusDEmission(): ?string
    {
        return app(ReglagesCommunsDeLaSociete::class)->refusDEmission($this->magasinDesReglages());
    }

    private function magasinDesReglages(): Magasin
    {
        /** @var Magasin */
        return Filament::getTenant();
    }
}
