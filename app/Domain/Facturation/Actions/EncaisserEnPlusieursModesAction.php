<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Encaisser » une facture ou un ticket déjà émis, d'un seul geste, en
 * un ou plusieurs modes (point 23) : chaque part devient un paiement de
 * son mode, avec sa référence, tous reçus le même jour (voir
 * EnregistrerPaiementAction), dans une seule transaction : tout ou rien.
 * Les parts, chacune dans un mode différent des autres, ne dépassent
 * jamais ce qui reste dû ; un acompte reste permis. Le crédit d'avoir du
 * client y est déjà imputé (voir ImputationDuCredit) : l'écran le montre
 * en lecture, comme la part « Avoir ».
 *
 * Un ticket du client comptoir resté dû accepte en plus des bons d'avoir
 * (Q6), par leur numéro, jamais une autre facture : ils s'imputent sur lui
 * avant les parts, chacun pour son crédit restant, et se signent avec le
 * paiement de la première part (voir EnregistrerPaiementAction), puisque
 * seul un paiement d'un mode signe ce qui règle un document après son
 * émission, et que le format signé n'a pas d'autre enregistrement pour
 * cela. Un bon s'y présente donc avec au moins une part payée, et les bons
 * n'y règlent jamais seuls tout ce qui reste dû : le règlement est refusé
 * sans rien enregistrer (Avoir::BON_D_AVOIR_SANS_PART_PAYEE). La
 * contre-passation de ce premier paiement, qui en est l'inverse exact,
 * rend aux bons le crédit qu'ils y avaient imputé et le ticket en
 * redevient dû ; celle d'une autre part les laisse imputés (voir
 * ContrePasserPaiementAction).
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture ;
 * l'auteur, relu sous le verrou de sa ligne, doit pouvoir enregistrer un
 * paiement et encaisser cette facture, relue verrouillée (voir
 * FacturePolicy::encaisser() : un vendeur sans « voir celles de toute
 * l'équipe » n'encaisse que les siennes) ; les bons se relisent
 * verrouillés. La demande porte un jeton (R3, voir
 * ActionsUniques), lié à ses parts, à ses bons et à leur date de
 * réception : répétée après une réponse perdue, elle rend la facture sans
 * second paiement ; le même jeton avec d'autres parts est refusé. Les
 * paiements qu'elle compose n'en consomment aucun.
 */
class EncaisserEnPlusieursModesAction
{
    public const PARTS_EN_TROP = 'Les parts du règlement (%s) dépassent ce qui reste dû (%s).';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly EnregistrerPaiementAction $enregistrerPaiement,
        private readonly LecteurDesDroits $lecteur,
        private readonly ActionsUniques $actionsUniques,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @param  list<PartDeReglement>  $parts
     * @param  list<string>  $bonsDAvoir  les numéros des bons d'avoir présentés (AV-...), pour un ticket du client comptoir
     * @param  string  $recuLe  AAAA-MM-JJ, jamais dans le futur
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture, array $parts, array $bonsDAvoir, string $recuLe, ?string $jeton = null): Facture
    {
        if ($parts === []) {
            throw new LogicException($bonsDAvoir === [] ? 'Saisissez au moins une part du règlement.' : Avoir::BON_D_AVOIR_SANS_PART_PAYEE);
        }

        PartDeReglement::refuserUnModeEnDouble($parts);
        $bonsDAvoir = PartDeReglement::numerosDesBons($bonsDAvoir);
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::ENCAISSER_EN_PLUSIEURS_MODES, $auteur, [
            'facture_id' => $facture->getKey(),
            'parts' => array_map(fn (PartDeReglement $part): array => $part->enTableau(), $parts),
            'bons_d_avoir' => $bonsDAvoir,
            'recu_le' => $recuLe,
        ], fn () => $this->registre->verrouiller($magasin), fn (): Facture => DB::transaction(function () use ($auteur, $facture, $parts, $bonsDAvoir, $recuLe, $magasin): Facture {
            $this->registre->verrouiller($magasin);
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Paiement::class);
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            $this->lecteur->exiger($compte, 'encaisser', $magasin, $facture);
            $bons = $this->bonsDAvoirUtilisables($facture, $bonsDAvoir);
            $this->refuserDesPartsEnTrop($facture, $parts, $bons);

            foreach ($parts as $rang => $part) {
                $montant = $this->calculateur->enDecimal($part->montant);

                $this->enregistrerPaiement->handle(
                    $compte,
                    $facture->client,
                    ['mode_paiement' => $part->mode->value, 'montant' => $montant, 'reference' => $part->reference, 'recu_le' => $recuLe],
                    [['facture_id' => $facture->getKey(), 'montant' => $montant]],
                    null,
                    $rang === 0 ? $bons : [],
                );
            }

            return $facture->fresh();
        }));
    }

    /**
     * Les bons d'avoir de ces numéros, relus verrouillés : seulement pour
     * un ticket du client comptoir, chacun un avoir émis au client
     * comptoir du magasin dont il reste du crédit (voir
     * Avoir::scopeBonDAvoir()).
     *
     * @param  list<string>  $numeros
     * @return list<Avoir>
     *
     * @throws LogicException
     */
    private function bonsDAvoirUtilisables(Facture $facture, array $numeros): array
    {
        if ($numeros === []) {
            return [];
        }

        if (! ($facture->client?->estComptoir() ?? false)) {
            throw new LogicException(Avoir::BON_D_AVOIR_HORS_DE_LA_CAISSE);
        }

        return array_map(function (string $numero): Avoir {
            $bon = Avoir::query()->bonDAvoir($numero)->lockForUpdate()->first();

            if ($bon === null || $bon->creditEnCentimes() <= 0) {
                throw new LogicException(Avoir::BON_D_AVOIR_INTROUVABLE);
            }

            return $bon;
        }, $numeros);
    }

    /**
     * Les bons laissent dû une partie de la facture, relue sous verrou, que
     * les parts payées signent (voir le commentaire de classe), et les
     * parts n'en dépassent pas ce reste.
     *
     * @param  list<PartDeReglement>  $parts
     * @param  list<Avoir>  $bons
     *
     * @throws LogicException
     */
    private function refuserDesPartsEnTrop(Facture $facture, array $parts, array $bons): void
    {
        $resteDu = $facture->resteDuEnCentimes();

        foreach ($bons as $bon) {
            $resteDu -= min($bon->creditEnCentimes(), max(0, $resteDu));
        }

        if ($bons !== [] && $resteDu <= 0) {
            throw new LogicException(Avoir::BON_D_AVOIR_SANS_PART_PAYEE);
        }

        $totalDesParts = PartDeReglement::total($parts);

        if ($totalDesParts > $resteDu) {
            throw new LogicException(sprintf(self::PARTS_EN_TROP, $this->calculateur->enEuros($totalDesParts), $this->calculateur->enEuros(max(0, $resteDu))));
        }
    }
}
