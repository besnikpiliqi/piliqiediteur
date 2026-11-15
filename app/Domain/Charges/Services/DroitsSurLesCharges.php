<?php

namespace App\Domain\Charges\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;

/**
 * Qui voit, ajoute et valide les charges d'une société (décisions de
 * l'éditeur du 14/11/2026, lot CHARGES) ; jamais dans un cabinet
 * d'expertise comptable, qui n'a pas de charges dans PILIQI, jamais pour
 * un compte qui ne peut pas agir :
 * - le propriétaire voit tout, ajoute, saisit et corrige avant la
 *   validation ; il valide seulement quand sa société n'a aucun comptable
 *   qui valide ses charges (voir aUnComptable()), et sa validation est
 *   marquée « validée par le propriétaire » ;
 * - un administrateur à qui le propriétaire coche « Charges : ajouter »
 *   voit et ajoute les charges de « Toute la société » et celles des
 *   magasins réels de sa portée où la case est cochée ; jamais il ne
 *   valide ;
 * - un responsable à qui le propriétaire la coche voit et ajoute celles de
 *   son magasin seulement, rien de « Toute la société » ni d'un autre
 *   magasin ; jamais il ne valide ;
 * - le cabinet lié, par son propriétaire et le collaborateur à qui le
 *   client est confié, et l'expert-comptable déclaré, quand le client leur
 *   accorde « Charges : voir, ajouter, saisir et valider » (accordée par
 *   défaut, voir PermissionRegistry::parDefautDuCabinet()) : ils voient,
 *   ajoutent, valident, corrigent et renvoient les charges de « Toute la
 *   société » et des magasins réels que le client leur ouvre, parmi les
 *   types que la société propose ; jamais ils ne touchent aux types de la
 *   société ni à ses types habituels, réglages de la société (décision de
 *   Claude, D76) ;
 * - les vendeurs, les observateurs et les comptes d'entraînement : rien.
 * Une lecture hors de la société courante, pour un compte d'un cabinet :
 * ses liens ouverts et ses autorisations par client (voir
 * AccesDesCabinets), filtrés par le client. Chaque action relit son
 * auteur sous verrou puis repose la question (voir
 * App\Domain\Charges\Actions).
 */
final class DroitsSurLesCharges
{
    public function __construct(
        private readonly AccesDesCabinets $cabinets,
        private readonly ExpertsComptablesDeclares $expertsComptables,
    ) {}

    /**
     * L'accès de ce compte aux charges de cette société ; null quand il n'en
     * a aucun. Pour seulement les lire (la TVA du tableau de bord), le
     * propriétaire n'a pas à savoir s'il les valide : sans lui demander
     * s'il a un comptable, il ne les valide pas.
     */
    public function pour(User $compte, Societe $societe, bool $pourValider = true): ?AccesAuxCharges
    {
        if (! $compte->peutAgir() || $societe->estUnCabinet() || ! $societe->type_activite->aDesMagasins()) {
            return null;
        }

        if ($compte->estDUnCabinet()) {
            $lien = $this->cabinets->liensOuverts($compte)->where('liens_cabinet.societe_id', $societe->getKey())->first(['liens_cabinet.id', 'liens_cabinet.societe_id']);

            return $lien === null ? null : $this->duClient($compte, $lien, $societe);
        }

        if ($compte->societe_id !== $societe->getKey()) {
            return null;
        }

        return match (true) {
            $compte->estProprietaire() => new AccesAuxCharges($compte, $societe, true, null, ! $pourValider || $this->aUnComptable($societe) ? null : ValideePar::Proprietaire, true),
            $compte->estAdministrateur() => $this->delAdministrateur($compte, $societe),
            $compte->estExpertComptable() => $this->delExpertComptable($compte, $societe),
            $compte->estMembre() => $this->duResponsable($compte, $societe),
            default => null,
        };
    }

    /**
     * Ce que ce compte voit des charges de cette société pour les
     * graphiques de ces magasins réels, qu'il ouvre déjà (voir
     * PerimetreDesGraphiques) : un compte qui entre par les autorisations
     * que le client accorde à son cabinet ou à son expert-comptable voit
     * les charges de « Toute la société » et de ceux de ces magasins où il
     * a « Charges : voir, ajouter, saisir et valider », lue dans ses
     * autorisations de chaque magasin, que le périmètre a déjà lues, sans
     * relire ses liens ; tout autre compte, ce que pour() lui donne, sans
     * lui demander s'il les valide. Null quand il n'en voit aucune.
     *
     * @param  iterable<Magasin>  $magasins
     */
    public function pourLesGraphiques(User $compte, Societe $societe, iterable $magasins): ?AccesAuxCharges
    {
        if (! $compte->entreParLesAutorisationsDUnCabinet()) {
            return $this->pour($compte, $societe, pourValider: false);
        }

        if (! $compte->peutAgir() || $societe->estUnCabinet() || ! $societe->type_activite->aDesMagasins()) {
            return null;
        }

        $ouverts = [];

        foreach ($magasins as $magasin) {
            if ($magasin->societe_id === $societe->getKey() && ! $magasin->est_demonstration && in_array(PermissionRegistry::CHARGES_VALIDER, $compte->autorisationsDans($magasin), true)) {
                $ouverts[] = CleDuModele::de($magasin);
            }
        }

        return $ouverts === [] ? null : new AccesAuxCharges($compte, $societe, true, $ouverts, null, false);
    }

    /**
     * La société a-t-elle un comptable qui valide ses charges : un lien
     * actif avec un cabinet vérifié, ou une déclaration de son
     * expert-comptable qui a un compte actif, qui lui accorde « Charges :
     * voir, ajouter, saisir et valider » ? Un client n'a qu'un cabinet à la
     * fois, lié ou déclaré. Sans lui, son propriétaire valide lui-même
     * (décision de l'éditeur du 14/11/2026) ; un comptable à qui il a
     * retiré les charges ne les valide pas, et il les valide alors
     * lui-même (décision de Claude, D76). Lecture filtrée par la société.
     */
    public function aUnComptable(Societe $societe): bool
    {
        $lien = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('liens_cabinet.societe_id', $societe->getKey())
            ->enCours()
            ->first(['id', 'uuid', 'societe_id', 'cabinet_id', 'etat']);

        if ($lien === null || ! in_array(PermissionRegistry::CHARGES_VALIDER, $lien->autorisationsRetenues(), true)) {
            return false;
        }

        return $lien->etat === EtatDuLien::Actif
            ? $lien->cabinet_id !== null && Societe::query()->findOrFail($lien->cabinet_id)->estUnCabinetVerifie()
            : $this->expertsComptables->aUnCompteActif($lien);
    }

    private function delAdministrateur(User $compte, Societe $societe): ?AccesAuxCharges
    {
        if (! in_array(PermissionRegistry::CHARGES_AJOUTER, $compte->casesDeSesAffectations(), true)) {
            return null;
        }

        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('id')->get()
            ->filter(fn (Magasin $magasin): bool => in_array(PermissionRegistry::CHARGES_AJOUTER, $compte->autorisationsDans($magasin), true));

        return new AccesAuxCharges($compte, $societe, true, CleDuModele::desModeles($magasins), null, true);
    }

    private function duResponsable(User $compte, Societe $societe): ?AccesAuxCharges
    {
        $magasin = $compte->magasinDeSonIdentifiant();

        if ($magasin === null || $magasin->est_demonstration || $magasin->societe_id !== $societe->getKey() || ! $compte->estResponsableDe($magasin)
            || ! in_array(PermissionRegistry::CHARGES_AJOUTER, $compte->autorisationsDans($magasin), true)) {
            return null;
        }

        return new AccesAuxCharges($compte, $societe, false, [CleDuModele::de($magasin)], null, false);
    }

    private function delExpertComptable(User $compte, Societe $societe): ?AccesAuxCharges
    {
        $declaration = $this->expertsComptables->declarationDe($compte);

        if ($declaration === null || ! $declaration->estDeclare() || ! in_array(PermissionRegistry::CHARGES_VALIDER, $declaration->autorisationsRetenues(), true)) {
            return null;
        }

        return new AccesAuxCharges($compte, $societe, true, $this->expertsComptables->idsDesMagasinsOuverts($compte), ValideePar::ExpertComptable, false);
    }

    private function duClient(User $compte, LienCabinet $lien, Societe $client): ?AccesAuxCharges
    {
        if (! in_array(PermissionRegistry::CHARGES_VALIDER, $this->cabinets->autorisationsParClient($compte)[CleDuModele::de($client)] ?? [], true)) {
            return null;
        }

        $ouverts = ValeursLues::entiers(Magasin::query()->where('societe_id', $client->getKey())->whereKey($this->cabinets->idsDesMagasinsOuverts($compte))->orderBy('id')->pluck('id'));

        return new AccesAuxCharges($compte, $client, true, $ouverts, ValideePar::Cabinet, false);
    }
}
