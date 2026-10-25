<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un ticket ou une facture émis garde le client et le type de client du
 * jour de son émission, quoi que devienne la fiche ensuite, et l'avoir qui
 * le corrige garde les siens ; un brouillon dont le client a changé de
 * type ne s'émet qu'une fois réenregistré.
 */
class TypeClientFigeTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $magasin = Magasin::factory()->create(['code' => 'MABOUT']);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Une ligne d'un article à 120,00 € TTC, TVA à 20 %.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function uneLigneA120Euros(): array
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);

        return [['produit_id' => $produit->id, 'quantite' => 1]];
    }

    private function emettre(Client $client): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->uneLigneA120Euros()));
    }

    private function rendu(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
    }

    /**
     * Le ticket de « Jean Ancien », particulier, émis le 9 octobre 2026 à
     * 10 h, impayé, à échéance du 8 novembre (délai du magasin de 30
     * jours) ; à 15 h, sa fiche devient « Marc Nouveau »,
     * professionnel, SIREN 111222333.
     *
     * @return array{0: Client, 1: Facture}
     */
    private function ticketDontLeClientDevientProfessionnel(): array
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $ticket = $this->emettre($client);
        $this->travelTo(self::aParis('2026-10-09 15:00:00'));
        $client->update(['nom' => 'Marc Nouveau', 'type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        return [$client, $ticket->fresh()];
    }

    public function test_un_ticket_reste_le_ticket_de_son_client_d_origine_quand_la_fiche_devient_professionnelle(): void
    {
        $magasin = $this->magasin();
        [, $ticket] = $this->ticketDontLeClientDevientProfessionnel();
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());

        $this->assertSame(['TK-MABOUT-2026-000001', '100.00', '20.00', '120.00'], [$ticket->numero, $ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertSame(['Ticket de caisse', 'ticket-TK-MABOUT-2026-000001.pdf', false], [$ticket->libelleDocument(), app(FacturePdfGenerator::class)->nomFichier($ticket), $ticket->relevantDeLeInvoicing()]);
        $this->assertStringStartsWith(
            "Sauf erreur de notre part, le ticket de caisse TK-MABOUT-2026-000001 d'un montant de 120,00 € TTC, arrivé à échéance le 08/11/2026, n'est pas entièrement réglé : il reste 120,00 € à payer.",
            $ticket->messageDeRelance(),
        );
        $pdf = $this->rendu($ticket);
        $this->assertStringContainsString('<strong>Jean Ancien</strong>', $pdf);
        $this->assertStringNotContainsString('Marc Nouveau', $pdf);
        $this->assertStringNotContainsString('111222333', $pdf);
        $this->assertSame([1, '120.00'], [$cloture->nombre_ventes, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $ticket->fresh()->cloture_caisse_id);
    }

    public function test_une_facture_garde_le_siren_fige_de_son_client_redevenu_particulier(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $client = Client::factory()->professionnel()->create(['nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017']);
        $facture = $this->emettre($client);

        $client->update(['type_client' => TypeClient::Particulier]);

        $facture = $facture->fresh();
        $this->assertSame(['FAC-MABOUT-2026-000001', 'Facture', 'facture-FAC-MABOUT-2026-000001.pdf', true], [$facture->numero, $facture->libelleDocument(), app(FacturePdfGenerator::class)->nomFichier($facture), $facture->relevantDeLeInvoicing()]);
        $this->assertSame([TypeClient::Professionnel, 'Atelier Durand', '100000009', '10000000900017'], [$facture->type_client, $facture->client_nom, $facture->client_siren, $facture->client_siret]);
        $this->assertStringContainsString('SIREN : 100000009', $this->rendu($facture));
    }

    /**
     * @return array<string, array{0: bool, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function changementsDeType(): array
    {
        return [
            'particulier devenu professionnel' => [false, ['type_client' => TypeClient::Professionnel, 'siren' => '111222333'], 'professionnel', 'FAC-MABOUT-2026-000001'],
            'professionnel devenu particulier' => [true, ['type_client' => TypeClient::Particulier], 'particulier', 'TK-MABOUT-2026-000001'],
        ];
    }

    /**
     * @param  array<string, mixed>  $nouvelleFiche
     */
    #[DataProvider('changementsDeType')]
    public function test_un_brouillon_dont_le_client_a_change_de_type_ne_s_emet_qu_une_fois_reenregistre(bool $professionnelAuBrouillon, array $nouvelleFiche, string $nouveauType, string $numeroApresReenregistrement): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $client = $professionnelAuBrouillon ? Client::factory()->professionnel()->create() : Client::factory()->create();
        $lignes = $this->uneLigneA120Euros();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, $lignes);
        $client->update($nouvelleFiche);

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon),
            LogicException::class,
            "Ce client est devenu {$nouveauType} depuis l'enregistrement du brouillon : ouvrez-le et enregistrez-le de nouveau.",
        );

        $this->assertSame([StatutFacture::Brouillon, null, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero, $brouillon->fresh()->client_nom]);
        $this->assertDatabaseCount('sequences_documents', 0);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count());

        $reenregistre = app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $client->fresh(), $lignes);

        $this->assertSame($numeroApresReenregistrement, app(EmettreFactureAction::class)->handle(self::auteur(), $reenregistre)->numero);
    }

    /**
     * Le retour du ticket, le lendemain, corrige un ticket : il en garde le
     * client, particulier, « Jean Ancien », sans identifiant, et non la
     * fiche devenue « Marc Nouveau », professionnel, et se déduit de la
     * clôture de son jour.
     */
    public function test_l_avoir_sur_le_ticket_garde_le_type_et_l_identite_du_client_du_ticket(): void
    {
        $magasin = $this->magasin();
        [$client, $ticket] = $this->ticketDontLeClientDevientProfessionnel();
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());
        $this->travelTo(self::aParis('2026-10-10 10:00:00'));

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client->fresh(), [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $this->assertSame(['AV-MABOUT-2026-000001', TypeClient::Particulier, 'Jean Ancien', null, '120.00'], [$avoir->numero, $avoir->type_client, $avoir->client_nom, $avoir->client_siren, $avoir->total_ttc]);
        $this->assertSame(
            ['type_client' => 'particulier', 'nom' => 'Jean Ancien', 'siren' => null],
            Arr::only($this->clientSigne($avoir), ['type_client', 'nom', 'siren']),
        );
        $this->travelTo(self::aParis('2026-10-10 19:00:00'));
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());
        $this->assertSame([0, 1, '-120.00'], [$cloture->nombre_ventes, $cloture->nombre_avoirs, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $avoir->fresh()->cloture_caisse_id);
    }

    /**
     * L'avoir d'une facture à « Atelier Durand », dont la fiche est
     * redevenue particulier (SIREN, SIRET et n° de TVA effacés), reste un
     * avoir entre professionnels avec les identifiants de la facture qu'il
     * corrige : sur son PDF comme dans ce qu'il signe.
     */
    public function test_l_avoir_d_une_facture_garde_les_identifiants_du_professionnel_redevenu_particulier(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->magasin();
        $client = Client::factory()->professionnel()->create(['nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009']);
        $facture = $this->emettre($client);
        $client->update(['type_client' => TypeClient::Particulier]);

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client->fresh(), [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);

        $this->assertSame([null, null, null], [$client->fresh()->siren, $client->fresh()->siret, $client->fresh()->numero_tva]);
        $this->assertSame(
            ['AV-MABOUT-2026-000001', TypeClient::Professionnel, 'Atelier Durand', '100000009', '10000000900017', 'FR88100000009', '120.00'],
            [$avoir->numero, $avoir->type_client, $avoir->client_nom, $avoir->client_siren, $avoir->client_siret, $avoir->client_numero_tva, $avoir->total_ttc],
        );
        $this->assertSame(
            ['type_client' => 'professionnel', 'nom' => 'Atelier Durand', 'siren' => '100000009', 'siret' => '10000000900017', 'numero_tva' => 'FR88100000009'],
            Arr::only($this->clientSigne($avoir), ['type_client', 'nom', 'siren', 'siret', 'numero_tva']),
        );
        $pdf = app(AvoirPdfGenerator::class)->html($avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'));
        $this->assertStringContainsString('SIREN : 100000009', $pdf);
        $this->assertStringContainsString('SIRET : 10000000900017', $pdf);
        $this->assertStringContainsString('N° TVA : FR88100000009', $pdf);
    }

    /**
     * Un avoir sur deux factures d'un même professionnel, émises avant et
     * après son déménagement, prend le client de la dernière émise, même
     * quand son brouillon a été créé le premier et que les lignes de
     * l'avoir citent d'abord l'autre.
     */
    public function test_l_avoir_de_plusieurs_factures_prend_le_client_de_la_derniere_emise(): void
    {
        $this->magasin();
        $client = Client::factory()->professionnel()->create(['nom' => 'Atelier Durand', 'siren' => '100000009', 'adresse' => '3 rue des Forges', 'code_postal' => '69002', 'ville' => 'Lyon']);
        $this->travelTo(self::aParis('2026-10-08 09:00:00'));
        $emiseEnDernier = app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->uneLigneA120Euros());
        $emiseEnPremier = app(CreerFactureAction::class)->handle(self::auteur(), $client, $this->uneLigneA120Euros());
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $emiseEnPremier = app(EmettreFactureAction::class)->handle(self::auteur(), $emiseEnPremier);
        $client->update(['nom' => 'Atelier Durand et Fils', 'adresse' => '18 avenue Jean Jaurès', 'code_postal' => '69007']);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $emiseEnDernier = app(EmettreFactureAction::class)->handle(self::auteur(), $emiseEnDernier);
        $client->update(['nom' => 'Durand Industries', 'adresse' => '1 place Bellecour']);
        $this->travelTo(self::aParis('2026-10-10 10:00:00'));

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client->fresh(), [
            ['ligne_facture_id' => $emiseEnPremier->lignes->sole()->id, 'quantite' => 1],
            ['ligne_facture_id' => $emiseEnDernier->lignes->sole()->id, 'quantite' => 1],
        ]);

        $this->assertSame(['FAC-MABOUT-2026-000001', 'FAC-MABOUT-2026-000002'], [$emiseEnPremier->numero, $emiseEnDernier->numero]);
        $this->assertSame(
            ['Atelier Durand et Fils', '18 avenue Jean Jaurès', '69007', 'Lyon', '100000009', '240.00'],
            [$avoir->client_nom, $avoir->client_adresse, $avoir->client_code_postal, $avoir->client_ville, $avoir->client_siren, $avoir->total_ttc],
        );
    }

    /**
     * Le client que le document a signé au registre fiscal à son émission.
     *
     * @return array<string, mixed>
     */
    private function clientSigne(Facture|Avoir $document): array
    {
        $enregistrement = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Vente->value)
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->sole();

        return json_decode($enregistrement->contenu, true, flags: JSON_THROW_ON_ERROR)['donnees']['document']['client'];
    }
}
