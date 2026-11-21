<?php

use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Database\Seeders\Semis\SemisComplet;
use Illuminate\Support\Facades\Storage;
use Tests\SemeSansTransaction;

/*
 * Le semis d'un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026, et semis complet du 15/11/2026,
 * cahier SEED-TOUT § 5) : après php artisan db:seed, chaque compte de la
 * liste se connecte avec le mot de passe que la liste donne, ici le mot de
 * passe commun de PILIQI_SEMIS_MOT_DE_PASSE ; le propriétaire avec son
 * adresse de connexion, les autres avec leur identifiant ; et chacun
 * retrouve ses propres données sur ses pages principales : le tableau de
 * bord et ses graphiques, les factures et les tickets, les charges, les
 * caisses. La vendeuse qui garde son identifiant pour mot de passe ne se
 * connecte qu'une fois le navigateur reconnu comme un poste de son
 * magasin, comme le README le dit. Le semis prend plusieurs minutes : un
 * seul semis, dans le groupe « semis », hors de la suite par défaut. Les
 * relevés de caisse se lisent à l'avant-veille de l'instant du test, le
 * dernier jour de ventes de l'histoire semée (la veille, les magasins de
 * Maison Ferrand ne vendent pas) ; les listes de documents, longues de
 * plusieurs pages, se lisent par la recherche du tableau.
 */

uses(SemeSansTransaction::class);

const MOT_DE_PASSE_DU_SEMIS = 'Fraise-Ete-Semis';

/**
 * Le seul compte actif semé sous ce nom.
 */
function compteSeme(string $nom): User
{
    return User::query()->where('name', $nom)->where('statut', 'actif')->orderBy('id')->firstOrFail();
}

test('chaque rôle de la liste du semis se connecte et retrouve ses propres données', function () {
    Storage::fake('local');
    Storage::fake(PiecesDuFournisseur::DISQUE);
    config(['piliqi.semis_mot_de_passe' => MOT_DE_PASSE_DU_SEMIS]);
    $this->artisan('db:seed')->assertSuccessful();

    $proprietaire = User::query()->where('email', SemisComplet::ADRESSE_COMMERCE)->sole();
    $societe = (string) $proprietaire->societe?->uuid;
    $halles = Magasin::query()->where('code', 'HALLES')->sole();
    $conseil = Magasin::query()->where('code', 'PARIS')->sole();
    $cabinet = Societe::query()->where('siren', '000000943')->sole();

    $onglet = $this->seConnecter($proprietaire, MOT_DE_PASSE_DU_SEMIS, saisie: SemisComplet::ADRESSE_COMMERCE)
        ->aller('/societe/'.$societe)
        ->voir('Maison Ferrand Halles')
        ->voir('Maison Ferrand République')
        ->voir('Chiffre d\'affaires')
        ->aller('/societe/'.$societe.'/charges')
        ->voir('EDF')
        ->aller('/societe/'.$societe.'/caisses')
        ->remplir('Relevés du', Calendrier::ajouterJours(Calendrier::deParis()->jourDe(now()), -2))
        ->voir('Maison Ferrand Halles')
        ->nePasVoir('Aucun relevé de caisse fermé')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Nadia Benali'), MOT_DE_PASSE_DU_SEMIS, onglet: $onglet)
        ->aller('/societe/'.$societe.'/charges')
        ->voir('EDF')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Camille Laurent'), MOT_DE_PASSE_DU_SEMIS, onglet: $onglet);
    expect($onglet->chemin())->toBe('/app/'.$halles->uuid);
    $onglet->voir('en retard')
        ->aller('/app/'.$halles->uuid.'/factures?tableSearch=Galerie')
        ->voir('Galerie Saint-Martin')
        ->aller('/app/'.$halles->uuid.'/charges-du-magasin')
        ->voir('EDF')
        ->aller('/app/'.$halles->uuid.'/devis?tableSearch=Horizon')
        ->voir('Agence Horizon')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Lucas Moreau'), MOT_DE_PASSE_DU_SEMIS, onglet: $onglet)
        ->aller('/app/'.$halles->uuid.'/factures')
        ->voir('Client comptoir')
        ->nePasVoir('Muster GmbH')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Paul Lefèvre'), MOT_DE_PASSE_DU_SEMIS, onglet: $onglet, panneau: 'cabinet')
        ->aller('/cabinet/'.$cabinet->uuid)
        ->voir('MAISON FERRAND')
        ->nePasVoir('EPICERIE LEMOINE')
        ->seDeconnecter();

    $onglet = $this->seConnecter(compteSeme('Marc Petit'), MOT_DE_PASSE_DU_SEMIS, onglet: $onglet)
        ->aller('/app/'.$conseil->uuid.'/charges-de-la-societe')
        ->voir('Bouygues')
        ->aller('/app/'.$conseil->uuid.'/factures?tableSearch=Boulangerie')
        ->voir('Boulangerie Martin')
        ->seDeconnecter();

    $ines = compteSeme('Inès Garcia');
    $identifiant = (string) $ines->identifiantDeConnexion();
    $onglet->aller('/app/login')
        ->remplir('Identifiant ou adresse e-mail', $identifiant)
        ->remplir('Mot de passe', $identifiant)
        ->cliquerSur('Connexion')
        ->voir('poste reconnu');

    $this->reconnaitreCeNavigateur($onglet, $halles, $proprietaire, 'Poste de l\'éditeur', MOT_DE_PASSE_DU_SEMIS);
    $this->seConnecter($ines, $identifiant, onglet: $onglet);

    expect($onglet->chemin())->toBe('/app/'.$halles->uuid);
})->group('semis');
