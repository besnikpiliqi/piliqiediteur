<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\LectureDeLaTva;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qui voit les chiffres clés et les graphiques du tableau de bord, où, et
 * sur quels magasins (décision de l'éditeur du 14/11/2026 ; CLAUDE.md,
 * « Tableau de bord ») ; seul constructeur d'un Perimetre :
 *
 * - l'espace de la société : le propriétaire, sur tous ses magasins ;
 *   un administrateur, sur ceux de sa portée où le propriétaire lui a
 *   coché « Tableau de bord : voir les graphiques »
 *   (PermissionRegistry::GRAPHIQUES_VOIR) ; personne d'autre ;
 * - le panneau d'un magasin, sur ce seul magasin : le propriétaire ;
 *   un administrateur ou un responsable à qui la case est cochée dans ce
 *   magasin ; un compte d'un cabinet lié vérifié et l'expert-comptable
 *   déclaré, qui l'ouvrent ; jamais un vendeur ni un observateur ;
 * - l'espace du cabinet : le propriétaire du cabinet et le collaborateur à
 *   qui le client est confié, sur les magasins que le client ouvre au
 *   cabinet, tant que le cabinet est vérifié et le lien actif (voir
 *   AccesDesCabinets::liensOuverts()) ;
 * - la page de l'expert-comptable déclaré : lui seul, sur les magasins de
 *   sa déclaration (voir ExpertsComptablesDeclares::idsDesMagasinsOuverts()).
 *
 * Jamais un magasin d'une autre société.
 * De chaque magasin, les sources que le compte y voit en entier, lues sur
 * ses autorisations dans ce magasin (voir User::autorisationsDans() : ses
 * cases, ou, pour un cabinet ou l'expert-comptable déclaré, les
 * consultations que le client leur accorde) ; une source des articles
 * seulement dans une société qui en a. Chaque lecture filtre
 * explicitement par la société et les magasins du compte. Les charges de
 * la société s'ajoutent à la TVA pour qui les voit (voir
 * DroitsSurLesCharges et Perimetre::chargesComptees()).
 */
final class PerimetreDesGraphiques
{
    public const REFUS = 'Ces graphiques ne vous sont pas ouverts.';

    public function __construct(
        private readonly AccesDesCabinets $cabinets,
        private readonly ExpertsComptablesDeclares $expertsComptables,
        private readonly DroitsSurLesCharges $charges,
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
        $magasins = $compte->magasinsDeSaPortee()->orderBy('id')->get()
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
        if (! $compte->peutOuvrir($magasin)) {
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
            ->whereKey($this->cabinets->idsDesMagasinsOuverts($compte))
            ->orderBy('id')
            ->get();

        return $this->construire(EcranDuTableauDeBord::Cabinet, $compte, $client, $magasins, Calendrier::deParis());
    }

    /**
     * Voit-il les graphiques de ce client dans l'espace du cabinet ? Le
     * lien est l'un de ceux que le cabinet lui ouvre, et le client lui
     * ouvre au moins un magasin : une société invitée par le cabinet
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
     * Ce que ce compte voit, dans l'espace de la société, de sa TVA (lot
     * TVA27, voir LectureDeLaTva) : rien sans graphiques, la carte de la
     * TVA sans le report du crédit, ou sa TVA avec le report, sur des
     * graphiques qui comptent tous ses magasins et ses charges de « Toute
     * la société » (voir Perimetre::reporteLeCreditDeTva()). Le message du
     * crédit de TVA ne dit son montant qu'avec le report, au propriétaire
     * et au comptable (voir MessagesDuCreditDeTva::message()).
     */
    public function lectureDeLaTvaDeLaSociete(User $compte, Societe $societe): LectureDeLaTva
    {
        return LectureDeLaTva::du($this->voitLesGraphiquesDeLaSociete($compte, $societe) ? $this->deLaSociete($compte, $societe) : null);
    }

    /**
     * De même, sur la page d'un client lié dans l'espace du cabinet.
     */
    public function lectureDeLaTvaDuClient(User $compte, LienCabinet $lien): LectureDeLaTva
    {
        try {
            return LectureDeLaTva::du($this->duClient($compte, $lien));
        } catch (AuthorizationException) {
            return LectureDeLaTva::Aucune;
        }
    }

    /**
     * De même, sur la page « Tableau de bord de la société » de
     * l'expert-comptable déclaré.
     */
    public function lectureDeLaTvaDeLExpertComptable(User $compte): LectureDeLaTva
    {
        try {
            return LectureDeLaTva::du($this->delExpertComptable($compte));
        } catch (AuthorizationException) {
            return LectureDeLaTva::Aucune;
        }
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
            if ($magasin->societe_id !== $societe->getKey()) {
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

        // Tous les magasins de la société, ouverts, fermés ou cédés, en
        // identifiants seulement, lus pour savoir si la TVA du périmètre
        // compte toute la société (voir Perimetre::reporteLeCreditDeTva()),
        // jamais montrés.
        $deLaSociete = ValeursLues::entiers(Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->pluck('id'));

        return new Perimetre($ecran, $societe, $magasins, $sources, $calendrier, $this->charges->pourLesGraphiques($compte, $societe, $magasins), false, $deLaSociete);
    }
}
