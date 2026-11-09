<?php

namespace App\Domain\Authorization;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/**
 * L'unique Gate::before de l'application (voir TenancyServiceProvider).
 * Il ne répond jamais « oui » d'office à une question de policy, pas même
 * pour le propriétaire : les règles qui valent pour tous (lecture seule,
 * validation de la société, document émis, journée clôturée) s'appliquent
 * à lui comme aux autres, et c'est par ses autorisations, toutes sans case
 * sauf celles de la vente, qu'il n'a jamais (voir
 * PermissionRegistry::duProprietaire()), qu'il passe les policies.
 *
 * Un compte qui ne peut pas agir n'a aucun droit, où que ce soit :
 * suspendu ou retiré (voir User::peutAgir()).
 *
 * Un compte d'un cabinet d'expertise comptable (son propriétaire ou un
 * collaborateur) n'a d'autorisation de société que dans l'espace de son
 * cabinet (les paramètres, son identité, pour son propriétaire) ; dans
 * l'espace de son cabinet, les policies du panneau répondent, la lecture
 * seule d'un cabinet suspendu, résilié ou révoqué ne lui laissant que les
 * consultations et les gestes d'accès ; il ne fait jamais rien dans
 * l'espace d'administration d'une autre société ; dans un magasin, il
 * n'agit que dans celui d'un client lié qui lui est ouvert (voir
 * User::peutOuvrir() : son cabinet vérifié et actif, le client confié),
 * avec les règles de ce client (lecture seule, validation), les seules
 * autorisations que le client accorde à son cabinet (refus net pour toute
 * autre), et, pour une question de policy, seulement les gestes de
 * GESTES_D_UN_CABINET : les consultations et les écritures des achats et
 * du stock (pièces du fournisseur comprises), jamais un geste sur l'équipe, le magasin ou la société, ni la
 * vente, l'envoi, la clôture ou l'encaissement.
 *
 * Une autorisation de société (voir AutorisationsSociete) ne vaut que dans
 * la société du compte : celle du contexte, sinon la sienne. Le propriétaire
 * les a toutes, un administrateur celles que le propriétaire a cochées pour
 * lui, tout autre compte aucune ; une société en lecture seule ne garde que
 * celles qui y restent permises (nommer et retirer les responsables,
 * redonner l'accès, sauf dans une société révoquée). Aucune ne suffit
 * seule pour un geste sur un magasin : les policies et les actions
 * vérifient en plus la portée de l'administrateur.
 *
 * Dans l'espace d'administration d'une société (une société posée sans
 * magasin), il refuse tout au compte d'une autre société ; quand la
 * société est en lecture seule (suspendue, résiliée, révoquée ou close,
 * voir Societe::estEnLectureSeule()), tout ce qui n'est ni une
 * consultation ni un geste sur l'équipe (un compte, voir MembrePolicy), et,
 * dans une société révoquée, tout ce qui n'est pas une consultation ; sinon
 * les policies répondent, en mode strict (voir RessourceDuPanneauSociete).
 *
 * Dans un magasin actif, il répond pour toute autorisation de
 * PermissionRegistry : seulement dans un magasin de la société du compte
 * (jamais ailleurs, propriétaire compris) ; oui s'il l'a dans ce magasin
 * (voir User::autorisationsDans() : toutes sauf celles de la vente pour le
 * propriétaire, toutes pour un responsable du magasin, ses cases pour
 * tout autre compte), sinon la question passe aux policies.
 * Quel que soit le compte, il refuse dans ce magasin :
 * - en lecture seule (voir Magasin::estEnLectureSeule()), tout ce qui n'y
 *   reste pas permis : viewAny et view, sur tout modèle (consulter et
 *   télécharger) ; clôturer la journée (ClotureCaissePolicy::cloturer()),
 *   fermer sa caisse et celle d'un vendeur
 *   (ClotureCaissePersonnellePolicy::fermer() et fermerLaCaisseDe()) : les
 *   clôtures continuent jusqu'au bout ; les autorisations de
 *   PermissionRegistry::permisesEnLectureSeule() ; un geste sur l'équipe
 *   du magasin (une affectation, un compte ou un poste reconnu), sauf dans
 *   une société révoquée ;
 * - tant que le magasin ne peut pas vendre (société en cours de
 *   vérification, établissement pas encore confirmé, voir
 *   Magasin::peutVendre()), les autorisations des opérations commerciales
 *   (PermissionRegistry::reserveesAuxOperationsCommerciales()).
 * Une policy qui, pour une consultation, demande une autre autorisation la
 * voit donc refusée : une consultation refusée reste sûre.
 *
 * Il n'a aucun état : le contexte se relit à chaque question, dans le
 * TenantContext de la requête ou du job en cours (lié en scoped()).
 */
final class ControleDesDroits
{
    private const CONSULTATIONS = ['viewAny', 'view', 'voirLesParametres', 'voirLesParametresFiscaux'];

    /**
     * Les gestes de clôture, chacun sur son modèle.
     */
    private const CLOTURES = [
        'cloturer' => ClotureCaisse::class,
        'fermer' => ClotureCaissePersonnelle::class,
        'fermerLaCaisseDe' => ClotureCaissePersonnelle::class,
    ];

    /**
     * Les gestes d'une policy qu'un compte d'un cabinet d'expertise
     * comptable peut demander dans un magasin d'un client, chacun sur son
     * modèle : les consultations, et les écritures des achats et du stock,
     * que la policy contrôle ensuite par l'autorisation que le client
     * accorde au cabinet. Tout autre geste lui est refusé, quelle que soit
     * la ligne en base, sur les comptes, les affectations, les postes
     * reconnus, les magasins et la société, et tout geste de vente,
     * d'envoi, de clôture ou d'encaissement.
     *
     * @var array<string, list<class-string>>
     */
    public const GESTES_D_UN_CABINET = [
        'viewAny' => self::MODELES_CONSULTES_PAR_UN_CABINET,
        'view' => self::MODELES_CONSULTES_PAR_UN_CABINET,
        'create' => [FactureAchat::class, AvoirFournisseur::class, Fournisseur::class, InventaireOuverture::class, Produit::class],
        'update' => [FactureAchat::class, Fournisseur::class, InventaireOuverture::class],
        'valider' => [FactureAchat::class, InventaireOuverture::class],
        'delete' => [FactureAchat::class, Fournisseur::class, InventaireOuverture::class],
        'deleteAny' => [FactureAchat::class, Fournisseur::class, InventaireOuverture::class],
        'restore' => [Fournisseur::class],
        'joindreUnePiece' => [FactureAchat::class, AvoirFournisseur::class],
        'retirerUnePiece' => [FactureAchat::class],
    ];

    /**
     * Les modèles de document ou de fiche qu'un compte d'un cabinet consulte
     * dans un magasin d'un client, selon les autorisations qu'il lui accorde.
     *
     * @var list<class-string>
     */
    private const MODELES_CONSULTES_PAR_UN_CABINET = [
        Client::class, Fournisseur::class, Produit::class, Service::class, Devis::class, Facture::class, Paiement::class, RecuPaiement::class, Avoir::class,
        ClotureCaisse::class, ClotureCaissePersonnelle::class, FactureAchat::class, AvoirFournisseur::class, InventaireOuverture::class, EnregistrementFiscal::class,
    ];

    /**
     * @param  array<int, mixed>  $arguments  le modèle (ou sa classe) sur lequel porte la question, s'il y en a un
     */
    public function __invoke(User $user, string $ability, array $arguments = []): ?bool
    {
        if (! $user->peutAgir()) {
            return false;
        }

        $contexte = app(TenantContext::class);

        if ($user->estDUnCabinet()) {
            return $this->pourUnCompteDeCabinet($user, $ability, $arguments[0] ?? null, $contexte);
        }

        if (in_array($ability, AutorisationsSociete::toutes(), true)) {
            return $this->autorisationDeSociete($user, $ability, $contexte->aUneSociete() ? $contexte->societe() : $user->societe);
        }

        if (! $contexte->has()) {
            if (! $contexte->aUneSociete()) {
                return null;
            }

            return $this->dansLEspaceDeLaSociete($user, $ability, $arguments[0] ?? null, $contexte->societe());
        }

        $magasin = $contexte->magasin();

        if ($magasin->societe_id !== $user->societe_id) {
            return false;
        }

        if ($magasin->estEnLectureSeule() && ! $this->estPermisEnLectureSeule($ability, $arguments[0] ?? null, $magasin->societe)) {
            return false;
        }

        if (! $magasin->peutVendre() && in_array($ability, PermissionRegistry::reserveesAuxOperationsCommerciales(), true)) {
            return false;
        }

        if (! in_array($ability, PermissionRegistry::toutes(), true)) {
            return null;
        }

        return in_array($ability, $user->autorisationsDans($magasin), true) ?: null;
    }

    /**
     * Un compte d'un cabinet d'expertise comptable : ses autorisations de
     * société dans l'espace de son cabinet seulement ; dans l'espace de son
     * cabinet, les policies du panneau, la lecture seule d'un cabinet
     * suspendu, résilié ou révoqué ne lui laissant que les consultations et
     * les gestes d'accès ; jamais rien dans l'espace d'une autre société ;
     * dans un magasin, seulement celui d'un client qu'il ouvre (voir
     * User::peutOuvrir()), avec les règles de ce client (lecture seule,
     * validation) et les seules autorisations que le client accorde au
     * cabinet, un refus net pour toute autre ; et, pour une question de
     * policy, seulement un geste de GESTES_D_UN_CABINET.
     */
    private function pourUnCompteDeCabinet(User $user, string $ability, mixed $cible, TenantContext $contexte): ?bool
    {
        if (in_array($ability, AutorisationsSociete::toutes(), true)) {
            return $contexte->aUneSociete() && ! $contexte->has() && $contexte->societe()->getKey() === $user->societe_id
                ? $this->autorisationDeSociete($user, $ability, $contexte->societe())
                : false;
        }

        if (! $contexte->has()) {
            if (! $contexte->aUneSociete()) {
                return null;
            }

            return $contexte->societe()->getKey() === $user->societe_id
                ? $this->dansLEspaceDeLaSociete($user, $ability, $cible, $contexte->societe())
                : false;
        }

        $magasin = $contexte->magasin();

        if (! $user->peutOuvrir($magasin)) {
            return false;
        }

        if ($magasin->estEnLectureSeule() && ! $this->estPermisEnLectureSeule($ability, $cible, $magasin->societe)) {
            return false;
        }

        // Un cabinet ne reçoit jamais une autorisation réservée aux
        // opérations commerciales (voir PermissionRegistry::duCabinet()) :
        // un magasin qui ne vend pas encore ne lui refuse rien de plus.
        if (in_array($ability, PermissionRegistry::toutes(), true)) {
            return in_array($ability, $user->autorisationsDans($magasin), true);
        }

        foreach (self::GESTES_D_UN_CABINET[$ability] ?? [] as $modele) {
            if (self::porteSur($cible, $modele)) {
                return null;
            }
        }

        return false;
    }

    private function autorisationDeSociete(User $user, string $ability, Societe $societe): ?bool
    {
        if ($societe->getKey() !== $user->societe_id) {
            return false;
        }

        if ($societe->estEnLectureSeule() && ! in_array($ability, AutorisationsSociete::permisesEnLectureSeule($societe->statut === StatutSociete::Revoquee), true)) {
            return false;
        }

        return $user->aLAutorisationDeSociete($ability) ?: null;
    }

    private function dansLEspaceDeLaSociete(User $user, string $ability, mixed $cible, Societe $societe): ?bool
    {
        if ($societe->getKey() !== $user->societe_id) {
            return false;
        }

        if (! $societe->estEnLectureSeule() || in_array($ability, self::CONSULTATIONS, true)) {
            return null;
        }

        return (self::porteSurLEquipe($cible) || ($ability === 'gererSonCabinet' && self::porteSur($cible, Societe::class))) && $societe->statut !== StatutSociete::Revoquee ? null : false;
    }

    private function estPermisEnLectureSeule(string $ability, mixed $cible, Societe $societe): bool
    {
        return in_array($ability, self::CONSULTATIONS, true)
            || (isset(self::CLOTURES[$ability]) && self::porteSur($cible, self::CLOTURES[$ability]))
            || in_array($ability, PermissionRegistry::permisesEnLectureSeule(), true)
            || (self::porteSurLEquipe($cible) && $societe->statut !== StatutSociete::Revoquee);
    }

    /**
     * Un geste sur l'équipe : un compte (voir MembrePolicy), une
     * affectation à un magasin (voir AffectationPolicy), un poste reconnu
     * du magasin (voir PosteReconnuPolicy), ou le lien avec un cabinet
     * d'expertise comptable (voir LienCabinetPolicy), des deux côtés du
     * lien : un accès se coupe toujours.
     */
    private static function porteSurLEquipe(mixed $cible): bool
    {
        return self::porteSur($cible, User::class) || self::porteSur($cible, Affectation::class) || self::porteSur($cible, PosteReconnu::class) || self::porteSur($cible, LienCabinet::class);
    }

    /**
     * @param  class-string  $modele
     */
    private static function porteSur(mixed $cible, string $modele): bool
    {
        return $cible instanceof $modele || $cible === $modele;
    }
}
