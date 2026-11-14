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
            ->where(fn ($dates) => $dates
                ->whereBetween('date_facture', [$du, $au])
                ->orWhere(fn ($sansDate) => $sansDate->whereNull('date_facture')->where('date_validation', '>=', $debut)->where('date_validation', '<', $fin)))
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
            ->join('factures_achat', fn ($jointure) => $jointure->on('factures_achat.id', '=', 'avoirs_fournisseur.facture_achat_id')->on('factures_achat.magasin_id', '=', 'avoirs_fournisseur.magasin_id'))
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
