<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\SocieteFactory;
use Illuminate\Support\Facades\Bus;
use Tests\Browser\Support\Navigation;

/*
 * La page de rattachement d'une société, pour son propriétaire : la
 * demande du cabinet de son expert-comptable déclaré s'accepte avec les
 * autorisations et les magasins de la déclaration, que « Accepter en
 * ajustant » reprend tels quels (choix C31 du cahier du lot EC2) ; celle
 * d'un autre cabinet, avec les autorisations par défaut et tous les
 * magasins, ou en choisissant ses magasins et ses cases.
 */

/*
 * Une société de deux magasins, « Lyon 1 » et « Lyon 2 », qui a déclaré son
 * expert-comptable sans « Clients : voir » ni « Devis : voir », pour le
 * seul magasin « Lyon 2 » ; le cabinet de ce SIREN, vérifié, sa tâche de
 * rattachement retenue, invite son SIREN : sa demande de rattachement
 * attend. Rend les deux magasins, la demande, les autorisations de la
 * déclaration et le compte de l'expert-comptable.
 */
$demandeDuCabinetDeclare = function (): array {
    $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $lyon2 = $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
    $cases = array_values(array_diff(PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::DEVIS_VIEW]));
    $marc = $this->expertComptable($lyon1, autorisations: $cases);
    $declaration = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::Declare->value)->sole();
    app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($lyon1), $declaration, $cases, [$lyon2->id]);
    app(TenantContext::class)->clear();
    $attendues = app(TenantContext::class)->runAsSociete($lyon1->societe, fn (): array => $declaration->fresh()->autorisations());
    Bus::fake([RattacherLesDeclarationsDuSiren::class]);
    $cabinet = $this->ouvrirUnCabinet();
    app(InviterUneEntrepriseAction::class)->handle($this->proprietaireDuCabinet($cabinet), $cabinet, (string) $lyon1->societe->siren, 'contact@boulangerie.test', static::MOT_DE_PASSE_CHOISI);
    app(TenantContext::class)->clear();

    return [$lyon1, $lyon2, LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::DemandeParLeCabinet->value)->sole(), $attendues, $marc];
};

test('le propriétaire accepte en ajustant, sans rien changer, la demande du cabinet qu’il a déclaré, qui garde les autorisations et les magasins de la déclaration', function () use ($demandeDuCabinetDeclare) {
    [$lyon1, $lyon2, $demande, $attendues, $marc] = $demandeDuCabinetDeclare->call($this);

    $this->seConnecter($this->proprietaireDe($lyon1), 'password', panneau: 'societe')
        ->aller('/societe/'.$lyon1->societe->uuid.'/rattachement-cabinet/'.$demande->uuid)
        ->voir('Ce cabinet est votre expert-comptable déclaré')
        ->cliquerSur('Accepter en ajustant les autorisations')
        ->voir('Seulement les magasins cochés ci-dessous')
        ->soumettre('Soumettre')
        ->voir('Ce cabinet est désormais votre cabinet.');

    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon1->societe_id)->where('etat', EtatDuLien::Actif->value)->sole();
    expect($lien->tous_les_magasins)->toBeFalse()
        ->and($lien->magasinsChoisis())->toBe([$lyon2->id])
        ->and(app(TenantContext::class)->runAsSociete($lyon1->societe, fn (): array => $lien->autorisations()))->toBe($attendues)
        ->and($attendues)->not->toContain(PermissionRegistry::CLIENT_VIEW, PermissionRegistry::DEVIS_VIEW)
        ->and($marc->fresh()->statut)->toBe(StatutCompte::Retire);
});

test('le propriétaire accepte la demande du cabinet qu’il a déclaré avec les autorisations et les magasins de sa déclaration, que la fenêtre nomme', function () use ($demandeDuCabinetDeclare) {
    [$lyon1, $lyon2, $demande, $attendues] = $demandeDuCabinetDeclare->call($this);

    $this->seConnecter($this->proprietaireDe($lyon1), 'password', panneau: 'societe')
        ->aller('/societe/'.$lyon1->societe->uuid.'/rattachement-cabinet/'.$demande->uuid)
        ->cliquerSur('Accepter avec les autorisations de votre déclaration')
        ->voir('Ce cabinet consultera le magasin Lyon 2, avec les autorisations de votre déclaration : '.implode(', ', PermissionRegistry::libellesDe($attendues)).'.')
        ->nePasVoir('Clients : voir')
        ->soumettre('Confirmer')
        ->voir('Ce cabinet est désormais votre cabinet.');

    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $lyon1->societe_id)->where('etat', EtatDuLien::Actif->value)->sole();
    expect([$lien->tous_les_magasins, $lien->magasinsChoisis()])->toBe([false, [$lyon2->id]])
        ->and(app(TenantContext::class)->runAsSociete($lyon1->societe, fn (): array => $lien->autorisations()))->toBe($attendues);
});

test('le propriétaire accepte la demande d’un autre cabinet avec les autorisations par défaut, dans tous ses magasins', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Atelier Morel']);
    $cabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Luc Moreau');
    $this->demanderLeRattachement($cabinet, $magasin->societe, 'contact@atelier.test');
    $demande = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::DemandeParLeCabinet->value)->sole();
    $parDefaut = PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce);

    $this->seConnecter($this->proprietaireDe($magasin), 'password', panneau: 'societe')
        ->aller('/societe/'.$magasin->societe->uuid.'/rattachement-cabinet/'.$demande->uuid)
        ->cliquerSur('Accepter avec les autorisations par défaut')
        ->voir('Ce cabinet consultera tous vos magasins réels, présents et futurs : '.implode(', ', PermissionRegistry::libellesDe($parDefaut)).'.')
        ->soumettre('Confirmer')
        ->voir('Ce cabinet est désormais votre cabinet.');

    $lien = $demande->fresh();
    expect([$lien->etat, $lien->tous_les_magasins])->toBe([EtatDuLien::Actif, true])
        ->and(app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => $lien->autorisations()))->toEqualCanonicalizing($parDefaut);
});

test('le propriétaire accepte en ajustant la demande d’un autre cabinet, pour un seul magasin et sans « Devis : voir »', function () {
    $lyon1 = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
    $this->ouvrirUnAutreMagasin($lyon1, ['nom' => 'Lyon 2']);
    $cabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Luc Moreau');
    $this->demanderLeRattachement($cabinet, $lyon1->societe, 'contact@lyon.test');
    $demande = LienCabinet::query()->withoutGlobalScopes()->where('etat', EtatDuLien::DemandeParLeCabinet->value)->sole();

    $this->seConnecter($this->proprietaireDe($lyon1), 'password', panneau: 'societe')
        ->aller('/societe/'.$lyon1->societe->uuid.'/rattachement-cabinet/'.$demande->uuid)
        ->cliquerSur('Accepter en ajustant les autorisations')
        ->cliquer(Navigation::FENETRE.' >> label:has-text("Seulement les magasins cochés ci-dessous")')
        ->cliquer(Navigation::FENETRE.' >> label:has-text("Lyon 1")')
        ->cliquer(Navigation::FENETRE.' >> label:has-text("Devis : voir")')
        ->soumettre('Soumettre')
        ->voir('Ce cabinet est désormais votre cabinet.');

    $lien = $demande->fresh();
    expect([$lien->etat, $lien->tous_les_magasins, $lien->magasinsChoisis()])->toBe([EtatDuLien::Actif, false, [$lyon1->id]])
        ->and(app(TenantContext::class)->runAsSociete($lyon1->societe, fn (): array => $lien->autorisations()))->not->toContain(PermissionRegistry::DEVIS_VIEW)
        ->toContain(PermissionRegistry::CLIENT_VIEW);
});
