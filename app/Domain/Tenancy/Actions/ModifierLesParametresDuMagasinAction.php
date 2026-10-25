<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enregistre ce qu'une personne a corrigé des paramètres du magasin actif
 * depuis l'une de ses pages : ses coordonnées et ses références
 * d'acheteur (CoordonneesMagasin), l'échéance et les mentions de ses
 * factures (ParametresFactures), avec « Paramètres du magasin » ; son
 * barème de prix de vente (ParametresPrixDeVente), avec « Régler les prix
 * de vente » ; ses relances (ParametresRelances), avec « Régler les
 * relances ». Chaque autorisation ne règle que ses propres paramètres, et
 * aucune ce que la société gère (nom, code, adresse, SIRET du magasin).
 *
 * La page a lu les paramètres à son ouverture (voir FicheLue) : seules les
 * corrections de son auteur s'écrivent ; ce qu'une autre personne a
 * changé depuis reste, et une correction qui porte sur un paramètre
 * changé entre-temps est refusée.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou qui
 * exclut toute autre écriture de cette ligne et les gestes qui la lisent
 * sous un verrou partagé (FOR NO KEY UPDATE, que le verrou d'une clé
 * étrangère vers le magasin n'attend pas) : un magasin suspendu, fermé ou
 * cédé, ou dont la société est en lecture seule, ne règle plus rien (voir
 * Magasin::verifierQuIlEstOuvert()), et sa fermeture comme la clôture de
 * l'inscription de sa société, qui verrouillent cette ligne (voir
 * FermerMagasinAction et CloreLInscriptionAction), passent tout entières
 * avant ou après le réglage. Puis son auteur, sous le verrou de sa ligne
 * (voir LecteurDesDroits), qui doit encore avoir l'autorisation de la
 * page. Le réglage se fait au nom de l'auteur relu (voir OperateurCourant).
 */
class ModifierLesParametresDuMagasinAction
{
    public const REFUS_PERIMES = 'Ces paramètres ont été modifiés par une autre personne depuis que vous avez ouvert la page : rechargez-la pour repartir des paramètres actuels, puis refaites votre correction.';

    /**
     * Les paramètres que règle chaque autorisation.
     */
    private const PARAMETRES = [
        PermissionRegistry::PARAMETRES_GERER => ['email', 'telephone', 'references_acheteur', 'delai_paiement_jours', 'mention_penalites_retard', 'mention_indemnite_recouvrement', 'mention_escompte'],
        PermissionRegistry::PRIX_VENTE_REGLER => ['prix_vente_automatique', 'bareme_prix_vente'],
        PermissionRegistry::RELANCES_REGLER => ['relances_automatiques', 'delais_relance'],
    ];

    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  string  $autorisation  celle de la page : PermissionRegistry::PARAMETRES_GERER, PRIX_VENTE_REGLER ou RELANCES_REGLER
     * @param  array<string, mixed>  $ficheLue  les paramètres tels que la page les a lus (voir FicheLue::valeurs())
     * @param  array<string, mixed>  $saisie  les paramètres que la page enregistre
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, string $autorisation, array $ficheLue, array $saisie): Magasin
    {
        $parametres = self::PARAMETRES[$autorisation] ?? throw new LogicException('Cette autorisation ne règle aucun paramètre du magasin.');
        $lue = FicheLue::depuis(Magasin::class, $ficheLue);
        $corrections = $lue->corrections(Arr::only($saisie, $parametres));

        return DB::transaction(function () use ($auteur, $autorisation, $lue, $corrections): Magasin {
            $magasin = app(TenantContext::class)->magasin();
            $relu = Magasin::query()->whereKey($magasin->getKey())->lock('for no key update')->firstOrFail();
            $relu->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, $autorisation, $magasin);
            $lue->refuserUneCorrectionPerimee($relu, $corrections, self::REFUS_PERIMES);

            $this->operateur->pendant($compte, fn (): bool => $relu->fill($corrections)->save());

            return $relu;
        });
    }
}
