<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\CreerCollaborateurAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\Connexion;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Tests\Browser\Support\Navigation;

/*
 * Les témoins de poste qu'un navigateur envoie en tableau
 * (« postes_connus[0]=… », « postes_reconnus[0]=… »), avec une valeur
 * chiffrée par le site : PHP les lit en tableau à la place du témoin
 * ordinaire de ce nom, et le site les compte comme des témoins absents (au
 * lieu d'une erreur 500). Sur la page de connexion de chaque panneau, un
 * compte qui n'a pas encore choisi son mot de passe reçoit le refus
 * ordinaire hors d'un poste reconnu, et un compte qui a choisi le sien se
 * connecte, ce qui efface du navigateur le témoin des postes connus envoyé
 * en tableau ; dans les paramètres du magasin, le propriétaire et le
 * responsable ouvrent « Postes reconnus » et reconnaissent ce navigateur,
 * ce qui efface le témoin des postes reconnus envoyé en tableau : la liste
 * dit ensuite « Ce navigateur », et un vendeur qui n'a pas encore choisi son
 * mot de passe s'y connecte avec son mot de passe provisoire.
 */

/**
 * Pose dans le navigateur de cet onglet, pour chacun de ces noms, le
 * témoin de ce nom en tableau (« postes_connus[0] »), sa valeur chiffrée par
 * le site pour ce nom, comme EncryptCookies chiffre un témoin.
 */
function poserLesTemoinsEnTableau(Navigation $onglet, string ...$noms): void
{
    $chiffreur = app('encrypter');

    foreach ($noms as $nom) {
        $valeur = $chiffreur->encrypt(CookieValuePrefix::create($nom.'[0]', $chiffreur->getKey()).'poste-d-essai', false);
        $onglet->page()->evaluate('() => { document.cookie = '.Navigation::texte($nom.'[0]='.rawurlencode($valeur).'; path=/').'; }');
    }
}

/**
 * Les noms des témoins de cet onglet que lit le JavaScript de la page (ceux
 * que pose le site ne s'y lisent pas, réservés aux requêtes).
 *
 * @return list<string>
 */
function temoinsLisiblesParLaPage(Navigation $onglet): array
{
    return array_values(array_filter(array_map(
        fn (string $temoin): string => trim(explode('=', $temoin, 2)[0]),
        explode(';', (string) $onglet->page()->evaluate('() => document.cookie')),
    )));
}

/**
 * Ce compte essaie, dans cet onglet, de se connecter sur la page de
 * connexion de ce panneau avec ce mot de passe provisoire (ou, pour un
 * collaborateur, qui n'en a pas, un mot de passe quelconque), hors d'un
 * poste reconnu : il reçoit le refus ordinaire et reste sur la page.
 */
function etreRefuseAvecUnMotDePasseProvisoire(Navigation $onglet, User $compte, string $motDePasse, string $panneau): void
{
    $onglet->aller('/'.$panneau.'/login')
        ->remplir('Identifiant ou adresse e-mail', (string) $compte->identifiantDeConnexion())
        ->remplir('Mot de passe', $motDePasse)
        ->cliquerSur('Connexion')
        ->voir(Connexion::MESSAGE_ECHEC);

    expect($onglet->chemin())->toBe('/'.$panneau.'/login');
}

/**
 * Les badges de la ligne de ce poste, dans la liste des postes reconnus
 * affichée par cet onglet.
 *
 * @return list<string>
 */
function badgesDuPoste(Navigation $onglet, string $poste): array
{
    return $onglet->page()->evaluate('() => Array.from(document.querySelectorAll(".fi-ta-row")).filter((ligne) => ligne.textContent.includes('.Navigation::texte($poste).')).flatMap((ligne) => Array.from(ligne.querySelectorAll(".fi-badge")).map((badge) => badge.textContent.trim()))');
}

test('dans le magasin, avec des témoins de poste envoyés en tableau, le vendeur au mot de passe provisoire reçoit le refus ordinaire, et le responsable puis le propriétaire, par son adresse et par son identifiant, se connectent', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $proprietaire = $this->choisirSonMotDePasse($this->proprietaireDe($magasin));
    [$vendeur, $provisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
    $responsable = $this->membre($magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);
    $onglet = $this->naviguer('/app/login');

    poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
    etreRefuseAvecUnMotDePasseProvisoire($onglet, $vendeur, $provisoire, 'app');

    foreach ([[$responsable, null], [$proprietaire, (string) $proprietaire->email], [$proprietaire, null]] as [$compte, $saisie]) {
        poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
        $this->seConnecter($compte, saisie: $saisie, onglet: $onglet);

        expect($onglet->chemin())->toStartWith('/app/'.$magasin->uuid)
            ->and(temoinsLisiblesParLaPage($onglet))->not->toContain('postes_connus[0]')->toContain('postes_reconnus[0]');
        $onglet->seDeconnecter();
    }
});

test('dans l’espace de la société, avec des témoins de poste envoyés en tableau, l’administrateur au mot de passe provisoire reçoit le refus ordinaire, et l’administrateur qui a choisi le sien et le propriétaire se connectent', function () {
    $magasin = $this->ouvrirUneSociete();
    $societe = $magasin->societe()->firstOrFail();
    $proprietaire = $this->choisirSonMotDePasse($this->proprietaireDe($magasin));
    $nouveau = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alice Nouvelle'], null, PermissionRegistry::modeleDeLAdministrateur($societe->type_activite), []));
    $administrateur = $this->administrateur($magasin);
    app(TenantContext::class)->clear();
    $onglet = $this->naviguer('/societe/login');

    poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
    etreRefuseAvecUnMotDePasseProvisoire($onglet, $nouveau, $nouveau->motDePasseProvisoire?->valeur() ?? '', 'societe');

    foreach ([$administrateur, $proprietaire] as $compte) {
        poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
        $this->seConnecter($compte, onglet: $onglet, panneau: 'societe');

        expect($onglet->chemin())->toStartWith('/societe/'.$societe->uuid)
            ->and(temoinsLisiblesParLaPage($onglet))->not->toContain('postes_connus[0]')->toContain('postes_reconnus[0]');
        $onglet->seDeconnecter();
    }
});

test('dans l’espace du cabinet, avec des témoins de poste envoyés en tableau, le collaborateur sans mot de passe reçoit le refus ordinaire, et le collaborateur qui a choisi le sien et le propriétaire du cabinet se connectent', function () {
    $magasin = $this->ouvrirUneSociete();
    $collaborateur = $this->collaborateurDuCabinetLie($magasin, nom: 'Paul Lefèvre');
    $cabinet = $collaborateur->societe()->firstOrFail();
    $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
    app(TenantContext::class)->clear();
    $nouveau = app(CreerCollaborateurAction::class)->handle($proprietaireDuCabinet, $cabinet, ['name' => 'Camille Nouvelle']);
    app(TenantContext::class)->clear();
    $onglet = $this->naviguer('/cabinet/login');

    poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
    etreRefuseAvecUnMotDePasseProvisoire($onglet, $nouveau, (string) $nouveau->identifiantDeConnexion(), 'cabinet');

    foreach ([$collaborateur, $proprietaireDuCabinet] as $compte) {
        poserLesTemoinsEnTableau($onglet, PostesConnus::TEMOIN, PostesReconnus::TEMOIN);
        $this->seConnecter($compte, onglet: $onglet, panneau: 'cabinet');

        expect($onglet->chemin())->toStartWith('/cabinet/'.$cabinet->uuid)
            ->and(temoinsLisiblesParLaPage($onglet))->not->toContain('postes_connus[0]')->toContain('postes_reconnus[0]');
        $onglet->fermerLesAvis()->seDeconnecter();
    }
});

test('avec un témoin des postes reconnus envoyé en tableau, le propriétaire et le responsable ouvrent « Postes reconnus » et reconnaissent ce navigateur, que la liste dit ensuite « Ce navigateur » et où le vendeur se connecte avec son mot de passe provisoire', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $postes = '/app/'.$magasin->uuid.'/postes-reconnus';

    foreach (['Caisse du propriétaire' => $this->choisirSonMotDePasse($this->proprietaireDe($magasin)), 'Caisse du responsable' => $this->responsable($magasin)] as $poste => $compte) {
        $onglet = $this->naviguer('/app/login');
        poserLesTemoinsEnTableau($onglet, PostesReconnus::TEMOIN);

        $this->seConnecter($compte, onglet: $onglet)
            ->aller($postes)
            ->cliquerSur('Reconnaître ce navigateur')
            ->remplir('Nom du poste', $poste)
            ->cliquerSur('Soumettre')
            ->voir('Ce navigateur est reconnu comme un poste du magasin.')
            ->aller($postes);

        expect(badgesDuPoste($onglet, $poste))->toBe(['Ce navigateur'])
            ->and(temoinsLisiblesParLaPage($onglet))->not->toContain('postes_reconnus[0]');

        [$vendeur, $provisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $this->seConnecter($vendeur, $provisoire, onglet: $onglet->seDeconnecter());
        expect($onglet->chemin())->toBe('/app/mot-de-passe');
    }
});
