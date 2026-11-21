<?php

namespace Database\Seeders\Semis;

use App\Domain\Demonstration\Models\CompteDuBandeau;
use App\Domain\Demonstration\Visites\RoleAuBandeau;
use App\Domain\Tenancy\Services\TenantContext;
use LogicException;

/**
 * Les comptes du bandeau « Vous êtes : » d'une base modèle du site de
 * démonstration (lot DEMO-COPIE, F.3, choix 16), écrits à la fin de son
 * semis : seulement des comptes de la société mise en avant du modèle,
 * jamais un compte retiré, ni un expert-comptable qui n'a pas encore
 * choisi son mot de passe, ni, dans la copie « Cabinet », un compte d'un
 * client. Chacun avec le libellé de son rôle et son groupe, dans l'ordre
 * du menu.
 */
final class ComptesDuBandeauDuModele
{
    /**
     * Le groupe des comptes de la société, hors des magasins.
     */
    public const LA_SOCIETE = 'La société';

    public const LE_COMPTABLE = 'Le comptable';

    public const LE_CABINET = 'Le cabinet';

    /**
     * La société mise en avant de chaque modèle, et ses comptes par groupe
     * (« La société », la clé d'un magasin, « Le comptable », « Le
     * cabinet »), chacun avec le libellé de son rôle, dans l'ordre du
     * menu.
     *
     * @var array<string, array{0: string, 1: array<string, array<string, string>>}>
     */
    public const COMPTES = [
        'commerce' => ['commerce', [
            self::LA_SOCIETE => [
                'propriétaire commerce' => 'Propriétaire',
                'Nadia Benali' => 'Administratrice sans vente',
                'Thomas Girard' => 'Administrateur qui vend aux Halles et à la République',
            ],
            'halles' => [
                'Camille Laurent' => 'Responsable',
                'Lucas Moreau' => 'Vendeur',
                'Inès Garcia' => 'Vendeuse (son mot de passe est encore son identifiant)',
                'Yanis Morel' => 'Vendeur (compte suspendu)',
                'Élodie Fabre' => 'Observatrice',
            ],
            'republique' => [
                'Hugo Lefebvre' => 'Responsable',
                'Lucas Moreau (République)' => 'Responsable (vendeur promu)',
                'Sarah Petit' => 'Vendeuse qui voit les factures de toute l\'équipe',
            ],
            'martinique' => [
                'Joëlle Marie-Sainte' => 'Responsable',
                'Kévin Louisy' => 'Vendeur',
            ],
            self::LE_COMPTABLE => [
                'Paul Lefèvre' => 'Expert-comptable déclaré',
            ],
        ]],
        'services' => ['services', [
            self::LA_SOCIETE => [
                'propriétaire services' => 'Propriétaire',
                'Léa Fontaine' => 'Administratrice sans vente',
                'Antoine Roux' => 'Administrateur qui vend',
            ],
            'conseil' => [
                'Claire Dubois' => 'Responsable',
                'Maxime Henry' => 'Vendeur',
                'Julie Mercier' => 'Vendeuse',
                'Anaïs Lambert' => 'Vendeuse (retirée puis réintégrée)',
                'Victor Blanchard' => 'Observateur',
            ],
            self::LE_COMPTABLE => [
                'Marc Petit' => 'Expert-comptable déclaré',
            ],
        ]],
        'cabinet' => ['cabinet', [
            self::LE_CABINET => [
                'propriétaire cabinet' => 'Propriétaire du cabinet',
                'Paul Lefèvre' => 'Collaborateur (Maison Ferrand confiée)',
                'Emma Rousseau' => 'Collaboratrice (Épicerie Lemoine confiée)',
            ],
        ]],
    ];

    public function __construct(private readonly SemisComplet $semis) {}

    /**
     * @throws LogicException hors du semis d'une base modèle, ou pour un compte qui n'est pas de la société mise en avant
     */
    public function ecrire(): void
    {
        $modele = $this->semis->modele ?? throw new LogicException('Le bandeau ne s\'écrit que dans une base modèle du site de démonstration.');
        [$cle, $groupes] = self::COMPTES[$modele->value];
        $societe = $this->semis->societe($cle);
        $ordre = 0;

        app(TenantContext::class)->runAsSociete($societe, function () use ($groupes, $societe, $modele, &$ordre): void {
            foreach ($groupes as $groupe => $comptes) {
                $nomDuGroupe = in_array($groupe, [self::LA_SOCIETE, self::LE_COMPTABLE, self::LE_CABINET], true) ? $groupe : (string) $this->semis->magasin($groupe)->nom;

                foreach ($comptes as $nom => $libelle) {
                    $compte = $this->semis->compte($nom);

                    if ($compte->societe_id !== $societe->getKey() || $compte->refresh()->estRetire()) {
                        throw new LogicException("Le compte {$nom} n'est pas un compte non retiré de la société mise en avant du modèle « {$modele->getLabel()} ».");
                    }

                    CompteDuBandeau::query()->create([
                        'user_id' => $compte->getKey(),
                        'libelle_du_role' => $libelle,
                        'role' => RoleAuBandeau::de($compte->load('affectations.magasin'), $societe->estUnCabinet())->cle,
                        'groupe' => $nomDuGroupe,
                        'ordre' => ++$ordre,
                    ]);
                }
            }
        });
        app(TenantContext::class)->clear();
    }
}
