<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Contacts\Support\DoublonDUneFiche;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enregistre ce qu'une personne a corrigé d'une fiche du magasin dans son
 * formulaire : un client, un fournisseur, un produit (sa page
 * « Produits ») ou un service. Le formulaire a lu la fiche à son ouverture
 * (voir FicheLue) : seules les corrections de son auteur s'écrivent ; ce
 * qu'une autre personne a changé depuis sur la fiche reste, et une
 * correction qui porte sur un attribut changé entre-temps est refusée.
 *
 * Dans une transaction, dans l'ordre des verrous de l'application :
 * - le registre fiscal du magasin, seulement quand l'auteur corrige le
 *   taux de TVA d'un produit, qui s'inscrit au journal des événements
 *   (voir JournaliseSesParametresDeConformite) : une autre correction ne
 *   fait pas attendre les ventes ;
 * - la ligne du magasin, sous un verrou partagé : un magasin suspendu,
 *   fermé ou cédé, ou dont la société est en lecture seule, ne modifie
 *   plus rien (voir Magasin::verifierQuIlEstOuvert()), et sa fermeture,
 *   qui verrouille cette ligne (voir FermerMagasinAction), passe tout
 *   entière avant ou après la modification ;
 * - l'auteur, sous le verrou de sa ligne (voir LecteurDesDroits), qui
 *   doit pouvoir modifier les fiches de ce genre (updateAny()) ;
 * - la fiche, relue sous le sien : une fiche qui n'est plus dans le
 *   magasin ou qui a été archivée entre-temps, même avant que la page ne
 *   s'envoie, est refusée, avec un message.
 * L'auteur relu doit pouvoir modifier la fiche relue (voir ClientPolicy,
 * FournisseurPolicy, ProduitPolicy et ServicePolicy) ; fixer le prix de
 * référence d'un service demande en plus d'être autorisé à fixer les prix
 * (R2, voir DerogationsDePrix) ; ce que le fournisseur dit d'un produit
 * reçu sur une facture récupérée automatiquement ne se corrige pas (voir
 * Produit::vientDUneFactureAchatApi()). La modification se fait au nom de
 * l'auteur relu (voir OperateurCourant). Le client comptoir garde son nom
 * et son type (voir Client::booted()). Un SIRET de fournisseur, un nom de
 * service ou une référence de produit qu'une autre personne vient
 * d'enregistrer dans le magasin, entre la validation du formulaire et
 * l'écriture, est refusé comme par cette validation, jamais par une
 * erreur de la base (voir DoublonDUneFiche et
 * Produit::refuserUneReferencePriseEntreTemps()).
 */
class ModifierUneFicheAction
{
    public const REFUS_PERIMEE = 'Cette fiche a été modifiée par une autre personne depuis que vous l\'avez ouverte : rechargez la page pour repartir de la fiche actuelle, puis refaites votre correction.';

    public const REFUS_ARCHIVEE = 'Cette fiche a été archivée entre-temps : restaurez-la depuis la liste des archivés avant de la modifier.';

    /**
     * Les attributs du prix de référence d'un service (voir Service).
     */
    private const PRIX_DE_REFERENCE = ['prix_ttc', 'taux_tva'];

    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly RegistreFiscal $registre,
    ) {}

    /**
     * @param  array<string, mixed>  $ficheLue  la fiche telle que le formulaire l'a lue (voir FicheLue::valeurs())
     * @param  array<string, mixed>  $saisie  les attributs que le formulaire envoie
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Client|Fournisseur|Produit|Service $fiche, array $ficheLue, array $saisie): Client|Fournisseur|Produit|Service
    {
        $lue = FicheLue::depuis($fiche::class, $ficheLue);
        $corrections = $lue->corrections($saisie);

        try {
            return DB::transaction(function () use ($auteur, $fiche, $lue, $corrections): Client|Fournisseur|Produit|Service {
                $magasin = app(TenantContext::class)->magasin();

                if ($fiche instanceof Produit && array_intersect_key($corrections, array_flip(Produit::parametresDeConformite())) !== []) {
                    $this->registre->verrouiller($magasin);
                }

                Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
                $compte = $this->lecteur->exigerSousVerrou($auteur, 'updateAny', $magasin, $fiche::class);

                $relue = $fiche->newQueryWithoutScope(SoftDeletingScope::class)->whereKey($fiche->getKey())->lockForUpdate()->first()
                    ?? throw new LogicException(ArchiverUneFicheAction::REFUS_INTROUVABLE);

                if (! $relue instanceof Service && $relue->trashed()) {
                    throw new LogicException(self::REFUS_ARCHIVEE);
                }

                $this->lecteur->exiger($compte, 'update', $magasin, $relue);
                $this->exigerLeDroitDeCorriger($compte, $magasin, $relue, $corrections);
                $lue->refuserUneCorrectionPerimee($relue, $corrections, self::REFUS_PERIMEE);

                $this->operateur->pendant($compte, fn (): bool => $relue->fill($corrections)->save());

                return $relue;
            });
        } catch (UniqueConstraintViolationException $doublon) {
            throw DoublonDUneFiche::refus($fiche::class, $doublon);
        }
    }

    /**
     * Ce que certaines corrections demandent en plus du droit de modifier
     * la fiche.
     *
     * @param  array<string, mixed>  $corrections
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function exigerLeDroitDeCorriger(User $compte, Magasin $magasin, Client|Fournisseur|Produit|Service $relue, array $corrections): void
    {
        if ($relue instanceof Service && array_intersect_key($corrections, array_flip(self::PRIX_DE_REFERENCE)) !== []) {
            $this->lecteur->exiger($compte, PermissionRegistry::VENTE_REMISES_ET_PRIX, $magasin);
        }

        if ($relue instanceof Produit && array_intersect_key($corrections, array_flip(Produit::ATTRIBUTS_DU_FOURNISSEUR)) !== [] && $relue->vientDUneFactureAchatApi()) {
            throw new LogicException("Le produit « {$relue->nom} » vient d'une facture d'achat récupérée automatiquement : ses informations fournisseur ne se modifient pas.");
        }
    }
}
