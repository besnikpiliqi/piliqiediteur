<?php

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * Le bouton « Ouvrir le magasin de démonstration » du tableau de bord de la
 * société, et « Rouvrir le magasin de démonstration » une fois qu'il s'est
 * fermé faute d'opération : sa fenêtre dit au propriétaire qu'il y vend, le
 * seul magasin où il le fait (décision de l'éditeur du 12/11/2026), et à un
 * administrateur de tous les magasins autorisé à les gérer, qui l'ouvre
 * aussi, que son équipe y vend et qu'il n'y vend que si le propriétaire lui
 * a coché la vente. Confirmée, elle mène au magasin de démonstration.
 */

test('le propriétaire ouvre le magasin de démonstration, dont la fenêtre dit qu’il y vend', function () {
    $societe = $this->inscrireUneSociete();
    $proprietaire = $societe->proprietaire()->sole();
    $onglet = $this->seConnecter($proprietaire);

    $onglet->cliquerSur('Ouvrir le magasin de démonstration')
        ->voir('vous y vendez, encaissez et clôturez comme pour de vrai, ce que vous ne faites jamais dans vos magasins réels')
        ->voir('pour y faire essayer la caisse à votre équipe, créez-y des comptes d\'entraînement')
        ->nePasVoir('vous n\'y vendez que si le propriétaire vous a coché la vente')
        ->soumettre('Confirmer')
        ->attendreLaPage('^/app/[0-9a-f-]+$');

    $demonstration = $societe->demonstration()->sole();
    expect($onglet->chemin())->toBe('/app/'.$demonstration->uuid)
        ->and($demonstration->statut)->toBe(StatutMagasin::Actif)
        ->and($proprietaire->fresh()->tientUneCaisseDans($demonstration))->toBeTrue();
});

test('l’administrateur rouvre le magasin de démonstration fermé faute d’opération, dont la fenêtre dit qu’il n’y vend que si la vente lui est cochée', function () {
    $this->travelTo(self::aParis('2026-02-02 10:00:00'));
    $societe = $this->inscrireUneSociete();
    $reel = $this->creerLeMagasinDuSiege($societe);
    $administrateur = $this->administrateur($reel, null, [AutorisationsSociete::MAGASINS_GERER], nom: 'Adèle Martin');
    $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
    $this->travelTo(self::aParis('2026-03-09 10:00:00'));
    $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
    $this->travelTo(self::instantDuTest());
    app(TenantContext::class)->clear();
    expect($demonstration->fresh()->statut)->toBe(StatutMagasin::Ferme);

    $onglet = $this->seConnecter($administrateur)->aller('/societe/'.$societe->uuid);

    $onglet->cliquerSur('Rouvrir le magasin de démonstration')
        ->voir('votre équipe y vend, encaisse et clôture comme pour de vrai, avec les comptes d\'entraînement qu\'y créent le propriétaire et les responsables de ce magasin')
        ->voir('vous n\'y vendez que si le propriétaire vous a coché la vente')
        ->nePasVoir('vous y vendez, encaissez et clôturez')
        ->soumettre('Confirmer')
        ->attendreLaPage('^/app/'.$demonstration->uuid.'$');

    $rouverte = $demonstration->fresh();
    expect($rouverte->statut)->toBe(StatutMagasin::Actif)
        ->and(array_values(array_intersect(PermissionRegistry::deVente(), $administrateur->fresh()->autorisationsDans($rouverte))))->toBe([])
        ->and($administrateur->fresh()->tientUneCaisseDans($rouverte))->toBeFalse();
});
