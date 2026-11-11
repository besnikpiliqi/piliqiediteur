<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

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
 * donc ouvrir la page des relevés où mène chaque magasin. Leurs relevés et
 * leurs caisses ouvertes se lisent d'un coup, hors de MagasinScope, chaque
 * requête filtrée explicitement par ces seuls magasins et la journée de
 * chacun : la page lit la base autant de fois pour un magasin que pour
 * vingt.
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
     * plus ancien (le magasin d'un relevé, lu pour le ranger, ne reste pas
     * sur le relevé rendu).
     *
     * @return list<array{magasin: Magasin, calendrier: Calendrier, journee: string, caisses_ouvertes: list<string>, releves: Collection<int, ClotureCaissePersonnelle>}>
     */
    public function pour(User $compte, ?string $journee): array
    {
        $magasins = $this->magasins($compte)->keyBy(fn (Magasin $magasin): int => CleDuModele::de($magasin));

        if ($magasins->isEmpty()) {
            return [];
        }

        $journees = $magasins->map(fn (Magasin $magasin): string => $journee ?? $magasin->calendrier()->aujourdhui())->all();
        $ouvertes = $this->verificateur->vendeursAvecCaisseOuverteDans($magasins->map(fn (Magasin $magasin): array => [$magasin, $magasin->calendrier()->aujourdhui()])->all());
        $releves = ClotureCaissePersonnelle::withoutGlobalScope(MagasinScope::class)
            ->where(function (Builder $duJour) use ($magasins, $journees): void {
                foreach ($magasins as $id => $magasin) {
                    [$debut, $fin] = $magasin->calendrier()->bornesDuJour($journees[$id]);
                    $duJour->orWhere(fn (Builder $duMagasin) => $duMagasin->where('magasin_id', $id)->where('cloturee_a', '>=', $debut)->where('cloturee_a', '<', $fin));
                }
            })
            ->orderByDesc('cloturee_a')
            ->orderByDesc('id')
            ->get(['magasin_id', ...self::COLONNES_DU_RELEVE])
            ->groupBy('magasin_id');

        return array_values($magasins
            ->map(fn (Magasin $magasin, int $id): array => [
                'magasin' => $magasin,
                'calendrier' => $magasin->calendrier(),
                'journee' => $journees[$id],
                'caisses_ouvertes' => ValeursLues::textes($ouvertes[$id]->pluck('name')),
                'releves' => (new Collection($releves->get($id)?->all() ?? []))->each(
                    fn (ClotureCaissePersonnelle $releve): ClotureCaissePersonnelle => $releve->setRawAttributes(Arr::except($releve->getAttributes(), 'magasin_id'), true),
                ),
            ])
            ->all());
    }
}
