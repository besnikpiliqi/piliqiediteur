<?php

namespace App\Filament\App\Pages;

use App\Domain\Charges\Enums\LectureDeLaTva;
use App\Domain\Charges\Services\ChargesAFaire;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\MessagesDuCreditDeTva;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « Tableau de bord de la société », dans le panneau des magasins, pour
 * l'expert-comptable que la société a déclaré par le SIREN de son cabinet
 * (décision de l'éditeur du 14/11/2026) : les chiffres clés et les
 * graphiques des magasins de sa déclaration, selon les
 * autorisations qu'elle lui accorde (voir GrilleDesGraphiques et
 * PerimetreDesGraphiques::delExpertComptable()), en lecture seule. Elle ne
 * s'ouvre qu'à lui, dans un magasin que sa déclaration lui ouvre, comme
 * « La société » (voir LaSociete). Au-dessus, ce qu'il a à faire : les
 * charges à valider et le crédit de TVA de départ à vérifier, puis le
 * message de la TVA de la société (lot TVA27).
 *
 * @phpstan-import-type MessageDeLaTva from MessagesDuCreditDeTva
 */
class TableauDeBordDeLaSociete extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Tableau de bord de la société';

    protected static ?string $title = 'Tableau de bord de la société';

    protected static ?string $slug = 'tableau-de-bord-de-la-societe';

    protected static ?int $navigationSort = 89;

    protected static string $view = 'filament.app.pages.tableau-de-bord-de-la-societe';

    /**
     * Le nombre de charges de la société qu'il a à valider (voir
     * ChargesAFaire), quand sa déclaration lui accorde les charges.
     */
    public function chargesAValider(): int
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();
        $acces = $compte instanceof User && $magasin instanceof Magasin ? app(DroitsSurLesCharges::class)->pour($compte, $magasin->societe) : null;

        return $acces === null || ! $acces->valide() ? 0 : app(ChargesAFaire::class)->compter($acces)['a_valider'];
    }

    /**
     * Le message de la TVA de la société, du côté de son comptable (lot
     * TVA27, voir MessagesDuCreditDeTva::message()) : son régime à
     * choisir, ou où en est son crédit de TVA, avec son montant quand sa
     * déclaration lui accorde les charges ou qu'il voit la TVA de toute la
     * société avec son report, sans lui quand il n'en voit que la carte de
     * la TVA ; le bouton mène au bloc du crédit de « Charges de la
     * société ».
     *
     * @return MessageDeLaTva|null
     */
    public function messageDeLaTva(): ?array
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        if (! $compte instanceof User || ! $magasin instanceof Magasin) {
            return null;
        }

        return app(MessagesDuCreditDeTva::class)->message(
            $magasin->societe,
            $compte,
            true,
            ChargesDeLaSociete::canAccess() ? ChargesDeLaSociete::getUrl().'#credit-de-tva' : null,
            fn (): LectureDeLaTva => app(PerimetreDesGraphiques::class)->lectureDeLaTvaDeLExpertComptable($compte),
        );
    }

    /**
     * Le crédit de TVA de départ que la société a indiqué attend-il sa
     * vérification (voir MessagesDuCreditDeTva::aVerifier()) ? Sa ligne
     * « Crédit de TVA de départ à vérifier » mène au bloc du crédit.
     */
    public function creditAVerifier(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin && app(MessagesDuCreditDeTva::class)->aVerifier($compte, $magasin->societe);
    }

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin
            && $compte->estExpertComptable() && $compte->estActif()
            && $compte->peutOuvrir($magasin);
    }
}
