<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\TableauDeBord\Support\JoursDesMagasins;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * L'avertissement du seuil du réel normal trimestriel (lot TVA27, point 3
 * du cahier) : une société au trimestriel dont le chiffre d'affaires HT de
 * l'année civile en cours, lu dans PILIQI (tous ses magasins, ventes
 * nettes des avoirs, chaque document dans l'année de son magasin), dépasse
 * 1 100 000,00 € (voir RegimeTva::SEUIL_DU_TRIMESTRIEL_EN_CENTIMES) reçoit,
 * une fois par année, une ligne dans l'« À faire » de l'espace de la
 * société, pour son propriétaire et l'administrateur qui peut modifier ses
 * réglages fiscaux, jusqu'à ce que l'un d'eux dise « C'est vérifié » (voir
 * VerifierLeSeuilDuTrimestrielAction) : ce geste, inscrit au journal
 * d'audit de la société avec son année, fait seul foi. 1 100 000,00 €
 * tout juste n'avertit pas ; une société au mensuel ou en franchise n'est
 * jamais avertie. Rien n'est bloqué : PILIQI ne voit ni les acquisitions
 * taxables ni les ventes faites hors de PILIQI, que la phrase rappelle.
 *
 * Une lecture hors du magasin courant (CLAUDE.md, « Produit ») : les
 * factures, tickets et avoirs émis de tous les magasins de cette société
 * seulement, filtrés par eux, limités à leur magasin, leur instant
 * d'émission et leur total HT, additionnés ici en centimes, jamais par
 * une somme de la base ; aucun nom de client, aucun numéro de document,
 * et le montant ne se montre pas : seul le dépassement se dit.
 */
final class SeuilDuTrimestriel
{
    public const PHRASE = 'Votre chiffre d\'affaires de l\'année dépasse 1 100 000 € : la déclaration de TVA devient mensuelle. Vérifiez votre régime de TVA.';

    public const PRECISION = 'Calculé dans PILIQI : les ventes de tous vos magasins depuis le 1er janvier, nettes des avoirs. PILIQI ne voit ni vos acquisitions taxables ni les ventes faites hors de PILIQI. Rien n\'est bloqué.';

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * L'année civile, au calendrier de Paris, dont l'« À faire » de cette
     * société doit dire le dépassement ; null quand il n'a rien à dire :
     * une société qui n'est pas au réel normal trimestriel, sous le seuil,
     * ou dont le dépassement de cette année est déjà vérifié.
     */
    public function anneeASignaler(Societe $societe): ?int
    {
        if ($societe->regime_tva !== RegimeTva::ReelNormalTrimestriel) {
            return null;
        }

        $annee = (int) substr(Calendrier::deParis()->aujourdhui(), 0, 4);

        return ! $this->estVerifie($societe, $annee) && $this->depasse($societe, $annee) ? $annee : null;
    }

    /**
     * Le chiffre d'affaires HT de cette année civile dépasse-t-il le seuil ?
     */
    public function depasse(Societe $societe, int $annee): bool
    {
        return $this->chiffreDAffaires($societe, $annee) > RegimeTva::SEUIL_DU_TRIMESTRIEL_EN_CENTIMES;
    }

    /**
     * Le dépassement de cette année est-il déjà vérifié : une entrée du
     * journal d'audit de la société le dit (voir
     * VerifierLeSeuilDuTrimestrielAction) ?
     */
    public function estVerifie(Societe $societe, int $annee): bool
    {
        return EntreeAudit::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->where('action', ActionAuditee::SeuilDuTrimestrielVerifie->value)
            ->where('nouvelles_valeurs->annee', (string) $annee)
            ->exists();
    }

    /**
     * Le chiffre d'affaires HT de cette année civile, en centimes : les
     * tickets et les factures émis moins les avoirs émis de tous les
     * magasins de la société, chaque document dans l'année du calendrier
     * de son magasin.
     */
    public function chiffreDAffaires(Societe $societe, int $annee): int
    {
        $magasins = Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get(['id', 'societe_id', 'territoire']);

        if ($magasins->isEmpty()) {
            return 0;
        }

        $jours = new JoursDesMagasins($magasins, sprintf('%04d-01-01', $annee), sprintf('%04d-12-31', $annee));
        [$debut, $fin] = $jours->bornes();
        $total = 0;

        foreach (['factures' => 1, 'avoirs' => -1] as $table => $signe) {
            $lus = DB::table($table)
                ->whereIn("{$table}.magasin_id", $magasins->modelKeys())
                ->when(
                    $table === 'factures',
                    fn (Builder $factures): Builder => $factures->where('factures.statut', '<>', StatutFacture::Brouillon->value),
                    fn (Builder $avoirs): Builder => $avoirs->where('avoirs.statut', StatutAvoir::Emise->value),
                )
                ->where("{$table}.date_emission", '>=', $debut)
                ->where("{$table}.date_emission", '<', $fin)
                ->orderBy("{$table}.id")
                ->get(["{$table}.magasin_id", "{$table}.date_emission", "{$table}.total_ht"]);

            foreach ($lus as $ligne) {
                if ($jours->jourDe(ValeurSimple::enEntier($ligne->magasin_id), ValeurSimple::enTexte($ligne->date_emission)) !== null) {
                    $total += $signe * $this->calculateur->enCentiemes($ligne->total_ht);
                }
            }
        }

        return $total;
    }
}
