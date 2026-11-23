<?php

use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\FormeJuridique;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Les mentions d'identité de la société dans le navigateur (décisions
 * P14.1, P14.3, P14.4 et P14.7) : le propriétaire, dans « Identité » de
 * l'espace de sa société, dit que son capital est variable et le laisse
 * sans montant, ou qu'il est celui d'une SNC, que la société est en
 * liquidation ou en location-gérance, ou qu'elle est un EIRL ; l'écran
 * refuse ce que les règles refusent, en français, et le pied de ses
 * documents imprime ce qu'il a déclaré (voir EmetteurFigeTest).
 */

const LIGNE_DE_LA_SOCIETE_AVANT = 'Ma Boutique SAS au capital de 10 000,00 €';

/**
 * L'identité de la société de test, telle que le pied de ses documents
 * l'imprime aujourd'hui : sa première ligne, puis les lignes de ses
 * mentions facultatives.
 *
 * @return list<string>
 */
function piedDeLIdentite(SocieteDeTest $societe): array
{
    return array_slice(Emetteur::duMagasin($societe->magasin->fresh())->lignesDeLaSociete(), 0, 2);
}

test('le propriétaire dit que son capital est variable, la case laisse son capital sans montant, et le pied imprime « à capital variable »', function () {
    $societe = $this->societeComplete();
    expect(piedDeLIdentite($societe)[0])->toBe(LIGNE_DE_LA_SOCIETE_AVANT);

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')->cliquerSurLeLien('Identité')->voir('Identité légale')->remplir('Capital social', '');
    $onglet->soumettre('Enregistrer')->voir(ModifierIdentiteSocieteAction::MESSAGE_CAPITAL_EXIGE);
    expect($societe->societe->fresh()->capital_social)->toBe('10000.00');

    $onglet->geste(fn () => $onglet->element(Navigation::champ('Capital variable'))->check(), 'la case « Capital variable »');
    $onglet->soumettre('Enregistrer')->voir('Identité de la société enregistrée');

    expect($societe->societe->fresh()->only(['capital_social', 'capital_variable']))->toBe(['capital_social' => null, 'capital_variable' => true])
        ->and(piedDeLIdentite($societe)[0])->toBe('Ma Boutique SAS à capital variable')
        ->and($societe->societe->fresh()->mentionsLegalesManquantes())->not->toContain('capital social');
});

test('le propriétaire d’une SNC enregistre son identité sans capital, que l’écran n’exige jamais', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')->cliquerSurLeLien('Identité')->voir('Identité légale')->choisir('Forme juridique', 'SNC')->remplir('Capital social', '');
    $onglet->soumettre('Enregistrer')->voir('Identité de la société enregistrée');

    expect($societe->societe->fresh()->only(['forme_juridique', 'capital_social']))->toBe(['forme_juridique' => FormeJuridique::Snc, 'capital_social' => null])
        ->and(piedDeLIdentite($societe)[0])->toBe('Ma Boutique SNC')
        ->and($societe->societe->fresh()->mentionsLegalesManquantes())->not->toContain('capital social');
});

test('le propriétaire déclare la société en liquidation et locataire-gérant, et le pied de ses documents l’imprime', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')->cliquerSurLeLien('Identité')->voir('Identité légale')->voir('Mentions facultatives');
    $onglet->geste(fn () => $onglet->element(Navigation::champ('La société est en liquidation'))->check(), 'la case « en liquidation »');
    $onglet->choisir('Qualité d\'exploitant', ExploitationEnGerance::LocataireGerant->getLabel());
    $onglet->soumettre('Enregistrer')->voir('Identité de la société enregistrée');

    expect($societe->societe->fresh()->only(['en_liquidation', 'exploitation_en_gerance']))->toBe(['en_liquidation' => true, 'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant])
        ->and(piedDeLIdentite($societe))->toBe(['Ma Boutique SAS société en liquidation, au capital de 10 000,00 €', 'Locataire-gérant']);

    $onglet->choisir('Qualité d\'exploitant', 'Aucune')->soumettre('Enregistrer')->voir('Identité de la société enregistrée');

    expect($societe->societe->fresh()->exploitation_en_gerance)->toBeNull()
        ->and(piedDeLIdentite($societe)[1])->not->toBe('Locataire-gérant');
});

test('le propriétaire d’un entrepreneur individuel coche EIRL, l’écran lui demande l’objet de son activité, puis le pied imprime « EIRL » et cet objet', function () {
    $societe = $this->societeComplete();
    $caseEirl = 'Entrepreneur individuel à responsabilité limitée (EIRL)';

    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')->cliquerSurLeLien('Identité')->voir('Identité légale');
    expect($onglet->element(Navigation::champ($caseEirl))->count())->toBe(0);

    $onglet->choisir('Forme juridique', 'Entrepreneur individuel (micro-entrepreneur compris)');
    $onglet->geste(fn () => $onglet->element(Navigation::champ($caseEirl))->check(), 'la case EIRL');
    $onglet->soumettre('Enregistrer')->voir(ModifierIdentiteSocieteAction::MESSAGE_OBJET_DE_L_EIRL);
    expect($societe->societe->fresh()->eirl)->toBeFalse();

    $onglet->remplir('Objet de l\'activité de l\'EIRL', 'Vente de plantes et de fleurs');
    $onglet->soumettre('Enregistrer')->voir('Identité de la société enregistrée');

    expect($societe->societe->fresh()->only(['forme_juridique', 'capital_social', 'eirl', 'eirl_objet']))->toBe([
        'forme_juridique' => FormeJuridique::EntrepreneurIndividuel,
        'capital_social' => null,
        'eirl' => true,
        'eirl_objet' => 'Vente de plantes et de fleurs',
    ])->and(piedDeLIdentite($societe))->toBe(['Ma Boutique EIRL', 'Objet de l\'activité à laquelle le patrimoine est affecté : Vente de plantes et de fleurs']);
});
