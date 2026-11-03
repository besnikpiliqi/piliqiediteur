<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les caisses de chaque magasin, à la page « Les caisses » de l'espace
 * d'administration de la société (voir LesCaisses) : une lecture hors du
 * magasin courant, déclarée dans CLAUDE.md.
 *
 * Elle ne lit que les magasins réels de la portée de la personne (voir
 * User::magasinsDeSaPortee() : toute sa société pour le propriétaire, la
 * sélection d'un administrateur), jamais le magasin de démonstration ni
 * un magasin d'une autre société, et seulement ceux où elle voit déjà les
 * relevés de toute l'équipe (cloture_caisse.view_toutes : toujours pour le
 * propriétaire, la case cochée pour un administrateur), et dont elle peut
 * donc ouvrir la page des relevés où mène chaque magasin. Chaque magasin
 * se lit à son tour comme magasin actif (voir TenantContext::runAs()) :
 * MagasinScope reste en place, aucune requête ne contourne sa portée.
 *
 * De chacun, elle ne rend que les relevés de caisse fermés le jour choisi,
 * ou, sans jour choisi, sa journée en cours, chaque magasin dans son
 * calendrier (voir Magasin::calendrier() : deux magasins d'une même
 * société peuvent ne pas être le même jour), avec les seules colonnes du
 * relevé (voir COLONNES_DU_RELEVE : nom figé du vendeur, période, mode de
 * fermeture et nom figé de qui l'a fermée, totaux), et le nom des vendeurs
 * dont la caisse est encore ouverte à son jour (voir
 * VerificateurCaissesPersonnelles) ; aucun identifiant de compte, aucun
 * nom de client, aucun document. Les montants sont ceux que chaque relevé
 * a enregistrés, jamais additionnés par la base.
 */
final class CaissesDeLaSociete
{
    /**
     * Les colonnes d'un relevé que la page lit, et rien d'autre.
     *
     * @var list<string>
     */
    public const COLONNES_DU_RELEVE = [
        'vendeur_nom',
        'periode_debut',
        'jusqu_au',
        'cloturee_a',
        'mode_fermeture',
        'fermee_par_nom',
        'nombre_ventes',
        'total_ht',
        'total_tva',
        'total_ttc',
        'encaissements',
        'credit_avoir',
        'reste_du',
    ];

    public function __construct(private readonly VerificateurCaissesPersonnelles $verificateur) {}

    /**
     * Les magasins réels de sa portée où cette personne voit les relevés
     * de toute l'équipe, par nom.
     *
     * @return Collection<int, Magasin>
     */
    public function magasins(User $compte): Collection
    {
        return $compte->magasinsDeSaPortee()
            ->where('est_demonstration', false)
            ->orderBy('nom')
            ->orderBy('id')
            ->get()
            ->filter(fn (Magasin $magasin): bool => in_array(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES, $compte->autorisationsDans($magasin), true))
            ->values();
    }

    /**
     * Pour chacun de ces magasins, dans leur ordre : le magasin, son
     * calendrier, la journée de ses relevés (ce jour choisi, « AAAA-MM-JJ »,
     * ou, sans jour choisi, sa journée en cours), les noms des vendeurs
     * dont la caisse est encore ouverte à son jour, et ses relevés fermés
     * pendant cette journée, de son minuit au suivant, du plus récent au
     * plus ancien.
     *
     * @return list<array{magasin: Magasin, calendrier: Calendrier, journee: string, caisses_ouvertes: list<string>, releves: Collection<int, ClotureCaissePersonnelle>}>
     */
    public function pour(User $compte, ?string $journee): array
    {
        $contexte = app(TenantContext::class);

        return $this->magasins($compte)
            ->map(function (Magasin $magasin) use ($contexte, $journee): array {
                $calendrier = $magasin->calendrier();
                $journeeDuMagasin = $journee ?? $calendrier->aujourdhui();
                [$debut, $fin] = $calendrier->bornesDuJour($journeeDuMagasin);

                return $contexte->runAs($magasin, fn (): array => [
                    'magasin' => $magasin,
                    'calendrier' => $calendrier,
                    'journee' => $journeeDuMagasin,
                    'caisses_ouvertes' => $this->verificateur->vendeursAvecCaisseOuverte($calendrier->aujourdhui())->pluck('name')->values()->all(),
                    'releves' => ClotureCaissePersonnelle::query()
                        ->where('cloturee_a', '>=', $debut)
                        ->where('cloturee_a', '<', $fin)
                        ->orderByDesc('cloturee_a')
                        ->orderByDesc('id')
                        ->get(self::COLONNES_DU_RELEVE),
                ]);
            })
            ->all();
    }
}
