<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Facture;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les preuves qui justifient l'exonération d'une facture émise, et celles
 * qui manquent encore (I4) : le magasin enregistre leurs références (voir
 * AjouterPreuveOperationAction), et ce qui manque lui est rappelé sur la
 * fiche de la facture et dans la liste (filtre et badge « Preuves à
 * fournir »). Sans ses preuves, la vente reste exonérée sur le document,
 * qui ne change jamais, mais le magasin devra la corriger par un avoir et
 * une nouvelle facture à la TVA française.
 *
 * - Export hors UE (art. 262 I du CGI) : la déclaration d'export (MRN)
 *   avec la certification de sortie, ou le CN23 d'un colis postal.
 * - Livraison vers un DOM (art. 294 du CGI) : la même déclaration, et un
 *   document de transport.
 * - Livraison intracommunautaire (art. 262 ter I du CGI, art. 45 bis du
 *   règlement 282/2011) : deux documents de transport de la catégorie a),
 *   ou un de la catégorie a) et une autre preuve d'expédition de la
 *   catégorie b), établis par deux parties indépendantes du magasin et
 *   du client ; et, quand le client enlève lui-même les biens, son
 *   attestation de réception, remise au plus tard le 10 du mois qui suit
 *   la livraison.
 *
 * Seule une facture émise demande des preuves, et plus aucune une fois
 * entièrement corrigée par des avoirs : la vente qu'elles justifiaient
 * n'existe plus. Le calcul en PHP (manquantes()) et sa traduction en SQL
 * (incompletes()) disent la même chose : une facture n'est jamais à la
 * fois complète sur sa fiche et listée parmi les preuves à fournir.
 */
class PreuvesRequises
{
    /**
     * Les types de preuve qu'une vente de cette nature admet : aucun pour
     * une nature sans livraison exonérée (vente en France, prestations).
     *
     * @return list<TypePreuve>
     */
    public static function typesAdmis(NatureOperation $nature): array
    {
        return match ($nature) {
            NatureOperation::ExportHorsUe => [TypePreuve::Mrn, TypePreuve::Cn23],
            NatureOperation::LivraisonDom => [TypePreuve::Mrn, TypePreuve::Cn23, TypePreuve::TransportA],
            NatureOperation::LivraisonIntracommunautaire => [TypePreuve::TransportA, TypePreuve::TransportB, TypePreuve::AttestationAcquereur],
            default => [],
        };
    }

    /**
     * La facture demande-t-elle des preuves ? Émise, d'une nature qui en
     * admet, et pas encore entièrement corrigée par des avoirs.
     */
    public function concerne(Facture $facture): bool
    {
        return $facture->statut !== StatutFacture::Brouillon
            && self::typesAdmis($facture->nature_operation) !== []
            && $facture->lignes()->creditables()->exists();
    }

    /**
     * Ce qui manque encore, une phrase par preuve attendue, dans l'ordre
     * où la nature les demande ; rien quand la facture est complète ou
     * n'en demande pas (voir concerne()).
     *
     * @return list<string>
     */
    public function manquantes(Facture $facture): array
    {
        if (! $this->concerne($facture)) {
            return [];
        }

        $nombre = $facture->preuvesOperation()->pluck('type')->countBy(fn (TypePreuve $type): string => $type->value);
        $nombreDe = fn (TypePreuve ...$types): int => array_sum(array_map(fn (TypePreuve $type): int => $nombre->get($type->value, 0), $types));
        $declaration = $nombreDe(TypePreuve::Mrn, TypePreuve::Cn23) > 0 ? null : TypePreuve::Mrn->getLabel().', ou '.lcfirst(TypePreuve::Cn23->getLabel());
        $transportsA = $nombreDe(TypePreuve::TransportA);

        return array_values(array_filter(match ($facture->nature_operation) {
            NatureOperation::ExportHorsUe => [$declaration],
            NatureOperation::LivraisonDom => [$declaration, $transportsA > 0 ? null : TypePreuve::TransportA->getLabel()],
            NatureOperation::LivraisonIntracommunautaire => [
                $transportsA > 0 ? null : TypePreuve::TransportA->getLabel(),
                $transportsA >= 2 || $nombreDe(TypePreuve::TransportB) > 0 ? null : 'Un second document de transport, ou une autre preuve d\'expédition : assurance du transport, paiement bancaire du transport, document d\'une autorité publique, récépissé d\'entrepositaire',
                $facture->transport_par === TransportPar::Acquereur && $nombreDe(TypePreuve::AttestationAcquereur) === 0 ? $this->attestationAttendue($facture) : null,
            ],
        }));
    }

    /**
     * Restreint les factures à celles qui demandent encore une preuve,
     * comme manquantes() les compte : en une seule requête, chaque preuve
     * comptée par une sous-requête, que filtre MagasinScope comme la
     * facture elle-même.
     *
     * @param  Builder<Facture>  $factures
     * @return Builder<Facture>
     */
    public function incompletes(Builder $factures): Builder
    {
        $deType = fn (TypePreuve ...$types): Closure => fn (Builder $preuves): Builder => $preuves->whereIn('type', $types);
        $sansDeclaration = fn (Builder $facture): Builder => $facture->whereDoesntHave('preuvesOperation', $deType(TypePreuve::Mrn, TypePreuve::Cn23));
        $sansTransportA = fn (Builder $facture): Builder => $facture->whereDoesntHave('preuvesOperation', $deType(TypePreuve::TransportA));

        return $factures
            ->emises()
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->creditables())
            ->where(fn (Builder $natures): Builder => $natures
                ->where(fn (Builder $export): Builder => $export
                    ->where('nature_operation', NatureOperation::ExportHorsUe)
                    ->where($sansDeclaration))
                ->orWhere(fn (Builder $dom): Builder => $dom
                    ->where('nature_operation', NatureOperation::LivraisonDom)
                    ->where(fn (Builder $manque): Builder => $manque->where($sansDeclaration)->orWhere($sansTransportA)))
                ->orWhere(fn (Builder $intracommunautaire): Builder => $intracommunautaire
                    ->where('nature_operation', NatureOperation::LivraisonIntracommunautaire)
                    ->where(fn (Builder $manque): Builder => $manque
                        ->where($sansTransportA)
                        ->orWhere(fn (Builder $unSeulDocument): Builder => $unSeulDocument
                            ->whereHas('preuvesOperation', $deType(TypePreuve::TransportA), '<', 2)
                            ->whereDoesntHave('preuvesOperation', $deType(TypePreuve::TransportB)))
                        ->orWhere(fn (Builder $enleveParLeClient): Builder => $enleveParLeClient
                            ->where('transport_par', TransportPar::Acquereur)
                            ->whereDoesntHave('preuvesOperation', $deType(TypePreuve::AttestationAcquereur))))));
    }

    /**
     * L'attestation de réception qui manque, avec le jour où elle est
     * attendue, et le rappel que ce jour est passé dans le calendrier de
     * son magasin. Elle est attendue au
     * plus tard le 10 du mois qui suit la livraison (art. 45 bis du
     * règlement 282/2011), dont toute livraison intracommunautaire émise
     * porte la date (voir ControleNatureOperation).
     */
    private function attestationAttendue(Facture $facture): string
    {
        $echeance = Calendrier::jourDuMoisSuivant($facture->livraison_date, 10);

        return TypePreuve::AttestationAcquereur->getLabel().', attendue au plus tard le '.Calendrier::afficherJour($echeance, 'd/m/Y')
            .($echeance < $facture->magasin->calendrier()->aujourdhui() ? ' : délai dépassé' : '');
    }
}
