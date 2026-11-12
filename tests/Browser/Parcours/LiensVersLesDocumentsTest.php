<?php

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Constat C2 de l'éditeur, confirmé à T43, dans le navigateur : chaque
 * numéro de document d'une fiche mène à la fiche de ce document pour qui
 * peut la voir, et reste un texte simple pour les autres ; un ticket est
 * nommé « Ticket ». Dans la société de test (voir
 * TestCaseDuNavigateur::remplirLeMagasin()), Jean Dupont a un avoir de
 * 6,00 €, fait par le responsable sur un ticket du vendeur ; le ticket que
 * le responsable lui émet ensuite, resté dû, en reçoit d'office le crédit.
 */

/**
 * L'avoir de Jean Dupont, le ticket du vendeur qu'il corrige et le ticket
 * du responsable, émis ensuite, que son crédit règle en partie.
 *
 * @return array{0: Avoir, 1: Facture, 2: Facture}
 */
function avoirEtTicketsDeJeanDupont(SocieteDeTest $societe): array
{
    return app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $client = Client::query()->where('nom', 'Jean Dupont')->sole();
        $ticketDuResponsable = app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle($societe->responsable, $client, [
            ['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1],
        ]));
        $avoir = Avoir::query()->where('client_id', $client->id)->sole();

        return [$avoir, $avoir->facturesConcernees()->sole(), $ticketDuResponsable];
    });
}

/**
 * Le lien de ce texte exact, dans la page.
 */
function liensDeTexte(Navigation $onglet, string $texte): int
{
    return $onglet->element('internal:role=link[name='.Navigation::texte($texte).'s]')->count();
}

test('le responsable passe d’un document à l’autre par leurs numéros : avoir, ticket crédité, ticket réglé par le crédit', function () {
    $societe = $this->societeComplete();
    [$avoir, $ticketDuVendeur, $ticketDuResponsable] = avoirEtTicketsDeJeanDupont($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/avoirs/'.$avoir->id)
        ->voir('Crédit appliqué')
        ->voir('Ticket '.$ticketDuVendeur->numero);

    $onglet->cliquerSurLeLien((string) $ticketDuResponsable->numero)
        ->attendreLaPage('/factures/'.$ticketDuResponsable->id.'$')
        ->voir('Crédité par avoir')
        ->cliquerSurLeLien((string) $avoir->numero)
        ->attendreLaPage('/avoirs/'.$avoir->id.'$')
        ->cliquerSurLeLien((string) $ticketDuVendeur->numero)
        ->attendreLaPage('/factures/'.$ticketDuVendeur->id.'$')
        ->voir('Avoirs émis')
        ->cliquerSurLeLien((string) $avoir->numero)
        ->attendreLaPage('/avoirs/'.$avoir->id.'$');

    $onglet->aller($magasin.'/paiements')
        ->cliquerSurLeLien((string) $ticketDuVendeur->numero)
        ->attendreLaPage('/factures/'.$ticketDuVendeur->id.'$');
});

test('le collaborateur du cabinet lié ouvre de même le ticket que le crédit d’un avoir a réglé', function () {
    $societe = $this->societeComplete();
    [$avoir, , $ticketDuResponsable] = avoirEtTicketsDeJeanDupont($societe);

    $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/app/'.$societe->magasin->uuid.'/avoirs/'.$avoir->id)
        ->cliquerSurLeLien((string) $ticketDuResponsable->numero)
        ->attendreLaPage('/factures/'.$ticketDuResponsable->id.'$');
});

/*
 * Le vendeur voit son ticket, mais pas les avoirs : le numéro de l'avoir
 * qui le corrige se lit sur sa fiche, sans lien qui le mènerait à un refus.
 */
test('un vendeur qui ne voit pas l’avoir de son ticket en lit le numéro, sans lien', function () {
    $societe = $this->societeComplete();
    [$avoir, $ticketDuVendeur] = avoirEtTicketsDeJeanDupont($societe);

    $onglet = $this->seConnecter($societe->vendeur)
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticketDuVendeur->id)
        ->voir('Avoirs émis')
        ->voir((string) $avoir->numero);

    expect(liensDeTexte($onglet, (string) $avoir->numero))->toBe(0);
});

/*
 * L'avoir fournisseur de la société de test (le retour d'une bougie
 * cassée au Grossiste du Nord) mène à la facture d'achat qu'il crédite, qui
 * mène à son tour à lui.
 */
test('le responsable passe de l’avoir fournisseur à la facture d’achat qu’il crédite, et retour', function () {
    $societe = $this->societeComplete();
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): AvoirFournisseur => AvoirFournisseur::query()->with('factureAchat')->sole());
    $achat = $avoir->factureAchat;

    $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs/'.$avoir->id)
        ->voir('Facture d\'achat créditée')
        ->cliquerSurLeLien((string) $achat->numero)
        ->attendreLaPage('/facture-achats/'.$achat->id.'$')
        ->cliquer('#avoirsFournisseurEmisListe a[href$="/avoir-fournisseurs/'.$avoir->id.'"]')
        ->attendreLaPage('/avoir-fournisseurs/'.$avoir->id.'$');
});

/*
 * La fiche de la clôture du 10/03/2026, faite par le responsable : chaque
 * ticket et l'avoir de la journée y mènent à leur fiche pour lui. Clément,
 * un vendeur qui voit les clôtures de toute l'équipe mais seulement ses
 * ventes, n'y a en lien que son ticket ; les autres documents ne s'y
 * comptent qu'en nombre. Un observateur qui voit les tickets de l'équipe
 * sans pouvoir ouvrir la liste des ventes en lit les numéros, sans lien.
 */
test('chaque numéro de la fiche d’une clôture ouvre son document ; un vendeur n’y a en lien que les siens', function () {
    $societe = $this->societeComplete();
    [$avoir, $ticketDuVendeur] = avoirEtTicketsDeJeanDupont($societe);
    [$clement, $sonTicket, $lecteur, $cloture] = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $clement = $this->membre($societe->magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut($societe->societe->type_activite),
            PermissionRegistry::CLOTURE_CAISSE_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
        ], ['name' => 'Clément Caisse']);
        $sonTicket = self::vendreAuComptant($clement, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1]]);
        $lecteur = $this->membre($societe->magasin, RoleMagasin::Observateur, [
            PermissionRegistry::CLOTURE_CAISSE_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
            PermissionRegistry::FACTURE_VIEW_TOUTES,
        ], ['name' => 'Odile Regard']);

        return [$clement, $sonTicket, $lecteur, app(CloturerCaisseAction::class)->handle($societe->responsable, $societe->magasin, '2026-03-10')];
    });
    $tickets = app(TenantContext::class)->runAs($societe->magasin, fn (): array => ClotureCaisse::query()->findOrFail($cloture->id)->factures()->orderBy('numero')->get()->all());
    expect(array_map(fn (Facture $ticket): int => $ticket->id, $tickets))->toContain($sonTicket->id, $ticketDuVendeur->id)
        ->and(app(TenantContext::class)->runAs($societe->magasin, fn (): array => $cloture->avoirs()->pluck('id')->all()))->toBe([$avoir->id]);
    $fiche = '/app/'.$societe->magasin->uuid.'/cloture-caisses/'.$cloture->id;

    $onglet = $this->seConnecter($societe->responsable);
    foreach ($tickets as $ticket) {
        $onglet->aller($fiche)
            ->cliquerSurLeLien((string) $ticket->numero)
            ->attendreLaPage('/factures/'.$ticket->id.'$')
            ->voir('Ticket '.$ticket->numero);
    }
    $onglet->aller($fiche)
        ->cliquerSurLeLien((string) $avoir->numero)
        ->attendreLaPage('/avoirs/'.$avoir->id.'$');

    $onglet = $this->seConnecter($clement)->aller($fiche)
        ->voir('Autres tickets de l\'équipe')
        ->voir('Autres avoirs de l\'équipe')
        ->nePasVoir((string) $ticketDuVendeur->numero)
        ->nePasVoir((string) $avoir->numero);
    $onglet->cliquerSurLeLien((string) $sonTicket->numero)
        ->attendreLaPage('/factures/'.$sonTicket->id.'$');

    $onglet = $this->seConnecter($lecteur)->aller($fiche)
        ->voir((string) $sonTicket->numero)
        ->voir((string) $ticketDuVendeur->numero);
    expect(liensDeTexte($onglet, (string) $sonTicket->numero))->toBe(0)
        ->and(liensDeTexte($onglet, (string) $ticketDuVendeur->numero))->toBe(0);
});

/*
 * Le SIREN de la société de test corrigé par la procédure de l'éditeur
 * (courrier d'examen au SIREN correct, son code saisi, examen qui désigne
 * la facture du vendeur à l'Hôtel des Pins, SIRET de ses deux magasins et
 * n° de TVA corrigés), le responsable émet la facture rectificative : la fiche de la
 * facture rectifiée et celle de la rectificative mènent l'une à l'autre
 * pour lui ; le vendeur, qui voit sa facture mais pas la rectificative du
 * responsable, en lit le numéro, sans lien.
 */
test('la facture rectifiée et sa rectificative mènent l’une à l’autre ; le vendeur en lit le numéro, sans lien', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('numero', 'like', 'FAC-%')->where('statut', StatutFacture::PartiellementPayee)->sole());
    $sirenCorrect = SocieteFactory::sirenFictif();
    $editeur = new InterventionDeLEditeur('SIREN d\'une filiale saisi à la place de celui de la société', 'DOSSIER-2026-0095', ['alice', 'bruno']);
    $this->travelTo(self::aParis('2026-03-10 10:30:00'));
    app(TenantContext::class)->clear();
    app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->societe->fresh(), MotifCode::ExamenSiren, $editeur, $sirenCorrect);
    app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure($sirenCorrect, MotifCode::ExamenSiren, $this->codeDuCourrier($societe->societe, MotifCode::ExamenSiren), '127.0.0.1');
    app(TenantContext::class)->clear();
    app(ExaminerSirenAction::class)->handle($societe->societe->fresh(), $sirenCorrect, DecisionExamenSiren::Erreur, $editeur);
    app(TenantContext::class)->clear();
    $this->travelTo(self::aParis('2026-03-10 10:40:00'));
    app(CorrigerSirenAction::class)->handle($societe->societe->fresh(), $sirenCorrect, [
        'PORT' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001'),
        'GARE' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002'),
    ], IdentifiantsInsee::numeroTvaPropose($sirenCorrect), $editeur);
    app(TenantContext::class)->clear();
    $this->travelTo(self::aParis('2026-03-10 10:50:00'));
    $rectificative = app(TenantContext::class)->runAs($societe->magasin->fresh(), fn (): Facture => app(EmettreLaFactureRectificativeAction::class)->handle($societe->responsable, $facture->fresh()));
    $magasin = '/app/'.$societe->magasin->uuid;

    $this->seConnecter($societe->responsable)->aller($magasin.'/factures/'.$facture->id)
        ->voir('Facture rectificative')
        ->cliquerSurLeLien((string) $rectificative->numero)
        ->attendreLaPage('/factures/'.$rectificative->id.'$')
        ->voir('Rectifie la facture')
        ->cliquerSurLeLien((string) $facture->numero)
        ->attendreLaPage('/factures/'.$facture->id.'$');

    $onglet = $this->seConnecter($societe->vendeur)->aller($magasin.'/factures/'.$facture->id)
        ->voir('Facture rectificative')
        ->voir((string) $rectificative->numero);
    expect(liensDeTexte($onglet, (string) $rectificative->numero))->toBe(0);
});

/*
 * Sous la ligne de la bougie de la facture d'achat, « Retourné : 1 (avoir
 * … du 09/03/2026) » mène à l'avoir fournisseur pour qui voit les avoirs
 * fournisseur ; un observateur qui ne voit que les achats en lit le
 * numéro, sans lien.
 */
test('le retour sous une ligne d’achat mène à son avoir fournisseur pour qui le voit', function () {
    $societe = $this->societeComplete();
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): AvoirFournisseur => AvoirFournisseur::query()->with('factureAchat')->sole());
    $achat = '/app/'.$societe->magasin->uuid.'/facture-achats/'.$avoir->factureAchat->id;
    $retour = 'a[style*="underline"]:text-is('.Navigation::texte((string) $avoir->numero).')';
    $lecteur = app(TenantContext::class)->runAs($societe->magasin, fn (): User => $this->membre($societe->magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_ACHAT_VIEW], ['name' => 'Odile Regard']));

    $this->seConnecter($societe->responsable)->aller($achat)
        ->voir('Retourné : 1 (avoir '.$avoir->numero)
        ->cliquer($retour)
        ->attendreLaPage('/avoir-fournisseurs/'.$avoir->id.'$');

    $onglet = $this->seConnecter($lecteur)->aller($achat)
        ->voir('Retourné : 1 (avoir '.$avoir->numero);
    expect(liensDeTexte($onglet, (string) $avoir->numero))->toBe(0);
});

/*
 * La fiche de l'e-reporting du 09/03/2026 donne le numéro de la clôture
 * de la journée : il mène à la fiche de la clôture pour le responsable ;
 * un observateur qui voit les clôtures de toute l'équipe sans « voir (les
 * siennes) » ouvre l'e-reporting mais pas la clôture, et en lit le numéro,
 * sans lien.
 */
test('le numéro de la clôture sur la fiche de l’e-reporting ne mène à elle que pour qui peut l’ouvrir', function () {
    $societe = $this->societeComplete();
    $cloture = app(TenantContext::class)->runAs($societe->magasin, fn (): ClotureCaisse => ClotureCaisse::query()->where('date_cloture', '2026-03-09')->sole());
    $lecteur = app(TenantContext::class)->runAs($societe->magasin, fn (): User => $this->membre($societe->magasin, RoleMagasin::Observateur, [PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES], ['name' => 'Odile Regard']));
    $fiche = '/app/'.$societe->magasin->uuid.'/e-reporting/'.$cloture->id;

    $this->seConnecter($societe->responsable)->aller($fiche)
        ->voir('E-reporting du 09/03/2026')
        ->cliquerSurLeLien((string) $cloture->numero)
        ->attendreLaPage('/cloture-caisses/'.$cloture->id.'$');

    $onglet = $this->seConnecter($lecteur)->aller($fiche)
        ->voir('E-reporting du 09/03/2026')
        ->voir((string) $cloture->numero);
    expect(liensDeTexte($onglet, (string) $cloture->numero))->toBe(0);
});
