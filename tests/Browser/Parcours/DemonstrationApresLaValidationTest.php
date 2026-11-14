<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * T115 (lot DEMO-V) : une fois la société validée, la démonstration
 * disparaît de tous les écrans de la société et de ses magasins, et plus
 * personne n'y entre. Le propriétaire et un administrateur de tous les
 * magasins, qui l'ont vue sur le tableau de bord de la société avant la
 * validation, n'en lisent plus rien ensuite, ni sur ce tableau de bord, ni
 * dans la liste des magasins, ni dans le menu des magasins, ni sur la page
 * du téléchargement des documents ; son adresse mène à une page
 * introuvable.
 */

test('une fois la société validée, le propriétaire et l’administrateur ne voient plus la démonstration et son adresse est introuvable', function () {
    $societe = $this->inscrireUneSociete();
    $proprietaire = $societe->proprietaire()->sole();
    $reel = $this->creerLeMagasinDuSiege($societe->fresh(), 'Boutique Lyon 1');
    $administrateur = $this->administrateur($reel, nom: 'Adèle Martin');
    $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());
    $ticket = app(TenantContext::class)->runAs($demonstration, fn (): Facture => self::vendreAuComptant(
        $this->responsableDeTest($demonstration),
        Client::query()->where('est_comptoir', true)->sole(),
        [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]],
        ModePaiement::Especes,
    ));
    app(TenantContext::class)->clear();
    $espace = '/societe/'.$societe->uuid;

    $this->seConnecter($proprietaire, panneau: 'societe')->aller($espace)
        ->voir('Démonstration')
        ->voir('Essayer le magasin de démonstration')
        ->seDeconnecter();

    $this->saisirLeCodeDuCourrier($societe->fresh());
    $autre = $this->ouvrirUnAutreMagasin($reel->fresh(), ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
    app(TenantContext::class)->clear();

    foreach ([$proprietaire, $administrateur] as $compte) {
        $onglet = $this->seConnecter($compte->fresh(), panneau: 'societe')->aller($espace)
            ->voir('Boutique Lyon 1')
            ->nePasVoir('Démonstration')
            ->nePasVoir('démonstration')
            ->aller($espace.'/magasins')
            ->voir('Boutique de la Gare')
            ->nePasVoir(OuvrirLaDemonstrationAction::NOM);

        if ($compte->estProprietaire()) {
            $onglet->aller($espace.'/telecharger-les-documents')
                ->voir('Boutique Lyon 1')
                ->nePasVoir(OuvrirLaDemonstrationAction::NOM);
        }

        $onglet->aller('/app/'.$reel->uuid);
        $onglet->cliquer('.fi-tenant-menu-trigger')
            ->voir('Boutique de la Gare')
            ->nePasVoir(OuvrirLaDemonstrationAction::NOM);
        $this->tolerer(404, '/app/'.$demonstration->uuid);
        $onglet->aller('/app/'.$demonstration->uuid)->voir('Page introuvable')->nePasVoir(OuvrirLaDemonstrationAction::NOM);
        $this->tolerer(404, '/app/'.$demonstration->uuid.'/factures/');
        $onglet->aller('/app/'.$demonstration->uuid.'/factures/'.$ticket->getRouteKey())->voir('Page introuvable')->nePasVoir((string) $ticket->numero);
        $onglet->aller('/app/'.$reel->uuid)->seDeconnecter();
    }

    expect($autre->fresh())->not->toBeNull();
});
