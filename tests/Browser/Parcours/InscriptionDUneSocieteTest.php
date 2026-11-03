<?php

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use Database\Factories\SocieteFactory;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/*
 * L'inscription d'une société de bout en bout, comme son propriétaire la
 * fait dans le navigateur : la page d'inscription, le lien lu dans
 * l'e-mail tel qu'il part (le transport « array » des tests garde chaque
 * message envoyé), la page du lien, le code du courrier recommandé (le prestataire
 * factice l'imprime dans le PDF du courrier), la première connexion par
 * son adresse, puis la création de son premier magasin dans l'espace de la
 * société.
 */

test('une société s’inscrit, confirme son lien, saisit le code de son courrier et crée son premier magasin', function () {
    $siren = SocieteFactory::sirenFictif();
    $adresse = 'jeanne.dupont@example.test';

    $onglet = $this->naviguer('/app/register')
        ->remplir('SIREN de votre entreprise', $siren)
        ->choisirDansLaListe('Votre activité', 'Commerce ou atelier')
        ->remplir('Votre adresse e-mail', $adresse)
        ->remplir('Votre mot de passe', self::MOT_DE_PASSE_CHOISI)
        ->remplir('Confirmer le mot de passe', self::MOT_DE_PASSE_CHOISI)
        ->cliquerSur('S\'inscrire')
        ->voir(InscriptionSociete::MESSAGE_NEUTRE);
    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->sole(fn (SentMessage $message): bool => $message->getOriginalMessage()->getTo()[0]->getAddress() === $adresse);
    preg_match('#href="(https?://[^"]+/inscription/[^"]+)"#', (string) $email->getOriginalMessage()->getHtmlBody(), $lien);

    expect($lien)->toHaveKey(1);

    $adresseDuLien = html_entity_decode($lien[1]);

    $onglet->aller(substr($adresseDuLien, (int) strpos($adresseDuLien, '/inscription/')))
        ->geste(fn () => $onglet->element('input[name="choix_definitif"]')->check())
        ->cliquerSur('Confirmer l\'inscription');
    $societe = Societe::query()->where('siren', $siren)->sole();

    expect($onglet->chemin())->toBe('/societe/'.$societe->uuid)
        ->and($societe->validee_le)->toBeNull();

    $onglet->aller('/code/'.$siren)
        ->remplir('Code du courrier (XXXX-XXXX-XXXX)', $this->codeDuCourrier($societe))
        ->remplir('Adresse e-mail', $adresse)
        ->remplir('Mot de passe', self::MOT_DE_PASSE_CHOISI)
        ->cliquerSur('Valider');

    expect($onglet->chemin())->toBe('/societe/'.$societe->uuid)
        ->and($societe->refresh()->validee_le)->not->toBeNull();

    $onglet->seDeconnecter();
    $this->seConnecter($societe->proprietaire()->sole(), saisie: $adresse, onglet: $onglet);

    expect($onglet->chemin())->toBe('/societe/'.$societe->uuid);

    $onglet->aller('/societe/'.$societe->uuid.'/magasins/creer')
        ->cliquer('label:has-text("(siège)") input[type=radio]')
        ->remplir('Nom du magasin', 'Boutique du Port')
        ->cliquerSur('Créer')
        ->voir('Le magasin Boutique du Port est créé.');

    expect(Magasin::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->pluck('nom')->all())->toBe(['Boutique du Port']);
});
