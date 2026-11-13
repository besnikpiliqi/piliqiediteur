<?php

use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Database\Seeders\Semis\SemisDeDemonstration;
use Illuminate\Support\Facades\Storage;

/*
 * Les données de démonstration d'un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026) : après php artisan db:seed, chaque
 * compte de la liste se connecte avec le mot de passe que la liste donne,
 * ici le mot de passe commun de PILIQI_DEMO_MOT_DE_PASSE ; le propriétaire
 * avec son adresse de connexion, les autres avec leur identifiant ; et
 * chacun arrive où son rôle le mène, avec les données semées. La vendeuse
 * qui garde son identifiant pour mot de passe ne se connecte qu'une fois
 * le navigateur reconnu comme un poste de son magasin, comme le README le
 * dit.
 */

const MOT_DE_PASSE_DE_DEMONSTRATION = 'Fraise-Ete-Demo';

/**
 * Sème les données de démonstration avec le mot de passe commun.
 */
function semerLaDemonstration(): void
{
    Storage::fake('local');
    Storage::fake(PiecesDuFournisseur::DISQUE);
    config(['piliqi.demo_mot_de_passe' => MOT_DE_PASSE_DE_DEMONSTRATION]);
    test()->artisan('db:seed')->assertSuccessful();
}

/**
 * Le seul compte actif semé sous ce nom.
 */
function compteSeme(string $nom): User
{
    return User::query()->where('name', $nom)->where('statut', 'actif')->sole();
}

test('les comptes de la liste du semis se connectent et retrouvent leurs données', function () {
    semerLaDemonstration();
    $proprietaire = User::query()->where('email', SemisDeDemonstration::ADRESSE_COMMERCE)->sole();
    $halles = Magasin::query()->where('code', 'HALLES')->sole();

    $onglet = $this->seConnecter($proprietaire, MOT_DE_PASSE_DE_DEMONSTRATION, saisie: SemisDeDemonstration::ADRESSE_COMMERCE)
        ->aller('/societe/'.$proprietaire->societe?->uuid)
        ->voir('Maison Ferrand Halles')
        ->voir('Maison Ferrand République')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Camille Laurent'), MOT_DE_PASSE_DE_DEMONSTRATION, onglet: $onglet);

    expect($onglet->chemin())->toBe('/app/'.$halles->uuid);

    $onglet->voir('1 en retard')
        ->aller('/app/'.$halles->uuid.'/factures')
        ->voir('Hôtel des Arts')
        ->voir('Agence Horizon')
        ->seDeconnecter();

    $this->seConnecter(compteSeme('Paul Lefèvre'), MOT_DE_PASSE_DE_DEMONSTRATION, onglet: $onglet)
        ->voir('MAISON FERRAND')
        ->nePasVoir('EPICERIE LEMOINE');
});

test('le vendeur qui garde son identifiant pour mot de passe ne se connecte que depuis un poste reconnu', function () {
    semerLaDemonstration();
    $ines = compteSeme('Inès Garcia');
    $identifiant = (string) $ines->identifiantDeConnexion();
    $halles = Magasin::query()->where('code', 'HALLES')->sole();
    $proprietaire = User::query()->where('email', SemisDeDemonstration::ADRESSE_COMMERCE)->sole();

    $onglet = $this->naviguer('/app/login')
        ->remplir('Identifiant ou adresse e-mail', $identifiant)
        ->remplir('Mot de passe', $identifiant)
        ->cliquerSur('Connexion')
        ->voir('poste reconnu');

    $this->reconnaitreCeNavigateur($onglet, $halles, $proprietaire, 'Poste de l\'éditeur', MOT_DE_PASSE_DE_DEMONSTRATION);
    $this->seConnecter($ines, $identifiant, onglet: $onglet);

    expect($onglet->chemin())->toBe('/app/'.$halles->uuid);
});
