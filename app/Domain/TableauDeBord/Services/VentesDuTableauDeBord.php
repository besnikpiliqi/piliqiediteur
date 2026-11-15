<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
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
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Les ventes du tableau de bord : les tickets et les factures émis, jamais
 * un brouillon, et les avoirs émis, avec les montants enregistrés sur
 * chacun, en centimes, jamais recalculés ; chaque document dans le jour de
 * son magasin (voir JoursDesMagasins). Une lecture hors du magasin courant
 * (CLAUDE.md, « Produit ») : seulement ces magasins, que le périmètre du
 * compte permet pour les ventes (voir Perimetre::exiger()), chaque requête
 * filtrée par eux et limitée aux colonnes nommées ; deux requêtes pour les
 * documents, deux pour leurs lignes, quel que soit le nombre de magasins
 * ou de cartes.
 *
 * @phpstan-type DocumentDeVente array{document: 'facture'|'avoir', id: int, magasin: int, jour: string, ht: int, tva: int, ttc: int, type_client: string, client: int, client_nom: string|null}
 * @phpstan-type LigneDeVente array{document: 'facture'|'avoir', document_id: int, ligne_id: int, magasin: int, jour: string, nature: string, produit: int|null, service: int|null, description: string, quantite: int, taux: int, ht: int, tva: int, ttc: int, sortie_par: int|null}
 */
final class VentesDuTableauDeBord
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Les tickets, les factures et les avoirs émis du $du au $au compris.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return list<DocumentDeVente>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function documents(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Ventes);

        return $this->lire(['facture' => 'factures', 'avoir' => 'avoirs'], $magasins, $du, $au);
    }

    /**
     * Les seuls avoirs émis du $du au $au compris, pour qui voit tous les
     * avoirs de ces magasins.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return list<DocumentDeVente>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function avoirs(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Avoirs);

        return $this->lire(['avoir' => 'avoirs'], $magasins, $du, $au);
    }

    /**
     * Les noms de ces clients de ces magasins, par identifiant, sans le
     * client comptoir, que les meilleurs clients ne comptent jamais.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  list<int>  $clients
     * @return array<int, string>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function nomsDesClients(Perimetre $perimetre, Collection $magasins, array $clients): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Clients);

        if ($magasins->isEmpty() || $clients === []) {
            return [];
        }

        $noms = [];

        foreach (DB::table('clients')->whereIn('magasin_id', $magasins->modelKeys())->whereIn('id', $clients)->where(fn (Builder $nommes) => $nommes->whereNull('est_comptoir')->orWhere('est_comptoir', false))->get(['id', 'nom']) as $client) {
            $noms[ValeurSimple::enEntier($client->id)] = ValeurSimple::enTexte($client->nom);
        }

        return $noms;
    }

    /**
     * @param  array<'facture'|'avoir', 'factures'|'avoirs'>  $tables
     * @param  Collection<int, Magasin>  $magasins
     * @return list<DocumentDeVente>
     */
    private function lire(array $tables, Collection $magasins, string $du, string $au): array
    {
        if ($magasins->isEmpty()) {
            return [];
        }

        $jours = new JoursDesMagasins($magasins, $du, $au);
        $documents = [];

        foreach ($tables as $document => $table) {
            foreach ($this->emis($table, $magasins, $jours)->get(['id', 'magasin_id', 'date_emission', 'total_ht', 'total_tva', 'total_ttc', 'type_client', 'client_id', 'client_nom']) as $ligne) {
                $jour = $jours->jourDe(ValeurSimple::enEntier($ligne->magasin_id), ValeurSimple::enTexte($ligne->date_emission));

                if ($jour !== null) {
                    $documents[] = [
                        'document' => $document,
                        'id' => ValeurSimple::enEntier($ligne->id),
                        'magasin' => ValeurSimple::enEntier($ligne->magasin_id),
                        'jour' => $jour,
                        'ht' => $this->calculateur->enCentiemes($ligne->total_ht),
                        'tva' => $this->calculateur->enCentiemes($ligne->total_tva),
                        'ttc' => $this->calculateur->enCentiemes($ligne->total_ttc),
                        'type_client' => ValeurSimple::enTexte($ligne->type_client),
                        'client' => ValeurSimple::enEntier($ligne->client_id),
                        'client_nom' => ValeursLues::texteOuNul($ligne->client_nom),
                    ];
                }
            }
        }

        return $documents;
    }

    /**
     * Les lignes des tickets, des factures et des avoirs émis du $du au $au
     * compris ; une ligne d'avoir d'un service porte le service de la ligne
     * vendue qu'elle corrige. Une ligne qui ne bouge pas le stock, parce
     * qu'elle régularise une vente qui l'a déjà fait, porte la facture de
     * cette vente (sortie_par) : la ligne d'une facture rectificative, la
     * facture qu'elle rectifie ; la ligne d'un avoir de régularisation, la
     * facture de la ligne qu'elle corrige (voir RegularisationDUneFacture
     * et RegularisationDUneExoneration) ; null pour toute autre ligne.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @return list<LigneDeVente>
     *
     * @throws AuthorizationException un magasin que le périmètre ne permet pas
     */
    public function lignes(Perimetre $perimetre, Collection $magasins, string $du, string $au): array
    {
        $perimetre->exiger($magasins, SourceDesGraphiques::Ventes);

        if ($magasins->isEmpty()) {
            return [];
        }

        $jours = new JoursDesMagasins($magasins, $du, $au);
        $colonnes = ['nature', 'description', 'quantite', 'taux_tva', 'montant_ht', 'montant_tva', 'montant_ttc', 'produit_id'];
        $vendues = $this->emis('factures', $magasins, $jours)
            ->join('lignes_facture', fn ($jointure) => $jointure->on('lignes_facture.facture_id', '=', 'factures.id')->on('lignes_facture.magasin_id', '=', 'factures.magasin_id'))
            ->get(['factures.id as document_id', 'lignes_facture.id as ligne_id', 'factures.magasin_id', 'factures.date_emission', 'factures.facture_rectifiee_id as sortie_par', 'lignes_facture.service_id', ...array_map(fn (string $colonne): string => "lignes_facture.{$colonne}", $colonnes)]);
        $rendues = $this->emis('avoirs', $magasins, $jours)
            ->join('lignes_avoir', fn ($jointure) => $jointure->on('lignes_avoir.avoir_id', '=', 'avoirs.id')->on('lignes_avoir.magasin_id', '=', 'avoirs.magasin_id'))
            ->leftJoin('lignes_facture', fn ($jointure) => $jointure->on('lignes_facture.id', '=', 'lignes_avoir.ligne_facture_id')->on('lignes_facture.magasin_id', '=', 'lignes_avoir.magasin_id'))
            ->get(['avoirs.id as document_id', 'lignes_avoir.id as ligne_id', 'avoirs.magasin_id', 'avoirs.date_emission', 'avoirs.motif_regularisation', 'lignes_facture.facture_id as facture_corrigee', 'lignes_facture.service_id', ...array_map(fn (string $colonne): string => "lignes_avoir.{$colonne}", $colonnes)]);
        $lignes = [];

        foreach (['facture' => $vendues, 'avoir' => $rendues] as $document => $lues) {
            foreach ($lues as $ligne) {
                $jour = $jours->jourDe(ValeurSimple::enEntier($ligne->magasin_id), ValeurSimple::enTexte($ligne->date_emission));

                if ($jour !== null) {
                    $lignes[] = $this->ligne($document, $ligne, $jour);
                }
            }
        }

        return $lignes;
    }

    /**
     * @return LigneDeVente
     */
    private function ligne(string $document, stdClass $ligne, string $jour): array
    {
        return [
            'document' => $document === 'avoir' ? 'avoir' : 'facture',
            'document_id' => ValeurSimple::enEntier($ligne->document_id),
            'ligne_id' => ValeurSimple::enEntier($ligne->ligne_id),
            'magasin' => ValeurSimple::enEntier($ligne->magasin_id),
            'jour' => $jour,
            'nature' => ValeurSimple::enTexte($ligne->nature),
            'produit' => ValeurSimple::enEntierOuNul($ligne->produit_id),
            'service' => ValeurSimple::enEntierOuNul($ligne->service_id),
            'description' => ValeurSimple::enTexte($ligne->description),
            'quantite' => ValeurSimple::enEntier($ligne->quantite),
            'taux' => $this->calculateur->enCentiemes($ligne->taux_tva),
            'ht' => $this->calculateur->enCentiemes($ligne->montant_ht),
            'tva' => $this->calculateur->enCentiemes($ligne->montant_tva),
            'ttc' => $this->calculateur->enCentiemes($ligne->montant_ttc),
            'sortie_par' => $document === 'avoir'
                ? ($ligne->motif_regularisation === null ? null : ValeurSimple::enEntierOuNul($ligne->facture_corrigee))
                : ValeurSimple::enEntierOuNul($ligne->sortie_par),
        ];
    }

    /**
     * Les documents émis de cette table dans ces magasins, entre les
     * instants qui couvrent ces jours dans tous leurs calendriers : chacun
     * se range ensuite dans le jour du sien.
     *
     * @param  'factures'|'avoirs'  $table
     * @param  Collection<int, Magasin>  $magasins
     */
    private function emis(string $table, Collection $magasins, JoursDesMagasins $jours): Builder
    {
        [$debut, $fin] = $jours->bornes();

        return DB::table($table)
            ->whereIn("{$table}.magasin_id", $magasins->modelKeys())
            ->when(
                $table === 'factures',
                fn (Builder $factures) => $factures->where('factures.statut', '<>', StatutFacture::Brouillon->value),
                fn (Builder $avoirs) => $avoirs->where('avoirs.statut', StatutAvoir::Emise->value),
            )
            ->where("{$table}.date_emission", '>=', $debut)
            ->where("{$table}.date_emission", '<', $fin)
            ->orderBy("{$table}.id");
    }
}
