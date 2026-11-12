<?php

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Mail\LienDuMotDePasseDeLExpertComptableMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Filament\App\Pages\AucunMagasin;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Browser\Support\Navigation;

/*
 * L'expert-comptable de la société, déclaré par le SIREN de son cabinet
 * (décision de l'éditeur du 01/11/2026) : le propriétaire le déclare dans
 * « Cabinet comptable », la personne choisit son mot de passe par le lien
 * reçu, entre dans les magasins réels de la société et y lit « La
 * société » ; le propriétaire ajoute une personne, puis retire son
 * expert-comptable ; il choisit les magasins qu'il voit ; depuis la fiche
 * d'une personne, il corrige son adresse, la retire et la réintègre ; une
 * personne d'une société sans magasin réel arrive sur une page qui le lui
 * dit, et s'en déconnecte.
 */

test('le propriétaire déclare son expert-comptable, qui choisit son mot de passe par le lien et lit la société', function () {
    $this->travelTo(self::aParis('2026-03-09 10:00:00'));
    $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
    app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
    Mail::fake();

    $onglet = $this->seConnecter($this->proprietaireDe($magasin), 'password', panneau: 'societe')
        ->aller('/societe/'.$magasin->societe->uuid.'/cabinet-comptable')
        ->voir('Déclarer votre expert-comptable')
        ->cliquerSur('Déclarer votre expert-comptable')
        ->remplir('SIREN de son cabinet', FauxRepertoireSirene::SIREN_CABINET)
        ->remplir('Nom de la personne', 'Marc Petit')
        ->remplir('Son adresse e-mail professionnelle', 'marc.petit@petit-associes.test')
        ->remplir('Confirmez cette adresse', 'marc.petit@petit-associes.test')
        ->remplir('Votre mot de passe', 'password')
        ->soumettre('Déclarer')
        ->voir('Marc Petit est déclaré')
        ->voir('Votre expert-comptable (sans espace')
        ->voir('CABINET PETIT ET ASSOCIES');

    $marc = User::query()->where('role_societe', 'expert_comptable')->sole();
    $onglet->voir((string) $marc->identifiantDeConnexion());

    $lien = (string) Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)->last()?->lien;
    $onglet->fermerLesAvis()
        ->seDeconnecter()
        ->aller('/expert-comptable/'.Str::afterLast($lien, '/'))
        ->voir('Bienvenue Marc Petit.')
        ->remplir('Votre mot de passe', 'Marc-Petit-2026!')
        ->remplir('Confirmez votre mot de passe', 'Marc-Petit-2026!')
        ->soumettre('Choisir mon mot de passe')
        ->voir((string) $marc->identifiantDeConnexion())
        ->cliquerSurLeLien('La société')
        ->voir('Identité et réglages fiscaux')
        ->voir((string) $magasin->societe->siren);
});

test('le propriétaire ajoute une personne à son expert-comptable déclaré, puis le retire', function () {
    $this->travelTo(self::aParis('2026-03-09 10:00:00'));
    $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $this->declarerSonExpertComptable($magasin);

    $this->seConnecter($this->proprietaireDe($magasin), 'password', panneau: 'societe')
        ->aller('/societe/'.$magasin->societe->uuid.'/cabinet-comptable')
        ->voir('Marc Petit')
        ->cliquerSur('Ajouter une personne de ce cabinet')
        ->remplir('Nom de la personne', 'Sophie Lenoir')
        ->remplir('Son adresse e-mail professionnelle', 'sophie.lenoir@petit-associes.test')
        ->remplir('Confirmez cette adresse', 'sophie.lenoir@petit-associes.test')
        ->remplir('Votre mot de passe', 'password')
        ->soumettre('Ajouter')
        ->voir('Sophie Lenoir est ajouté')
        ->cliquerSur('Retirer votre expert-comptable')
        ->voir('Les comptes de Marc Petit, Sophie Lenoir sont retirés aussitôt')
        ->soumettre('Confirmer')
        ->voir('Votre expert-comptable n\'est plus déclaré : ses comptes sont retirés.')
        ->nePasVoir('Votre expert-comptable (sans espace');
});

test('le propriétaire déclare son expert-comptable pour les seuls magasins cochés', function () {
    $this->travelTo(self::aParis('2026-03-09 10:00:00'));
    $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
    app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
    Mail::fake();

    $this->seConnecter($this->proprietaireDe($lyon1), 'password', panneau: 'societe')
        ->aller('/societe/'.$lyon1->societe->uuid.'/cabinet-comptable')
        ->cliquerSur('Déclarer votre expert-comptable')
        ->remplir('SIREN de son cabinet', FauxRepertoireSirene::SIREN_CABINET)
        ->remplir('Nom de la personne', 'Marc Petit')
        ->remplir('Son adresse e-mail professionnelle', 'marc.petit@petit-associes.test')
        ->remplir('Confirmez cette adresse', 'marc.petit@petit-associes.test')
        ->cliquer(Navigation::FENETRE.' >> label:has-text("Seulement les magasins cochés ci-dessous")')
        ->cliquer(Navigation::FENETRE.' >> label:has-text("Lyon 2")')
        ->remplir('Votre mot de passe', 'password')
        ->soumettre('Déclarer')
        ->voir('Marc Petit est déclaré');

    $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
    $marc = User::query()->where('role_societe', 'expert_comptable')->sole();
    expect($declaration->tous_les_magasins)->toBeFalse()
        ->and(DB::table('magasins_cabinet')->where('lien_id', $declaration->id)->pluck('magasin_id')->all())->toBe([$lyon2->id])
        ->and($marc->peutOuvrir($lyon1))->toBeFalse()
        ->and($marc->peutOuvrir($lyon2))->toBeTrue();
});

test('le propriétaire ouvre la fiche d’une personne de son expert-comptable, corrige son adresse, la retire puis la réintègre', function () {
    $this->travelTo(self::aParis('2026-03-09 10:00:00'));
    $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $this->declarerSonExpertComptable($magasin);
    $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');

    $this->seConnecter($this->proprietaireDe($magasin), 'password', panneau: 'societe')
        ->aller('/societe/'.$magasin->societe->uuid.'/cabinet-comptable')
        ->cliquer('tr:has-text("Sophie Lenoir") >> internal:role=link[name="Fiche"s]')
        ->voir('Sophie Lenoir')
        ->cliquerSur('Corriger l\'adresse')
        ->remplir('Nouvelle adresse e-mail professionnelle', 'sophie@petit-associes.test')
        ->remplir('Confirmez cette adresse', 'sophie@petit-associes.test')
        ->remplir('Votre mot de passe', 'password')
        ->soumettre('Soumettre')
        ->voir('Adresse corrigée : un nouveau lien est parti à la nouvelle adresse.')
        ->fermerLesAvis()
        ->cliquerSur('Retirer de la société')
        ->soumettre('Confirmer')
        ->cliquerSur('Réintégrer')
        ->voir('La personne retrouve son compte, avec son mot de passe et les autorisations de votre déclaration.')
        ->soumettre('Confirmer');

    expect($sophie->fresh()->email)->toBe('sophie@petit-associes.test')
        ->and($sophie->fresh()->statut)->toBe(StatutCompte::Actif);
});

test('la personne de l’expert-comptable d’une société sans magasin réel arrive sur la page qui le lui dit et s’en déconnecte', function () {
    $societe = $this->inscrireUneSociete();
    $this->saisirLeCodeDuCourrier($societe)->forceFill(['password' => 'password'])->save();
    $marc = $this->expertComptable($societe->fresh());

    $this->seConnecter($marc)
        ->voir(AucunMagasin::MESSAGE_EXPERT_COMPTABLE)
        ->cliquerSur('Me déconnecter')
        ->attendreQue('location.pathname === "/app/login"', 'la déconnexion n\'a pas mené à la page de connexion');
});

test('le propriétaire suit l’étape « Ajouter un magasin » et le lien de son expert-comptable déclaré depuis son tableau de bord', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
    $this->declarerSonExpertComptable($magasin);
    $texteDuCabinet = 'Cabinet comptable : votre expert-comptable, cabinet CABINET PETIT ET ASSOCIES, déclaré, en attente de son espace (1 personne)';

    $this->seConnecter($this->proprietaireDe($magasin), 'password', panneau: 'societe')
        ->aller('/societe/'.$magasin->societe->uuid)
        ->voir('1 magasin')
        ->cliquerSurLeLien(TableauDeBord::ETAPE_AJOUTER_UN_MAGASIN)
        ->attendreQue('location.pathname.endsWith("/magasins/creer")', 'l’étape n’a pas mené à la création d’un magasin')
        ->aller('/societe/'.$magasin->societe->uuid)
        ->cliquerSurLeLien($texteDuCabinet)
        ->voir('Votre expert-comptable (sans espace')
        ->voir('Marc Petit');
});
