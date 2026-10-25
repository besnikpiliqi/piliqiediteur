<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Crée une fiche du magasin depuis un formulaire : un client (sa page, la
 * création rapide d'une vente, de la caisse ou du tableau des clients), un
 * fournisseur ou un service (la liste des services ; un service tapé à la
 * caisse passe par TrouverOuCreerServiceAction). Un produit ne se crée
 * jamais ainsi : il naît d'un achat ou d'un inventaire d'ouverture (voir
 * OrigineDesProduits).
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé : un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne crée plus rien (voir Magasin::verifierQuIlEstOuvert()),
 * et sa fermeture, qui verrouille cette ligne (voir FermerMagasinAction),
 * passe tout entière avant ou après la création. Puis son auteur, sous le
 * verrou de sa ligne (voir LecteurDesDroits) : il doit pouvoir créer cette
 * fiche (voir ClientPolicy, FournisseurPolicy et ServicePolicy), et, pour
 * fixer le prix de référence d'un nouveau service, fixer les prix (R2,
 * voir DerogationsDePrix). La fiche se crée à son nom (voir
 * OperateurCourant), dans le magasin actif (voir BelongsToMagasin). Un
 * SIRET de fournisseur ou un nom de service qu'une autre personne vient
 * d'enregistrer dans le magasin est refusé comme par la validation du
 * formulaire, jamais par une erreur de la base.
 */
class CreerUneFicheAction
{
    public const REFUS_SIRET_PRIS = 'Ce SIRET est déjà celui d\'un autre fournisseur du magasin, peut-être archivé : affichez les fournisseurs archivés pour le restaurer.';

    public const REFUS_SERVICE_EXISTANT = 'Ce service existe déjà : une autre personne vient de l\'enregistrer.';

    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @template TFiche of Client|Fournisseur|Service
     *
     * @param  class-string<TFiche>  $classe
     * @param  array<string, mixed>  $attributs  ceux du formulaire
     * @return TFiche
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, string $classe, array $attributs): Client|Fournisseur|Service
    {
        try {
            return DB::transaction(function () use ($auteur, $classe, $attributs): Client|Fournisseur|Service {
                $magasin = app(TenantContext::class)->magasin();
                Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
                $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, $classe);

                if ($classe === Service::class && filled($attributs['prix_ttc'] ?? null)) {
                    $this->lecteur->exiger($compte, PermissionRegistry::VENTE_REMISES_ET_PRIX, $magasin);
                }

                return $this->operateur->pendant($compte, fn (): Client|Fournisseur|Service => $classe::query()->create($attributs));
            });
        } catch (UniqueConstraintViolationException $doublon) {
            throw match ($classe) {
                Fournisseur::class => new LogicException(self::REFUS_SIRET_PRIS, previous: $doublon),
                Service::class => new LogicException(self::REFUS_SERVICE_EXISTANT, previous: $doublon),
                default => $doublon,
            };
        }
    }
}
