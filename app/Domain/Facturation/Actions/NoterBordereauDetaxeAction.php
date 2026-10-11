<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Note sur le ticket d'un voyageur résidant hors de l'Union le n° du
 * bordereau de vente à l'exportation (BVE) émis pour lui dans PABLO (I3,
 * art. 262 I 2° du CGI) : le ticket reste à la TVA française, tel qu'il a
 * été émis et signé, et sa TVA ne se régularise qu'après le visa du
 * bordereau à la sortie de l'Union -- ce que fera la comptabilité.
 *
 * Seul un ticket de caisse émis, vendu en France à la TVA française, se
 * détaxe : ni une facture à un professionnel, ni une vente déjà exonérée
 * par sa nature, ni un ticket vendu en franchise en base, qui n'a facturé
 * aucune TVA à rembourser. La franchise se lit sur le ticket, tel qu'il a
 * été émis, jamais sur le régime actuel du magasin. Un ticket de 100,00 €
 * TTC ou moins se détaxe quand même, les achats d'un voyageur pouvant se
 * regrouper sur trois jours consécutifs : le bordereau noté le signale
 * (voir BordereauDetaxe::avertissement()).
 *
 * Le n° s'enregistre sans espaces autour, en majuscules : de 4 à 30
 * lettres, chiffres ou tirets. Corriger une faute note un nouveau n°, le
 * dernier noté faisant foi ; noter de nouveau celui qui fait déjà foi est
 * refusé.
 *
 * Le ticket se relit, verrouillé, dans le magasin actif (MagasinScope) :
 * celui d'un autre magasin est introuvable. Un magasin suspendu ou fermé
 * ne note plus rien (voir Magasin::verifierQuIlEstOuvert()).
 */
class NoterBordereauDetaxeAction
{
    /**
     * @throws LogicException
     */
    public function handle(Facture $facture, string $numero): BordereauDetaxe
    {
        $numero = Str::upper(trim($numero));

        if (preg_match('/^[A-Z0-9-]{4,30}$/D', $numero) !== 1) {
            throw new InvalidArgumentException('Le n° du bordereau de détaxe compte de 4 à 30 lettres, chiffres ou tirets.');
        }

        return DB::transaction(function () use ($facture, $numero): BordereauDetaxe {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            $facture->magasin->verifierQuIlEstOuvert();

            if (($refus = self::refusPour($facture)) !== null) {
                throw new LogicException($refus);
            }

            if ($facture->bordereauDetaxe?->numero === $numero) {
                throw new LogicException("Le bordereau n° {$numero} est déjà celui de ce ticket.");
            }

            return BordereauDetaxe::forceCreate([
                'facture_id' => $facture->getKey(),
                'numero' => $numero,
                'note_par' => auth()->id(),
            ])->setRelation('facture', $facture);
        });
    }

    /**
     * Pourquoi cette vente ne se détaxe pas, null quand elle se détaxe :
     * ce que l'action refuse, et ce qui retire son bouton de la fiche.
     */
    public static function refusPour(Facture $facture): ?string
    {
        return match (true) {
            $facture->statut === StatutFacture::Brouillon, $facture->serie() !== TypeDocument::TicketCaisse => 'Le bordereau de détaxe se note sur un ticket de caisse émis : la détaxe rembourse un voyageur, jamais un professionnel.',
            $facture->nature_operation !== NatureOperation::VenteEnFrance => "Ce ticket, de nature « {$facture->nature_operation->getLabel()} », ne porte aucune TVA française : la détaxe ne s'y applique pas.",
            $facture->franchise_en_base => 'Ce ticket a été vendu en franchise en base de TVA : sans TVA facturée, la détaxe n\'a rien à rembourser.',
            default => null,
        };
    }
}
