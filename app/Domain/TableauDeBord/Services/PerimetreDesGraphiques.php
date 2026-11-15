<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Calendrier\Calendrier;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qui voit les chiffres clés et les graphiques du tableau de bord, où, et
 * sur quels magasins (décision de l'éditeur du 14/11/2026 ; CLAUDE.md,
 * « Tableau de bord ») ; seul constructeur d'un Perimetre :
 *
 * - l'espace de la société : le propriétaire, sur tous ses magasins réels ;
 *   un administrateur, sur ceux de sa portée où le propriétaire lui a
 *   coché « Tableau de bord : voir les graphiques »
 *   (PermissionRegistry::GRAPHIQUES_VOIR) ; personne d'autre ;
 * - le panneau d'un magasin réel, sur ce seul magasin : le propriétaire ;
 *   un administrateur ou un responsable à qui la case est cochée dans ce
 *   magasin ; un compte d'un cabinet lié vérifié et l'expert-comptable
 *   déclaré, qui l'ouvrent ; jamais un vendeur, un observateur ni un
 *   compte d'entraînement ;
 * - l'espace du cabinet : le propriétaire du cabinet et le collaborateur à
 *   qui le client est confié, sur les magasins réels que le client ouvre au
 *   cabinet, tant que le cabinet est vérifié et le lien actif (voir
 *   AccesDesCabinets::liensOuverts()) ;
 * - la page de l'expert-comptable déclaré : lui seul, sur les magasins de
 *   sa déclaration (voir ExpertsComptablesDeclares::idsDesMagasinsOuverts()).
 *
 * Jamais le magasin de démonstration, ni un magasin d'une autre société.
 * De chaque magasin, les sources que le compte y voit en entier, lues sur
 * ses autorisations dans ce magasin (voir User::autorisationsDans() : ses
 * cases, ou, pour un cabinet ou l'expert-comptable déclaré, les
 * consultations que le client leur accorde) ; une source des articles
 * seulement dans une société qui en a. Chaque lecture filtre
 * explicitement par la société et les magasins du compte.
 */
final class PerimetreDesGraphiques
{
    public const REFUS = 'Ces graphiques ne vous sont pas ouverts.';

    public function __construct(
        private readonly AccesDesCabinets $cabinets,
        private readonly ExpertsComptablesDeclares $expertsComptables,
    ) {}

    /**
     * Le périmètre de l'espace de la société pour ce compte.
     *
     * @throws AuthorizationException quand il n'y voit aucun graphique
     */
    public function deLaSociete(User $compte, Societe $societe): Perimetre
    {
        if (! $compte->peutAdministrer() || $societe->getKey() !== $compte->societe_id || $societe->estUnCabinet()) {
            throw new AuthorizationException(self::REFUS);
        }

        $compte->casesDeSesAffectations();
        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('id')->get()
            ->filter(fn (Magasin $magasin): bool => $compte->estProprietaire() || in_array(PermissionRegistry::GRAPHIQUES_VOIR, $compte->autorisationsDans($magasin), true));

        return $this->construire(EcranDuTableauDeBord::Societe, $compte, $societe, $magasins, Calendrier::deParis());
    }

    /**
     * Voit-il les graphiques de l'espace de la société ? Le tableau de
     * bord de la société le demande avant d'y monter la grille : un
     * administrateur qui n'a la case dans aucune de ses affectations n'en
     * voit aucun, sans que son périmètre soit lu.
     */
    public function voitLesGraphiquesDeLaSociete(User $compte, Societe $societe): bool
    {
        if (! $compte->estProprietaire() && ! in_array(PermissionRegistry::GRAPHIQUES_VOIR, $compte->casesDeSesAffectations(), true)) {
            return false;
        }

        try {
            $this->deLaSociete($compte, $societe);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * Le périmètre du panneau de ce magasin pour ce compte : ce seul
     * magasin.
     *
     * @throws AuthorizationException quand il n'y voit aucun graphique
     */
    public function duMagasin(User $compte, Magasin $magasin): Perimetre
    {
        if (! $this->voitLesGraphiquesDuMagasin($compte, $magasin)) {
            throw new AuthorizationException(self::REFUS);
        }

        return $this->construire(EcranDuTableauDeBord::Magasin, $compte, $magasin->societe, new Collection([$magasin]), $magasin->calendrier());
    }

    /**
     * Voit-il les graphiques dans le panneau de ce magasin ? Le widget du
     * tableau de bord du magasin le demande avant de s'afficher.
     */
    public function voitLesGraphiquesDuMagasin(User $compte, Magasin $magasin): bool
    {
        if ($magasin->est_demonstration || ! $compte->peutOuvrir($magasin)) {
            return false;
        }

        return $compte->entreParLesAutorisationsDUnCabinet()
            || ($compte->estProprietaire() && $magasin->societe_id === $compte->societe_id)
            || in_array(PermissionRegistry::GRAPHIQUES_VOIR, $compte->autorisationsDans($magasin), true);
    }

    /**
     * Le périmètre de la page d'un client dans l'espace du cabinet : ce
     * lien doit être l'un de ceux que le cabinet ouvre au compte.
     *
     * @throws AuthorizationException
     */
    public function duClient(User $compte, LienCabinet $lien): Perimetre
    {
        $ouvert = $this->cabinets->liensOuverts($compte)->whereKey($lien->getKey())->exists();

        if (! $ouvert) {
            throw new AuthorizationException(self::REFUS);
        }

        $client = Societe::query()->whereKey($lien->societe_id)->firstOrFail();
        $magasins = Magasin::query()->with('societe')
            ->where('societe_id', $client->getKey())
            ->where('est_demonstration', false)
            ->whereKey($this->cabinets->idsDesMagasinsOuverts($compte))
            ->orderBy('id')
            ->get();

        return $this->construire(EcranDuTableauDeBord::Cabinet, $compte, $client, $magasins, Calendrier::deParis());
    }

    /**
     * Voit-il les graphiques de ce client dans l'espace du cabinet ? Le
     * lien est l'un de ceux que le cabinet lui ouvre, et le client lui
     * ouvre au moins un magasin réel : une société invitée par le cabinet
     * naît sans magasin. La fiche du client et la page de ses graphiques
     * le demandent avant d'offrir « Graphiques » ou d'y monter la grille.
     */
    public function voitLesGraphiquesDuClient(User $compte, LienCabinet $lien): bool
    {
        try {
            $this->duClient($compte, $lien);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * Le périmètre de la page « Tableau de bord de la société » de
     * l'expert-comptable déclaré : les magasins de sa déclaration.
     *
     * @throws AuthorizationException
     */
    public function delExpertComptable(User $compte): Perimetre
    {
        if (! $compte->estExpertComptable() || ! $compte->estActif()) {
            throw new AuthorizationException(self::REFUS);
        }

        $magasins = Magasin::query()->with('societe')
            ->where('societe_id', $compte->societe_id)
            ->where('est_demonstration', false)
            ->whereKey($this->expertsComptables->idsDesMagasinsOuverts($compte))
            ->orderBy('id')
            ->get();

        return $this->construire(EcranDuTableauDeBord::ExpertComptable, $compte, $compte->societe, $magasins, Calendrier::deParis());
    }

    /**
     * Les magasins de cette société où le compte voit au moins une source,
     * chacun avec ses sources ; aucun magasin : refusé.
     *
     * @param  iterable<Magasin>  $candidats
     *
     * @throws AuthorizationException
     */
    private function construire(EcranDuTableauDeBord $ecran, User $compte, Societe $societe, iterable $candidats, Calendrier $calendrier): Perimetre
    {
        $magasins = new Collection;
        $sources = [];

        foreach ($candidats as $magasin) {
            if ($magasin->societe_id !== $societe->getKey() || $magasin->est_demonstration) {
                continue;
            }

            $autorisations = $compte->autorisationsDans($magasin);
            $vues = array_values(array_filter(
                SourceDesGraphiques::cases(),
                fn (SourceDesGraphiques $source): bool => $source->estVueAvec($autorisations) && (! $source->exigeDesArticles() || $societe->aDesArticles()),
            ));

            $magasin->setRelation('societe', $societe);
            $magasins->put(CleDuModele::de($magasin), $magasin);
            $sources[CleDuModele::de($magasin)] = $vues;
        }

        if ($magasins->isEmpty()) {
            throw new AuthorizationException(self::REFUS);
        }

        return new Perimetre($ecran, $societe, $magasins, $sources, $calendrier);
    }
}
