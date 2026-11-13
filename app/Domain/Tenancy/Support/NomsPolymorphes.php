<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Le nom sous lequel un modèle s'écrit dans une colonne polymorphique
 * (document_type du registre fiscal et des envois, source_type du grand
 * livre du stock, transmissible_type, notifiable_type, cible_type du
 * journal d'audit, resultat_type des demandes déjà traitées) : un alias
 * stable plutôt que le nom de sa classe, qu'un déplacement de fichier
 * changerait, alors que le registre fiscal le signe (voir RegistreFiscal).
 * AppServiceProvider le donne à Relation::morphMap(), sans
 * enforceMorphMap() : un modèle absent de la liste garde le nom de sa
 * classe. Les gardes de la base et les requêtes qui l'écrivent dans leur
 * SQL le lisent par aliasDe(), un texte du code (literal-string), comme
 * DB::unprepared() l'exige.
 */
final class NomsPolymorphes
{
    public const REFUS_SANS_ALIAS = 'Ce modèle n\'a pas de nom polymorphe : %s.';

    public const MODELES = [
        'avoir' => Avoir::class,
        'avoir_fournisseur' => AvoirFournisseur::class,
        'cabinet' => Cabinet::class,
        'cloture_caisse' => ClotureCaisse::class,
        'cloture_caisse_personnelle' => ClotureCaissePersonnelle::class,
        'devis' => Devis::class,
        'envoi_document' => EnvoiDocument::class,
        'facture' => Facture::class,
        'facture_achat' => FactureAchat::class,
        'inventaire_ouverture' => InventaireOuverture::class,
        'invitation_cabinet' => InvitationCabinet::class,
        'ligne_avoir' => LigneAvoir::class,
        'lien_cabinet' => LienCabinet::class,
        'compte_expert_comptable' => CompteExpertComptable::class,
        'magasin' => Magasin::class,
        'paiement' => Paiement::class,
        'produit' => Produit::class,
        'recu_paiement' => RecuPaiement::class,
        'societe' => Societe::class,
        'utilisateur' => User::class,
    ];

    /**
     * L'alias de ce modèle, celui que lui donne getMorphClass().
     *
     * @param  class-string<Model>  $modele
     * @return literal-string
     *
     * @throws InvalidArgumentException un modèle sans alias
     */
    public static function aliasDe(string $modele): string
    {
        $alias = array_search($modele, self::MODELES, true);

        return $alias === false ? throw new InvalidArgumentException(sprintf(self::REFUS_SANS_ALIAS, $modele)) : $alias;
    }
}
