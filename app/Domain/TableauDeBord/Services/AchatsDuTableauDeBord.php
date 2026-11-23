<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\JoursDesMagasins;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Les achats de marchandises du tableau de bord, dans une société qui a
 * des articles : les factures d'achat validées, à la date de la facture du
 * fournisseur (à défaut, au jour de leur validation), et les avoirs
 * fournisseur émis, au jour de leur émission, avec les montants
 * enregistrés, qui sont ceux du fournisseur (CLAUDE.md, « Prix et TVA des
 * ventes »), en centimes ; jamais un brouillon. Une lecture hors du
 * magasin courant, limitée aux magasins que le périmètre permet pour les
 * achats (voir Perimetre::exiger()) et aux colonnes nommées : deux
 * requêtes, et une pour nommer les fournisseurs.
 *
 * Les lignes des factures d'achat validées, que lit la carte « Écart des
 * prix d'achat » (décision de l'éditeur du 21/11/2026, D76), le sont en une
 * seule requête (voir lignes()).
 *
 * @phpstan-type LigneDAchatDuTableau array{magasin: int, produit: int, nom: string, jour: string, rang: int, quantite: int, prix: int}
 * @phpstan-type DocumentDAchat array{document: 'achat'|'avoir_fournisseur', id: int, magasin: int, jour: string, ht: int, tva: int, ttc: int, fournisseur: int}
 */
final class AchatsDuTableauDeBord
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * @param  Collection<int, Magasin>  $magasins
     * @return list<DocumentDAchat>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function documents(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Achats);

        if ($magasins->isEmpty()) {
            return [];
        }

        $jours = new JoursDesMagasins($magasins, $du, $au);
        [$debut, $fin] = $jours->bornes();
        $documents = [];

        $achats = DB::table('factures_achat')
            ->whereIn('magasin_id', $magasins->modelKeys())
            ->where('statut', StatutFactureAchat::Validee->value)
            ->where(fn (Builder $dates): Builder => $dates
                ->whereBetween('date_facture', [$du, $au])
                ->orWhere(fn (Builder $sansDate): Builder => $sansDate->whereNull('date_facture')->where('date_validation', '>=', $debut)->where('date_validation', '<', $fin)))
            ->orderBy('id')
            ->get(['id', 'magasin_id', 'date_facture', 'date_validation', 'total_ht', 'total_tva', 'total_ttc', 'fournisseur_id']);

        foreach ($achats as $achat) {
            $magasin = ValeurSimple::enEntier($achat->magasin_id);
            $dateFacture = ValeursLues::texteOuNul($achat->date_facture);
            $jour = $dateFacture !== null ? substr($dateFacture, 0, 10) : $jours->jourDe($magasin, ValeurSimple::enTexte($achat->date_validation));

            if ($jour !== null && $jours->contient($jour)) {
                $documents[] = $this->document('achat', $achat->id, $magasin, $jour, $achat->total_ht, $achat->total_tva, $achat->total_ttc, $achat->fournisseur_id);
            }
        }

        $avoirs = DB::table('avoirs_fournisseur')
            ->join('factures_achat', fn (JoinClause $jointure): JoinClause => $jointure->on('factures_achat.id', '=', 'avoirs_fournisseur.facture_achat_id')->on('factures_achat.magasin_id', '=', 'avoirs_fournisseur.magasin_id'))
            ->whereIn('avoirs_fournisseur.magasin_id', $magasins->modelKeys())
            ->where('avoirs_fournisseur.statut', StatutAvoirFournisseur::Emise->value)
            ->where('avoirs_fournisseur.date_emission', '>=', $debut)
            ->where('avoirs_fournisseur.date_emission', '<', $fin)
            ->orderBy('avoirs_fournisseur.id')
            ->get(['avoirs_fournisseur.id', 'avoirs_fournisseur.magasin_id', 'avoirs_fournisseur.date_emission', 'avoirs_fournisseur.total_ht', 'avoirs_fournisseur.total_tva', 'avoirs_fournisseur.total_ttc', 'factures_achat.fournisseur_id']);

        foreach ($avoirs as $avoir) {
            $magasin = ValeurSimple::enEntier($avoir->magasin_id);
            $jour = $jours->jourDe($magasin, ValeurSimple::enTexte($avoir->date_emission));

            if ($jour !== null) {
                $documents[] = $this->document('avoir_fournisseur', $avoir->id, $magasin, $jour, $avoir->total_ht, $avoir->total_tva, $avoir->total_ttc, $avoir->fournisseur_id);
            }
        }

        return $documents;
    }

    /**
     * Les lignes des factures d'achat validées de ces magasins, en une seule
     * requête sur les colonnes nommées, avec le nom actuel de leur produit
     * (un produit archivé reste nommé) : jamais un brouillon, un avoir
     * fournisseur ni un inventaire d'ouverture, qui sont d'autres tables.
     * Les trois tables (lignes, factures, produits) se joignent sur
     * (magasin_id, id), comme la base les lie, et chacune est filtrée par les
     * magasins du périmètre, aucune n'ayant de colonne de société. La date
     * est celle de documents() : la date de la facture du fournisseur, à
     * défaut le jour de sa validation dans le calendrier de son magasin.
     *
     * Le prix est le coût réel d'un article en centimes (décision D76, B5) :
     * le HT de la ligne ÷ sa quantité, escompte de la facture déduit, quand la
     * facture écrit le HT de la ligne, sinon le PU HT au millionième moins la
     * remise de la ligne, puis l'escompte ; un seul arrondi, jamais depuis le
     * coût que le stock enregistre au dix-millième (voir
     * CalculateurLigne::coutUnitaireNetEnCentimes()). Le rang est celui de la
     * validation de la facture, unique dans le magasin : avec le jour, il
     * ordonne les achats d'un produit. Aucune somme faite par la base.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return list<LigneDAchatDuTableau>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function lignes(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Achats);

        if ($magasins->isEmpty()) {
            return [];
        }

        $jours = new JoursDesMagasins($magasins, $du, $au);
        [$debut, $fin] = $jours->bornes();
        $identifiants = $magasins->modelKeys();

        $lues = DB::table('lignes_achat')
            ->join('factures_achat', fn (JoinClause $jointure): JoinClause => $jointure->on('factures_achat.magasin_id', '=', 'lignes_achat.magasin_id')->on('factures_achat.id', '=', 'lignes_achat.facture_achat_id'))
            ->join('produits', fn (JoinClause $jointure): JoinClause => $jointure->on('produits.magasin_id', '=', 'lignes_achat.magasin_id')->on('produits.id', '=', 'lignes_achat.produit_id'))
            ->whereIn('lignes_achat.magasin_id', $identifiants)
            ->whereIn('factures_achat.magasin_id', $identifiants)
            ->whereIn('produits.magasin_id', $identifiants)
            ->where('factures_achat.statut', StatutFactureAchat::Validee->value)
            ->where(fn (Builder $dates): Builder => $dates
                ->whereBetween('factures_achat.date_facture', [$du, $au])
                ->orWhere(fn (Builder $sansDate): Builder => $sansDate->whereNull('factures_achat.date_facture')->where('factures_achat.date_validation', '>=', $debut)->where('factures_achat.date_validation', '<', $fin)))
            ->orderBy('lignes_achat.id')
            ->get([
                'lignes_achat.magasin_id', 'lignes_achat.produit_id', 'lignes_achat.quantite', 'lignes_achat.prix_unitaire_ht', 'lignes_achat.remise_pourcentage', 'lignes_achat.montant_ht_saisi',
                'factures_achat.date_facture', 'factures_achat.date_validation', 'factures_achat.rang_validation', 'factures_achat.escompte_pourcentage',
                'produits.nom',
            ]);

        $lignes = [];

        foreach ($lues as $ligne) {
            $magasin = ValeurSimple::enEntier($ligne->magasin_id);
            $dateFacture = ValeursLues::texteOuNul($ligne->date_facture);
            $jour = $dateFacture !== null ? substr($dateFacture, 0, 10) : $jours->jourDe($magasin, ValeurSimple::enTexte($ligne->date_validation));

            if ($jour === null || ! $jours->contient($jour)) {
                continue;
            }

            $quantite = ValeurSimple::enEntier($ligne->quantite);
            $escompte = $this->calculateur->enCentiemes($ligne->escompte_pourcentage);
            $htSaisi = ValeursLues::texteOuNul($ligne->montant_ht_saisi);

            $lignes[] = [
                'magasin' => $magasin,
                'produit' => ValeurSimple::enEntier($ligne->produit_id),
                'nom' => ValeurSimple::enTexte($ligne->nom),
                'jour' => $jour,
                'rang' => ValeurSimple::enEntier($ligne->rang_validation),
                'quantite' => $quantite,
                'prix' => $htSaisi !== null
                    ? $this->calculateur->coutUnitaireDuHtEnCentimes($this->calculateur->enCentiemes($htSaisi), $quantite, $escompte)
                    : $this->calculateur->coutUnitaireNetEnCentimes($this->calculateur->enMillioniemes($ligne->prix_unitaire_ht), $this->calculateur->enCentiemes($ligne->remise_pourcentage), $escompte),
            ];
        }

        return $lignes;
    }

    /**
     * Les noms de ces fournisseurs de ces magasins, par identifiant.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  list<int>  $fournisseurs
     * @return array<int, string>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function nomsDesFournisseurs(Perimetre $perimetre, Collection $magasins, array $fournisseurs): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Achats);

        if ($magasins->isEmpty() || $fournisseurs === []) {
            return [];
        }

        $noms = [];

        foreach (DB::table('fournisseurs')->whereIn('magasin_id', $magasins->modelKeys())->whereIn('id', $fournisseurs)->get(['id', 'nom']) as $fournisseur) {
            $noms[ValeurSimple::enEntier($fournisseur->id)] = ValeurSimple::enTexte($fournisseur->nom);
        }

        return $noms;
    }

    /**
     * @return DocumentDAchat
     */
    private function document(string $document, mixed $id, int $magasin, string $jour, mixed $ht, mixed $tva, mixed $ttc, mixed $fournisseur): array
    {
        return [
            'document' => $document === 'achat' ? 'achat' : 'avoir_fournisseur',
            'id' => ValeurSimple::enEntier($id),
            'magasin' => $magasin,
            'jour' => $jour,
            'ht' => $this->calculateur->enCentiemes($ht),
            'tva' => $this->calculateur->enCentiemes($tva),
            'ttc' => $this->calculateur->enCentiemes($ttc),
            'fournisseur' => ValeurSimple::enEntier($fournisseur),
        ];
    }
}
