<?php

use App\Domain\Cabinets\Exceptions\LienDeLExpertComptableInvalideException;
use App\Domain\Tenancy\Enums\EtatDuMotDePasse;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\LimitationDesLiens;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Filament\App\Pages\Auth\Connexion;
use App\Models\User;
use Tests\Browser\Support\ContrasteDesPages;
use Tests\Browser\Support\Navigation;

/*
 * Le mot de passe provisoire d'un accès redonné en l'absence de la personne
 * (voir MotsDePasseProvisoires, décision de Claude D76, T36.1 à T36.4), et
 * la limite de « Mot de passe oublié » : le responsable le voit une seule
 * fois, dans la notification de « Redonner l'accès » ; la personne s'en
 * sert une seule fois, depuis un poste reconnu, qui la mène droit au choix
 * de son mot de passe ; ensuite il ne sert plus. « Mot de passe oublié »
 * répond de la même façon à chaque saisie jusqu'à sa limite, puis dit
 * d'attendre.
 */

/**
 * Ce compte essaie de se connecter avec ce mot de passe sur cet onglet et
 * reçoit le refus ordinaire, sans quitter la page de connexion.
 */
function seConnecterRefuse(User $compte, string $motDePasse, Navigation $onglet): void
{
    $onglet->aller('/app/login')
        ->remplir('Identifiant ou adresse e-mail', (string) $compte->identifiantDeConnexion())
        ->remplir('Mot de passe', $motDePasse)
        ->cliquerSur('Connexion')
        ->voir(Connexion::MESSAGE_ECHEC);

    expect($onglet->chemin())->toBe('/app/login');
}

/**
 * Le texte que la page de cet onglet montre.
 */
function texteDeLaPage(Navigation $onglet): string
{
    return (string) $onglet->page()->evaluate('() => document.body.innerText');
}

test('le responsable redonne l’accès d’un vendeur absent : le mot de passe provisoire ne s’affiche qu’une fois, ne sert qu’une fois depuis le poste reconnu et mène au choix du mot de passe', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $responsable = $this->responsable($magasin);
    $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Léo Martin']);
    $onglet = $this->reconnaitreCeNavigateur($this->naviguer('/app/login'), $magasin, $responsable);

    $this->seConnecter($responsable, onglet: $onglet)
        ->aller('/app/'.$magasin->uuid.'/equipe')
        ->cliquerSur('Redonner l\'accès')
        ->cliquer('label:has-text("Elle est absente") input[type=radio]')
        ->cliquerSur('Soumettre')
        ->voir('Accès redonné. Remettez en main propre l\'identifiant '.$vendeur->identifiantDeConnexion().' et le mot de passe provisoire');

    expect(preg_match('/mot de passe provisoire ([a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}) : il ne s.affiche qu.une fois/u', texteDeLaPage($onglet), $trouve))->toBe(1);
    $provisoire = $trouve[1];
    expect($vendeur->refresh()->etatDuMotDePasse())->toBe(EtatDuMotDePasse::ProvisoireValable);

    $onglet->aller('/app/'.$magasin->uuid.'/equipe')->voir('Léo Martin');
    expect(texteDeLaPage($onglet))->not->toContain($provisoire);

    $onglet->seDeconnecter();
    $this->seConnecter($vendeur, $provisoire, onglet: $onglet);

    expect($onglet->chemin())->toBe('/app/mot-de-passe');
    $onglet->voir('Choisissez votre mot de passe');
    $contraste = new ContrasteDesPages($onglet);
    expect([$contraste->defautsDeLaPage('clair'), $contraste->defautsDeLaPage('sombre')])->toBe([[], []]);
    $onglet->remplir('Votre nouveau mot de passe', 'cigale-2704-ocre')
        ->remplir('Confirmez-le', 'cigale-2704-ocre')
        ->cliquerSur('Choisir mon mot de passe')
        ->attendreQue('location.pathname !== "/app/mot-de-passe"', 'le choix du mot de passe n\'a mené nulle part');

    expect($onglet->chemin())->toStartWith('/app/'.$magasin->uuid)
        ->and($vendeur->refresh()->etatDuMotDePasse())->toBe(EtatDuMotDePasse::Choisi);

    $onglet->seDeconnecter()
        ->aller('/app/login')
        ->remplir('Identifiant ou adresse e-mail', (string) $vendeur->identifiantDeConnexion())
        ->remplir('Mot de passe', $provisoire)
        ->cliquerSur('Connexion')
        ->voir(Connexion::MESSAGE_ECHEC);
    expect($onglet->chemin())->toBe('/app/login');
});

test('un mot de passe provisoire expiré ne sert plus : le refus est le refus ordinaire, et « Redonner l’accès » en tire un nouveau', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $responsable = $this->responsable($magasin);
    [$vendeur, $provisoire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
    $onglet = $this->reconnaitreCeNavigateur($this->naviguer('/app/login'), $magasin, $responsable);

    $this->travel(MotsDePasseProvisoires::dureeEnHeures() + 1)->hours();

    seConnecterRefuse($vendeur, $provisoire, $onglet);
    expect($vendeur->refresh()->etatDuMotDePasse())->toBe(EtatDuMotDePasse::ProvisoireExpire);
});

test('« Mot de passe oublié » répond de la même façon à chaque demande jusqu’à sa limite, qu’il désigne un propriétaire ou non, puis dit d’attendre, sans rien dire du compte', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $proprietaire = $this->proprietaireDe($magasin);
    $onglet = $this->naviguer('/app/password-reset/request');
    $reponse = 'Si cette saisie est celle d\'un propriétaire, un lien vient d\'être envoyé à son adresse de connexion.';
    $attente = LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE - LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE * 61);

    foreach ([(string) $proprietaire->email, 'inconnu@exemple.test'] as $saisie) {
        foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE) as $rang) {
            $onglet->remplir('Adresse e-mail ou identifiant', $saisie)
                ->cliquerSur('Envoyer l\'email')
                ->voir($reponse)
                ->fermerLesAvis();
            $this->travel(61)->seconds();
        }

        $onglet->remplir('Adresse e-mail ou identifiant', $saisie)
            ->cliquerSur('Envoyer l\'email')
            ->voir($attente)
            ->fermerLesAvis();
        $this->travel(61)->seconds();

        expect(texteDeLaPage($onglet))->not->toContain($proprietaire->name);
    }
});

test('la page d’attente d’une adresse qui a essayé dix liens inconnus ne dit rien d’aucun lien et se lit en clair et en sombre', function () {
    $onglet = $this->naviguer('/app/login');

    foreach (range(1, LimitationDesLiens::JETONS_INVALIDES_PAR_HEURE) as $rang) {
        $this->tolerer(410, '/expert-comptable/');
        $onglet->aller('/expert-comptable/'.str_pad((string) $rang, 64, 'x'))->voir(LienDeLExpertComptableInvalideException::MESSAGE);
    }

    $this->tolerer(429, '/expert-comptable/');
    $onglet->aller('/expert-comptable/'.str_pad('suivant', 64, 'x'))->voir(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE));

    expect(texteDeLaPage($onglet))->not->toContain(LienDeLExpertComptableInvalideException::MESSAGE);
    $contraste = new ContrasteDesPages($onglet);
    expect([$contraste->defautsDeLaPageSelonLeSysteme('clair'), $contraste->defautsDeLaPageSelonLeSysteme('sombre')])->toBe([[], []]);
});
