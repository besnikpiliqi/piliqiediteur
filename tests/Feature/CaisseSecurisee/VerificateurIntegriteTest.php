<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RapportIntegrite;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La vérification de l'intégrité du registre fiscal : chaînes, ligne de
 * tête, documents, imputations et numérotation. Chaque falsification
 * passe par DB::table(), filtrée par magasin, comme le ferait un
 * administrateur de base malveillant ; celles d'une table en ajout seul
 * suppriment d'abord le déclencheur qui l'en empêcherait.
 */
class VerificateurIntegriteTest extends TestCase
{
    use RefreshDatabase;

    private function magasinActif(string $code = 'LYON1'): Magasin
    {
        $magasin = Magasin::factory()->create(['code' => $code]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function produit(string $reference, float $prixTtc, float $tauxTva = 20): Produit
    {
        return Produit::factory()->create(['reference' => $reference, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $tauxTva, 'suivi_stock' => false]);
    }

    /**
     * Une matinée du magasin actif où naît chaque sorte de document et
     * d'imputation, le 9 octobre 2026 à 10 h, heure de Paris :
     * - TK-LYON1-2026-000001 (29,50 €, payé par carte, paiement n° 1) ;
     * - AV-LYON1-2026-000001, retour d'un café de ce ticket (12,00 €) : déjà
     *   payé, il devient un crédit du client ;
     * - TK-LYON1-2026-000002 (24,00 €), réglé par ce crédit et 12,00 € en
     *   espèces (paiement n° 2) ;
     * - FAC-LYON1-2026-000001 à un professionnel (60,00 €), puis AV-LYON1-2026-000002
     *   (12,00 €), imputé sur elle, et un virement du reste, 48,00 €
     *   (paiement n° 3).
     *
     * @return array{ticket: Facture, avoir: Avoir, ticketAvecCredit: Facture, facture: Facture, avoirSurFacture: Avoir, virement: Paiement}
     */
    private function activite(): array
    {
        $this->travelTo('2026-10-09 10:00:00');
        $particulier = Client::factory()->create();
        $professionnel = Client::factory()->professionnel()->create();
        $cafe = $this->produit('CAF', 12);
        $livre = $this->produit('LIV', 5.50, 5.5);
        $ticket = $this->encaisser($particulier, [[$cafe, 2], [$livre, 1]], ModePaiement::CarteBancaire);
        $avoir = app(EnregistrerAvoirAction::class)->handle($particulier, [['ligne_facture_id' => $ticket->lignes->firstWhere('produit_id', $cafe->id)->id, 'quantite' => 1]], 'Retour');
        $ticketAvecCredit = $this->encaisser($particulier, [[$cafe, 2]], ModePaiement::Especes);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($professionnel, [['produit_id' => $cafe->id, 'quantite' => 5]]));
        $avoirSurFacture = app(EnregistrerAvoirAction::class)->handle($professionnel, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);
        $virement = app(EnregistrerPaiementAction::class)->handle(
            $professionnel,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '48.00', 'reference' => 'VIR-1', 'recu_le' => '2026-10-09'],
            [['facture_id' => $facture->id, 'montant' => '48.00']],
        );

        return compact('ticket', 'avoir', 'ticketAvecCredit', 'facture', 'avoirSurFacture', 'virement');
    }

    /**
     * @param  list<array{0: Produit, 1: int}>  $articles
     */
    private function encaisser(Client $client, array $articles, ModePaiement $mode): Facture
    {
        return app(EnregistrerVenteCaisseAction::class)->handle(
            $client,
            array_map(static fn (array $article): array => ['produit_id' => $article[0]->id, 'quantite' => $article[1]], $articles),
            $mode,
        );
    }

    /**
     * @param  array<string, array{sequence: int, signature: string}>|null  $pointsDeReprise
     */
    private function verifier(?array $pointsDeReprise = null): RapportIntegrite
    {
        return app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin(), $pointsDeReprise);
    }

    /**
     * Les anomalies du rapport : code de l'événement qui la journalise et
     * message.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function anomalies(RapportIntegrite $rapport): array
    {
        return array_map(static fn (AnomalieFiscale $anomalie): array => [$anomalie->code->value, $anomalie->message], $rapport->anomalies());
    }

    private function enregistrementDe(Model $document, ChaineFiscale $chaine): EnregistrementFiscal
    {
        return EnregistrementFiscal::query()
            ->where('chaine', $chaine->value)
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->sole();
    }

    private function enregistrement(ChaineFiscale $chaine, int $sequence): EnregistrementFiscal
    {
        return EnregistrementFiscal::query()->where('chaine', $chaine->value)->where('sequence', $sequence)->sole();
    }

    /**
     * Ce que ferait un administrateur de base : écrire dans le registre en
     * retirant le déclencheur qui l'interdit.
     *
     * @param  array<string, mixed>  $colonnes
     */
    private function falsifierLEnregistrement(EnregistrementFiscal $enregistrement, array $colonnes): void
    {
        DB::unprepared('DROP TRIGGER enregistrements_fiscaux_ajout_seul_update');
        DB::table('enregistrements_fiscaux')->where('magasin_id', $enregistrement->magasin_id)->where('id', $enregistrement->id)->update($colonnes);
    }

    /**
     * Ce que seul pourrait faire le détenteur de la clé de l'éditeur :
     * réécrire un enregistrement et le signer de nouveau avec la clé
     * épinglée.
     *
     * @param  Closure(string): string  $reecrire  le nouveau contenu, depuis l'ancien
     * @param  array<string, mixed>  $colonnes
     */
    private function reecrireEtResigner(EnregistrementFiscal $enregistrement, Closure $reecrire, array $colonnes = []): void
    {
        $contenu = $reecrire($enregistrement->contenu);
        openssl_sign($contenu, $signature, base64_decode(config('caisse_securisee.cle_privee')), OPENSSL_ALGO_SHA256);

        $this->falsifierLEnregistrement($enregistrement, ['contenu' => $contenu, 'signature' => self::base64url($signature), ...$colonnes]);
    }

    private static function base64url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    public function test_un_registre_intact_ne_presente_aucune_anomalie(): void
    {
        $this->magasinActif();
        $this->activite();

        $rapport = $this->verifier();

        $this->assertSame([], $this->anomalies($rapport));
        $this->assertTrue($rapport->complet);
        $this->assertSame(['vente' => 5, 'encaissement' => 3, 'evenement' => 2], $rapport->enregistrementsVerifies());
        $this->assertSame([
            'vente' => ['sequence' => 5, 'signature' => $this->enregistrement(ChaineFiscale::Vente, 5)->signature],
            'encaissement' => ['sequence' => 3, 'signature' => $this->enregistrement(ChaineFiscale::Encaissement, 3)->signature],
            'evenement' => ['sequence' => 2, 'signature' => $this->enregistrement(ChaineFiscale::Evenement, 2)->signature],
        ], $rapport->pointsDeReprise());
    }

    public function test_une_ligne_de_ticket_modifiee_en_base_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        $ligne = $documents['ticket']->lignes->first();

        DB::table('lignes_facture')->where('magasin_id', $magasin->id)->where('id', $ligne->id)->update(['montant_ttc' => '1.00']);

        $this->assertSame([
            [90, 'Le ticket TK-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (lignes, ventilation).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * La fiche du client change après l'émission : ses documents gardent la
     * copie signée, et la vérification ne voit rien à redire.
     */
    public function test_une_fiche_client_modifiee_apres_l_emission_ne_presente_aucune_anomalie(): void
    {
        $this->magasinActif();
        $documents = $this->activite();

        $documents['facture']->client->update(['nom' => 'Nouveau nom', 'type_client' => TypeClient::Particulier]);
        $documents['ticket']->client->update(['nom' => 'Autre nom', 'type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $this->assertSame([], $this->anomalies($this->verifier()));
    }

    public function test_un_client_fige_modifie_en_base_est_detecte(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['facture']->id)->update(['client_nom' => 'Nom réécrit']);

        $this->assertSame([
            [90, 'La facture FAC-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (client).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * La franchise de TVA d'un document se signe avec lui : réécrite en
     * base, elle ferait imprimer ou retirer la mention « TVA non
     * applicable » d'un document émis.
     */
    public function test_une_franchise_de_tva_reecrite_en_base_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['facture']->id)->update(['franchise_en_base' => true]);

        $this->assertSame([
            [90, 'La facture FAC-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (franchise_en_base).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Sous MagasinScope, un client_id réécrit vers la fiche d'un autre
     * magasin ne désigne plus aucun client : la facture et l'avoir sont
     * signalés, et la vérification va jusqu'au bout.
     */
    public function test_un_client_d_un_autre_magasin_reecrit_en_base_est_detecte_sans_interrompre_la_verification(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        $clientDAilleurs = app(TenantContext::class)->runAs(Magasin::factory()->create(['code' => 'AUTRE']), fn (): Client => Client::factory()->professionnel()->create());

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['facture']->id)->update(['client_id' => $clientDAilleurs->id]);
        DB::table('avoirs')->where('magasin_id', $magasin->id)->where('id', $documents['avoirSurFacture']->id)->update(['client_id' => $clientDAilleurs->id]);

        $this->assertSame([
            [90, 'La facture FAC-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (client).'],
            [90, 'L\'avoir AV-LYON1-2026-000002 ne correspond plus à ce qui a été signé à son émission (client).'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_date_d_emission_effacee_en_base_est_detectee_sans_interrompre_la_verification(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['facture']->id)->update(['date_emission' => null]);

        $this->assertSame([
            [90, 'La facture FAC-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (date_emission).'],
            [90, 'La facture FAC-LYON1-2026-000001 porte la date d\'émission illisible, qui n\'est pas l\'instant de sa signature (09/10/2026 à 10:00:00).'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_date_d_emission_modifiee_en_base_n_est_plus_l_instant_de_la_signature(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['facture']->id)->update(['date_emission' => '2026-10-09 08:00:01']);

        $this->assertSame([
            [90, 'La facture FAC-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (date_emission).'],
            [90, 'La facture FAC-LYON1-2026-000001 porte la date d\'émission du 09/10/2026 à 10:00:01, qui n\'est pas l\'instant de sa signature (09/10/2026 à 10:00:00).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * L'enregistrement de l'avoir disparu, la chaîne des ventes saute du
     * n° 1 au n° 3, et l'avoir n'a plus d'enregistrement.
     */
    public function test_un_enregistrement_supprime_rompt_la_sequence_de_sa_chaine(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        $enregistrementDeLAvoir = $this->enregistrementDe($documents['avoir'], ChaineFiscale::Vente);
        DB::unprepared('DROP TRIGGER enregistrements_fiscaux_ajout_seul_delete');

        DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('id', $enregistrementDeLAvoir->id)->delete();

        $this->assertSame([
            [95, 'Rupture de séquence de la chaîne des ventes : l\'enregistrement n° 2 manque avant l\'enregistrement n° 3.'],
            [90, 'L\'avoir AV-LYON1-2026-000001 n\'a aucun enregistrement de la chaîne des ventes.'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_un_contenu_signe_modifie_ne_se_verifie_plus_avec_sa_signature(): void
    {
        $this->magasinActif();
        $documents = $this->activite();
        $encaissement = $this->enregistrementDe($documents['ticket']->allocationsPaiement->sole()->paiement, ChaineFiscale::Encaissement);

        $this->falsifierLEnregistrement($encaissement, ['contenu' => str_replace('"mode":"carte_bancaire"', '"mode":"especes"', $encaissement->contenu)]);

        $this->assertSame([
            [90, 'Enregistrement n° 1 de la chaîne des encaissements : sa signature ne correspond pas à son contenu signé.'],
            [90, "Le paiement (identifiant interne {$encaissement->document_id}) ne correspond plus à ce qui a été signé à son enregistrement (mode)."],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Une signature valide pour une autre clé que les clés épinglées ne
     * prouve rien : celui qui l'a produite n'est pas l'éditeur.
     */
    public function test_un_enregistrement_re_signe_avec_une_cle_non_epinglee_est_rejete(): void
    {
        $this->magasinActif();
        $documents = $this->activite();
        $vente = $this->enregistrementDe($documents['ticket'], ChaineFiscale::Vente);
        $paireDuFaussaire = SignataireFiscal::genererUnePaire();
        $contenu = str_replace($vente->cle_id, $paireDuFaussaire['cle_id'], $vente->contenu);
        openssl_sign($contenu, $signature, $paireDuFaussaire['cle_privee'], OPENSSL_ALGO_SHA256);

        $this->falsifierLEnregistrement($vente, ['contenu' => $contenu, 'signature' => self::base64url($signature), 'cle_id' => $paireDuFaussaire['cle_id']]);

        $this->assertSame([
            [90, "Enregistrement n° 1 de la chaîne des ventes : signé avec une clé qui n'est pas épinglée ({$paireDuFaussaire['cle_id']})."],
            [90, 'Enregistrement n° 2 de la chaîne des ventes : sa signature précédente n\'est pas la signature de l\'enregistrement n° 1.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Même signés par la clé de l'éditeur, des grands totaux qui
     * n'avancent pas du montant de la vente se voient, sur cette vente et
     * sur la suivante, qui les prolonge.
     */
    public function test_des_grands_totaux_qui_n_avancent_pas_du_montant_de_la_vente_sont_detectes(): void
    {
        $this->magasinActif();
        $documents = $this->activite();
        $vente = $this->enregistrementDe($documents['ticketAvecCredit'], ChaineFiscale::Vente);

        $this->reecrireEtResigner($vente, static fn (string $contenu): string => str_replace('"perpetuel_reel":4150', '"perpetuel_reel":5150', $contenu));

        $this->assertSame([
            [90, 'Enregistrement n° 3 de la chaîne des ventes : grands totaux perpétuels de 51,50 € (réel) et 65,50 € (absolu), au lieu de 41,50 € et 65,50 €.'],
            [90, 'Enregistrement n° 4 de la chaîne des ventes : sa signature précédente n\'est pas la signature de l\'enregistrement n° 3.'],
            [90, 'Enregistrement n° 4 de la chaîne des ventes : grands totaux perpétuels de 101,50 € (réel) et 125,50 € (absolu), au lieu de 111,50 € et 125,50 €.'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_colonne_qui_ne_correspond_plus_a_l_en_tete_signe_est_detectee(): void
    {
        $this->magasinActif();
        $this->activite();
        $initialisation = $this->enregistrement(ChaineFiscale::Evenement, 1);
        DB::unprepared('DROP TRIGGER enregistrements_fiscaux_ajout_seul_update');

        DB::table('enregistrements_fiscaux')->where('magasin_id', $initialisation->magasin_id)->where('id', $initialisation->id)->update(['horodatage' => '2026-10-09 07:00:00']);

        $this->assertSame([
            [90, 'Enregistrement n° 1 du journal des événements : l\'en-tête signé diffère de ses colonnes (horodatage).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Même signé par la clé de l'éditeur, un enregistrement horodaté avant
     * le précédent de sa chaîne est suspect : l'horloge du registre ne
     * recule jamais.
     */
    public function test_un_horodatage_anterieur_a_l_enregistrement_precedent_est_detecte(): void
    {
        $this->magasinActif();
        $this->travelTo('2026-10-09 10:00:00');
        DB::transaction(fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $this->travelTo('2026-10-09 11:00:00');
        DB::transaction(fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        DB::transaction(fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $deuxiemeExport = $this->enregistrement(ChaineFiscale::Evenement, 3);

        $this->reecrireEtResigner(
            $deuxiemeExport,
            static fn (string $contenu): string => str_replace('"horodatage":"20261009090000Z"', '"horodatage":"20261009075959Z"', $contenu),
            ['horodatage' => '2026-10-09 07:59:59'],
        );

        $this->assertSame([
            [90, 'Enregistrement n° 3 du journal des événements : horodaté le 09/10/2026 à 09:59:59, avant l\'enregistrement précédent (09/10/2026 à 10:00:00).'],
            [90, 'Enregistrement n° 4 du journal des événements : sa signature précédente n\'est pas la signature de l\'enregistrement n° 3.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Un simple INSERT, sans retirer aucun déclencheur, glisse un faux
     * événement avant le premier de sa chaîne, en tête de l'export du
     * journal : la vérification le relit, complète comme reprise, et le
     * signale comme une rupture de séquence.
     */
    public function test_un_enregistrement_insere_avant_le_premier_de_sa_chaine_est_releve(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        $initialisation = (array) DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('chaine', 'evenement')->where('sequence', 1)->first();

        DB::table('enregistrements_fiscaux')->insert([...Arr::except($initialisation, 'id'), 'sequence' => 0, 'type' => '280', 'contenu' => 'faux', 'signature' => 'faux']);

        $anomalies = [
            [95, 'Rupture de séquence du journal des événements : l\'enregistrement n° 0 est hors de la séquence de la chaîne, qui commence au n° 1.'],
            [90, 'Enregistrement n° 0 du journal des événements : sa signature ne correspond pas à son contenu signé.'],
            [90, 'Enregistrement n° 0 du journal des événements : son contenu signé n\'est pas un JSON lisible.'],
        ];
        $this->assertSame($anomalies, $this->anomalies($this->verifier()));
        $this->assertSame($anomalies, $this->anomalies($this->verifier($pointsDeReprise)));
    }

    public function test_un_enregistrement_dans_une_chaine_inconnue_est_detecte(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $modele = (array) DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->orderBy('id')->first();

        DB::table('enregistrements_fiscaux')->insert([...Arr::except($modele, 'id'), 'chaine' => 'remises', 'sequence' => 1]);

        $this->assertSame([
            [90, '1 enregistrement(s) dans une chaîne inconnue du registre (« remises »).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Un ticket enregistré comme une facture, même par un enregistrement
     * signé de la clé de l'éditeur, ne correspond pas à sa série.
     */
    public function test_un_ticket_enregistre_comme_une_facture_est_detecte(): void
    {
        $this->magasinActif();
        $documents = $this->activite();
        $vente = $this->enregistrementDe($documents['ticket'], ChaineFiscale::Vente);

        $this->reecrireEtResigner($vente, static fn (string $contenu): string => str_replace('"type":"ticket"', '"type":"facture"', $contenu), ['type' => 'facture']);

        $this->assertSame([
            [90, 'Enregistrement n° 2 de la chaîne des ventes : sa signature précédente n\'est pas la signature de l\'enregistrement n° 1.'],
            [90, 'Le ticket TK-LYON1-2026-000001 est enregistré comme « facture » alors que son numéro le place dans la série des tickets.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Chaque falsification reçoit les chaînes que note la ligne de tête et
     * rend les colonnes qu'elle réécrit.
     *
     * @return array<string, array{0: Closure(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function falsificationsDeLaTete(): array
    {
        return [
            'séquence d\'une chaîne' => [
                static function (array $chaines): array {
                    $chaines['vente']['sequence'] = 4;

                    return ['chaines' => json_encode($chaines)];
                },
                'La ligne de tête de la chaîne des ventes note la séquence 4, alors que son dernier enregistrement est le n° 5.',
            ],
            'chaîne oubliée' => [
                static fn (array $chaines): array => ['chaines' => json_encode(Arr::except($chaines, 'encaissement'))],
                'La ligne de tête de la chaîne des encaissements ne note rien, alors que son dernier enregistrement est le n° 3.',
            ],
            'chaîne sans enregistrement' => [
                static fn (array $chaines): array => ['chaines' => json_encode([...$chaines, 'remises' => ['sequence' => 1, 'signature' => 'x']])],
                'La ligne de tête note une chaîne « remises » qui n\'a aucun enregistrement.',
            ],
            'grands totaux' => [
                static function (array $chaines): array {
                    $chaines['vente']['gt_reel'] = 1;

                    return ['chaines' => json_encode($chaines)];
                },
                'La ligne de tête de la chaîne des ventes ne note pas les grands totaux perpétuels de son dernier enregistrement.',
            ],
            'version du module' => [
                static fn (array $chaines): array => ['version_module' => '0.9'],
                'La ligne de tête ne note pas la version du module et la clé du dernier enregistrement du registre.',
            ],
            'dernier verrou' => [
                static fn (array $chaines): array => ['dernier_horodatage' => '2026-10-09 07:59:59'],
                'La ligne de tête date le dernier verrou du registre du 09/10/2026 à 09:59:59, avant son enregistrement le plus récent (09/10/2026 à 10:00:00).',
            ],
        ];
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $falsification
     */
    #[DataProvider('falsificationsDeLaTete')]
    public function test_une_ligne_de_tete_qui_ne_note_plus_la_suite_du_registre_est_detectee(Closure $falsification, string $anomalie): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $chaines = json_decode(DB::table('tetes_registre_fiscal')->where('magasin_id', $magasin->id)->value('chaines'), true);

        DB::table('tetes_registre_fiscal')->where('magasin_id', $magasin->id)->update($falsification($chaines));

        $this->assertSame([[90, $anomalie]], $this->anomalies($this->verifier()));
    }

    public function test_un_registre_sans_ligne_de_tete_est_detecte(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();

        DB::table('tetes_registre_fiscal')->where('magasin_id', $magasin->id)->delete();

        $this->assertSame([
            [90, 'Le registre a des enregistrements, mais aucune ligne de tête ne note leur suite.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * L'enregistrement d'un paiement qui désigne un autre document : son
     * en-tête ne correspond plus, il désigne un paiement introuvable, et
     * le paiement qu'il enregistrait, comme son imputation, n'est plus
     * signé nulle part.
     */
    public function test_un_enregistrement_qui_designe_un_document_introuvable_est_detecte(): void
    {
        $this->magasinActif();
        $documents = $this->activite();
        $paiement = $documents['ticket']->allocationsPaiement->sole()->paiement;

        $this->falsifierLEnregistrement($this->enregistrementDe($paiement, ChaineFiscale::Encaissement), ['document_id' => 999999]);

        $this->assertSame([
            [90, 'Enregistrement n° 1 de la chaîne des encaissements : l\'en-tête signé diffère de ses colonnes (document).'],
            [90, "Le paiement (identifiant interne {$paiement->id}) n'a aucun enregistrement de la chaîne des encaissements."],
            [90, 'Enregistrement n° 1 de la chaîne des encaissements : il désigne un paiement introuvable (identifiant interne 999999).'],
            [90, "L'imputation du paiement (identifiant interne {$paiement->id}) sur le ticket TK-LYON1-2026-000001 (29,50 €) ne figure dans aucun contenu signé."],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function enregistrementsQuiNeDesignentPasLeurDocument(): array
    {
        return [
            'type inconnu de la chaîne' => [['type' => 'remise'], 'type', 'Enregistrement n° 1 de la chaîne des ventes : son type « remise » n\'est pas celui d\'un document que sa chaîne enregistre.'],
            'document d\'une autre nature' => [['document_type' => 'paiement'], 'document', 'Enregistrement n° 1 de la chaîne des ventes : il ne désigne pas le document qu\'il enregistre.'],
        ];
    }

    /**
     * @param  array<string, string>  $colonnes
     */
    #[DataProvider('enregistrementsQuiNeDesignentPasLeurDocument')]
    public function test_un_enregistrement_de_vente_qui_ne_designe_pas_un_document_de_son_type_est_detecte(array $colonnes, string $ecart, string $anomalie): void
    {
        $this->magasinActif();
        $documents = $this->activite();

        $this->falsifierLEnregistrement($this->enregistrementDe($documents['ticket'], ChaineFiscale::Vente), $colonnes);

        $this->assertSame([
            [90, "Enregistrement n° 1 de la chaîne des ventes : l'en-tête signé diffère de ses colonnes ({$ecart})."],
            [90, $anomalie],
            [90, 'Le ticket TK-LYON1-2026-000001 n\'a aucun enregistrement de la chaîne des ventes.'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_un_contenu_signe_qui_n_est_pas_du_json_est_detecte(): void
    {
        $this->magasinActif();
        $this->activite();

        $this->falsifierLEnregistrement($this->enregistrement(ChaineFiscale::Evenement, 2), ['contenu' => 'contenu effacé']);

        $this->assertSame([
            [90, 'Enregistrement n° 2 du journal des événements : sa signature ne correspond pas à son contenu signé.'],
            [90, 'Enregistrement n° 2 du journal des événements : son contenu signé n\'est pas un JSON lisible.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * @return array<string, array{0: Closure(string): string, 1: list<string>}>
     */
    public static function grandsTotauxIncoherents(): array
    {
        return [
            'grands totaux retirés' => [
                static fn (string $contenu): string => preg_replace('/,"gt":\{[^}]*\}/', '', $contenu),
                [
                    'Enregistrement n° 3 de la chaîne des ventes : l\'en-tête signé diffère de ses colonnes (structure).',
                    'Enregistrement n° 3 de la chaîne des ventes : ses grands totaux signés sont illisibles.',
                ],
            ],
            'montant qui n\'est pas le total du document' => [
                static fn (string $contenu): string => str_replace('"montant":2400', '"montant":2500', $contenu),
                [
                    'Enregistrement n° 3 de la chaîne des ventes : le montant qui fait avancer les grands totaux (25,00 €) n\'est pas le total TTC signé de son document (24,00 €).',
                    'Enregistrement n° 3 de la chaîne des ventes : grands totaux perpétuels de 41,50 € (réel) et 65,50 € (absolu), au lieu de 42,50 € et 66,50 €.',
                ],
            ],
        ];
    }

    /**
     * @param  Closure(string): string  $reecrire
     * @param  list<string>  $anomaliesDeLaVente
     */
    #[DataProvider('grandsTotauxIncoherents')]
    public function test_des_grands_totaux_illisibles_ou_d_un_autre_montant_sont_detectes(Closure $reecrire, array $anomaliesDeLaVente): void
    {
        $this->magasinActif();
        $documents = $this->activite();

        $this->reecrireEtResigner($this->enregistrementDe($documents['ticketAvecCredit'], ChaineFiscale::Vente), $reecrire);

        $this->assertSame([
            ...array_map(static fn (string $anomalie): array => [90, $anomalie], $anomaliesDeLaVente),
            [90, 'Enregistrement n° 4 de la chaîne des ventes : sa signature précédente n\'est pas la signature de l\'enregistrement n° 3.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Un brouillon numéroté directement en base n'a pas d'enregistrement,
     * et son numéro n'a jamais été attribué par la série des tickets.
     */
    public function test_un_document_numerote_sans_enregistrement_est_detecte(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit('THE', 8)->id, 'quantite' => 1]]);

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-LYON1-2026-000003', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);

        $this->assertSame([
            [90, 'Le ticket TK-LYON1-2026-000003 n\'a aucun enregistrement de la chaîne des ventes.'],
            [95, 'Série TK-LYON1-2026- : TK-LYON1-2026-000003 hors des numéros qu\'elle a attribués (de TK-LYON1-2026-000001 à TK-LYON1-2026-000002).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Le document de activite() dont l'enregistrement est copié, sa chaîne,
     * la séquence de la copie, en fin de chaîne, et l'anomalie attendue
     * (%d : l'identifiant du document).
     *
     * @return array<string, array{0: string, 1: ChaineFiscale, 2: int, 3: string}>
     */
    public static function documentsEnregistresDeuxFois(): array
    {
        return [
            'ticket' => ['ticket', ChaineFiscale::Vente, 6, 'Le ticket TK-LYON1-2026-000001 a 2 enregistrements de la chaîne des ventes (séquences 1, 6) au lieu d\'un seul.'],
            'avoir' => ['avoir', ChaineFiscale::Vente, 6, 'L\'avoir AV-LYON1-2026-000001 a 2 enregistrements de la chaîne des ventes (séquences 2, 6) au lieu d\'un seul.'],
            'paiement' => ['virement', ChaineFiscale::Encaissement, 4, 'Le paiement (identifiant interne %d) a 2 enregistrements de la chaîne des encaissements (séquences 3, 4) au lieu d\'un seul.'],
        ];
    }

    /**
     * Une copie de l'enregistrement d'un document, ajoutée en fin de sa
     * chaîne : le document a deux enregistrements.
     */
    #[DataProvider('documentsEnregistresDeuxFois')]
    public function test_un_document_enregistre_deux_fois_est_detecte(string $document, ChaineFiscale $chaine, int $sequenceDeLaCopie, string $anomalie): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        $enregistrement = (array) DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('id', $this->enregistrementDe($documents[$document], $chaine)->id)->first();

        DB::table('enregistrements_fiscaux')->insert([...Arr::except($enregistrement, 'id'), 'sequence' => $sequenceDeLaCopie]);

        $this->assertContains([90, sprintf($anomalie, $documents[$document]->getKey())], $this->anomalies($this->verifier()));
    }

    /**
     * Le second ticket renuméroté TK-LYON1-2026-000025, dernier numéro de la
     * série : les vingt-trois numéros qui le précèdent manquent, le message
     * énumère les vingt premiers.
     */
    public function test_les_numeros_manquants_au_milieu_d_une_serie_sont_decomptes_et_enumeres(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['ticketAvecCredit']->id)->update(['numero' => 'TK-LYON1-2026-000025']);

        DB::table('sequences_documents')->where('magasin_id', $magasin->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 26]);

        $enumeres = implode(', ', array_map(static fn (int $numero): string => sprintf('TK-LYON1-2026-%06d', $numero), range(2, 21)));
        $this->assertContains([95, "Série TK-LYON1-2026- : 23 numéro(s) attribué(s) sans document : {$enumeres}…."], $this->anomalies($this->verifier()));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function numerosHorsSerie(): array
    {
        return [
            'série inconnue' => ['RC-2026-000001', 'Le numéro RC-2026-000001 n\'appartient à aucune série de numérotation du magasin.'],
            'écriture d\'une autre série' => ['TK-LYON1-2026-03', 'Le numéro TK-LYON1-2026-03 n\'est pas écrit comme sa série écrit ses numéros (TK-LYON1-2026-000003).'],
        ];
    }

    #[DataProvider('numerosHorsSerie')]
    public function test_un_numero_qu_aucune_serie_n_a_attribue_rompt_la_numerotation(string $numero, string $anomalie): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit('THE', 8)->id, 'quantite' => 1]]);

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => $numero, 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);

        $this->assertSame([95, $anomalie], $this->anomalies($this->verifier())[1]);
    }

    /**
     * Un numéro attribué par la série sans document qui le porte : un
     * ticket disparu, même le dernier.
     */
    public function test_un_numero_de_ticket_manquant_rompt_la_numerotation(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();

        DB::table('sequences_documents')->where('magasin_id', $magasin->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 4]);

        $this->assertSame([
            [95, 'Série TK-LYON1-2026- : 1 numéro(s) attribué(s) sans document : TK-LYON1-2026-000003.'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_imputation_qu_aucun_contenu_signe_ne_porte_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('avoir_allocations')->insert([
            'magasin_id' => $magasin->id,
            'avoir_id' => $documents['avoir']->id,
            'facture_id' => $documents['facture']->id,
            'montant_alloue' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            [90, 'L\'imputation de l\'avoir AV-LYON1-2026-000001 sur la facture FAC-LYON1-2026-000001 (1,00 €) ne figure dans aucun contenu signé.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Une imputation ajoutée en base à un paiement change ce qu'il règle :
     * son enregistrement ne le signe pas.
     */
    public function test_une_imputation_ajoutee_a_un_paiement_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('paiement_allocations')->insert([
            'magasin_id' => $magasin->id,
            'paiement_id' => $documents['virement']->id,
            'facture_id' => $documents['ticket']->id,
            'montant_alloue' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            [90, "Le paiement (identifiant interne {$documents['virement']->id}) ne correspond plus à ce qui a été signé à son enregistrement (allocations)."],
            [90, "L'imputation du paiement (identifiant interne {$documents['virement']->id}) sur le ticket TK-LYON1-2026-000001 (1,00 €) ne figure dans aucun contenu signé."],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_imputation_au_montant_illisible_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('avoir_allocations')->insert([
            'magasin_id' => $magasin->id,
            'avoir_id' => $documents['avoirSurFacture']->id,
            'facture_id' => $documents['ticket']->id,
            'montant_alloue' => 'douze euros',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            [90, 'L\'imputation de l\'avoir AV-LYON1-2026-000002 sur le ticket TK-LYON1-2026-000001 (montant illisible) ne figure dans aucun contenu signé.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Même signé par la clé de l'éditeur, un crédit d'avoir signé pour un
     * autre montant que celui de l'imputation en base est relevé.
     */
    public function test_une_imputation_signee_pour_un_autre_montant_est_detectee(): void
    {
        $this->magasinActif();
        $documents = $this->activite();

        $this->reecrireEtResigner(
            $this->enregistrementDe($documents['ticketAvecCredit'], ChaineFiscale::Vente),
            static fn (string $contenu): string => str_replace('"numero":"AV-LYON1-2026-000001","montant":1200', '"numero":"AV-LYON1-2026-000001","montant":1100', $contenu),
        );

        $this->assertSame([
            [90, 'Enregistrement n° 4 de la chaîne des ventes : sa signature précédente n\'est pas la signature de l\'enregistrement n° 3.'],
            [90, 'L\'imputation de l\'avoir AV-LYON1-2026-000001 sur le ticket TK-LYON1-2026-000002 : 12,00 € en base, 11,00 € dans son contenu signé.'],
        ], $this->anomalies($this->verifier()));
    }

    public function test_une_imputation_signee_disparue_de_la_base_est_detectee(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        DB::unprepared('DROP TRIGGER avoir_allocations_ajout_seul_delete');

        DB::table('avoir_allocations')->where('magasin_id', $magasin->id)->where('avoir_id', $documents['avoir']->id)->delete();

        $this->assertSame([
            [90, 'L\'imputation de l\'avoir AV-LYON1-2026-000001 sur le ticket TK-LYON1-2026-000002, signée, est introuvable en base.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Un ticket du 9 octobre 2026 dont la date d'émission recule au
     * 31 décembre 2025 n'est plus dans l'année de son numéro.
     */
    public function test_un_numero_hors_de_l_annee_d_emission_de_son_document_est_detecte(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $documents['ticket']->id)->update(['date_emission' => '2025-12-31 12:00:00']);

        $this->assertSame([
            [90, 'Le ticket TK-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (date_emission).'],
            [90, 'Le ticket TK-LYON1-2026-000001 porte la date d\'émission du 31/12/2025 à 13:00:00, qui n\'est pas l\'instant de sa signature (09/10/2026 à 10:00:00).'],
            [90, 'Le ticket TK-LYON1-2026-000001 est numéroté dans l\'année 2026 mais émis le 31/12/2025 à 13:00:00.'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * La reprise ne vérifie que ce qui s'est inscrit après les points de
     * reprise : une modification plus ancienne attend la vérification
     * complète suivante, qui la voit.
     */
    public function test_la_reprise_ne_verifie_que_les_enregistrements_inscrits_depuis_ses_points_de_reprise(): void
    {
        $magasin = $this->magasinActif();
        $documents = $this->activite();
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        DB::table('lignes_facture')->where('magasin_id', $magasin->id)->where('id', $documents['ticket']->lignes->first()->id)->update(['montant_ttc' => '1.00']);
        $this->encaisser(Client::factory()->create(), [[$this->produit('THE', 8), 1]], ModePaiement::Especes);

        $reprise = $this->verifier($pointsDeReprise);

        $this->assertSame([], $this->anomalies($reprise));
        $this->assertFalse($reprise->complet);
        $this->assertSame(['vente' => 1, 'encaissement' => 1, 'evenement' => 0], $reprise->enregistrementsVerifies());
        $this->assertSame(
            [6, 4, 2],
            array_values(Arr::map($reprise->pointsDeReprise(), static fn (array $pointDeReprise): int => $pointDeReprise['sequence'])),
        );
        $this->assertSame([
            [90, 'Le ticket TK-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (lignes, ventilation).'],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Le pic de mémoire d'une reprise sans nouvel enregistrement, en octets,
     * après une vérification complète qui en donne les points de reprise.
     */
    private function memoireDUneRepriseSansNouvelEnregistrement(): int
    {
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        memory_reset_peak_usage();
        $avant = memory_get_usage();

        $this->verifier($pointsDeReprise);

        return memory_get_peak_usage() - $avant;
    }

    /**
     * Les contrôles qui portent chaque nuit sur tout le registre (ligne de
     * tête, correspondance entre documents et enregistrements,
     * numérotation) se font en base ou en flux : cent cinquante ventes de
     * plus ne pèsent presque rien sur la mémoire d'une reprise.
     */
    public function test_la_memoire_d_une_reprise_ne_grandit_pas_avec_le_registre(): void
    {
        $this->magasinActif();
        $client = Client::factory()->create();
        $the = $this->produit('THE', 8);
        $vendre = fn (int $nombre) => collect(range(1, $nombre))->each(fn () => $this->encaisser($client, [[$the, 1]], ModePaiement::Especes));
        $vendre(10);
        $memoireAvecDixVentes = $this->memoireDUneRepriseSansNouvelEnregistrement();

        $vendre(150);

        $this->assertLessThan(200_000, $this->memoireDUneRepriseSansNouvelEnregistrement() - $memoireAvecDixVentes);
    }

    public function test_un_point_de_reprise_disparu_depuis_la_verification_precedente_rompt_la_sequence(): void
    {
        $magasin = $this->magasinActif();
        $this->activite();
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        $this->encaisser(Client::factory()->create(), [[$this->produit('THE', 8), 1]], ModePaiement::Especes);
        DB::unprepared('DROP TRIGGER enregistrements_fiscaux_ajout_seul_delete');

        DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('id', $this->enregistrement(ChaineFiscale::Vente, 5)->id)->delete();

        $this->assertSame([
            [95, 'L\'enregistrement n° 5 de la chaîne des ventes, dernier vérifié par la vérification précédente, a disparu.'],
            [90, 'L\'avoir AV-LYON1-2026-000002 n\'a aucun enregistrement de la chaîne des ventes.'],
        ], $this->anomalies($this->verifier($pointsDeReprise)));
    }

    public function test_un_point_de_reprise_modifie_depuis_la_verification_precedente_est_detecte(): void
    {
        $this->magasinActif();
        $this->activite();
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        $dernierVerifie = $this->enregistrement(ChaineFiscale::Vente, 5);

        $this->falsifierLEnregistrement($dernierVerifie, ['contenu' => str_replace('"motif":null', '"motif":"Geste commercial"', $dernierVerifie->contenu)]);

        $this->assertSame([
            [90, 'L\'enregistrement n° 5 de la chaîne des ventes a changé depuis la vérification précédente : il n\'est plus celui qu\'elle a vérifié.'],
        ], $this->anomalies($this->verifier($pointsDeReprise)));
    }

    /**
     * Un enregistrement dont la signature ne se vérifie pas n'est jamais un
     * point de reprise : la reprise suivante le relit, et signale de
     * nouveau ce qu'il a, sans prétendre qu'il a changé depuis.
     */
    public function test_un_enregistrement_a_la_signature_invalide_n_est_jamais_un_point_de_reprise(): void
    {
        $this->magasinActif();
        $this->activite();
        $dernier = $this->enregistrement(ChaineFiscale::Vente, 5);
        $this->falsifierLEnregistrement($dernier, ['contenu' => str_replace('"motif":null', '"motif":"Geste commercial"', $dernier->contenu)]);
        $anomalies = [
            [90, 'Enregistrement n° 5 de la chaîne des ventes : sa signature ne correspond pas à son contenu signé.'],
            [90, 'L\'avoir AV-LYON1-2026-000002 ne correspond plus à ce qui a été signé à son émission (motif).'],
        ];

        $complete = $this->verifier();
        $reprise = $this->verifier($complete->pointsDeReprise());

        $this->assertSame($anomalies, $this->anomalies($complete));
        $this->assertSame(['sequence' => 4, 'signature' => $this->enregistrement(ChaineFiscale::Vente, 4)->signature], $complete->pointsDeReprise()['vente']);
        $this->assertSame($anomalies, $this->anomalies($reprise));
        $this->assertSame(['vente' => 1, 'encaissement' => 0, 'evenement' => 0], $reprise->enregistrementsVerifies());
    }

    /**
     * Une clé retirée du dépôt depuis la vérification précédente : les
     * points de reprise qu'elle a signés n'ont pas changé, c'est le dépôt
     * qui a changé, et la reprise le dit. Ils ne prouvent plus rien et ne
     * sont plus des points de reprise : la vérification suivante relira
     * tout.
     */
    public function test_un_point_de_reprise_dont_la_cle_n_est_plus_epinglee_est_signale_sans_etre_dit_modifie(): void
    {
        $this->magasinActif();
        $this->activite();
        $pointsDeReprise = $this->verifier()->pointsDeReprise();
        $ancienneCle = $this->enregistrement(ChaineFiscale::Vente, 5)->cle_id;
        $nouvellePaire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($nouvellePaire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['nouvelle.pem' => $nouvellePaire['cle_publique']],
        ]);

        $reprise = $this->verifier($pointsDeReprise);

        $this->assertSame([
            [90, "L'enregistrement n° 5 de la chaîne des ventes, dernier vérifié par la vérification précédente, est signé avec une clé qui n'est pas épinglée ({$ancienneCle})."],
            [90, "L'enregistrement n° 3 de la chaîne des encaissements, dernier vérifié par la vérification précédente, est signé avec une clé qui n'est pas épinglée ({$ancienneCle})."],
            [90, "L'enregistrement n° 2 du journal des événements, dernier vérifié par la vérification précédente, est signé avec une clé qui n'est pas épinglée ({$ancienneCle})."],
        ], $this->anomalies($reprise));
        $this->assertSame([], $reprise->pointsDeReprise());
    }

    /**
     * L'enregistrement du ticket de A désigne désormais le ticket de B, et
     * celui de B le ticket de A : la vérification de A ne prend jamais le
     * document ni l'enregistrement de B pour le sien.
     */
    public function test_un_enregistrement_et_un_document_de_deux_magasins_ne_se_correspondent_jamais(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasinB = $this->magasinActif('LYON2');
        $ticketDeB = $this->encaisser(Client::factory()->create(), [[$this->produit('THE', 8), 1]], ModePaiement::Especes);
        $enregistrementDeB = $this->enregistrementDe($ticketDeB, ChaineFiscale::Vente);
        $magasinA = $this->magasinActif();
        $ticketDeA = $this->encaisser(Client::factory()->create(), [[$this->produit('THE', 8), 1]], ModePaiement::Especes);
        $enregistrementDeA = $this->enregistrementDe($ticketDeA, ChaineFiscale::Vente);
        DB::unprepared('DROP TRIGGER enregistrements_fiscaux_ajout_seul_update');

        DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinB->id)->where('id', $enregistrementDeB->id)->update(['document_id' => $ticketDeA->id]);
        DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinA->id)->where('id', $enregistrementDeA->id)->update(['document_id' => $ticketDeB->id]);

        $this->assertSame([
            [90, 'Enregistrement n° 1 de la chaîne des ventes : l\'en-tête signé diffère de ses colonnes (document).'],
            [90, 'Le ticket TK-LYON1-2026-000001 n\'a aucun enregistrement de la chaîne des ventes.'],
            [90, "Enregistrement n° 1 de la chaîne des ventes : il désigne une facture introuvable ou jamais numérotée (identifiant interne {$ticketDeB->id})."],
        ], $this->anomalies($this->verifier()));
    }

    /**
     * Le registre du magasin B, falsifié, n'entre jamais dans la
     * vérification du magasin A, qui ne vérifie que ses propres
     * enregistrements.
     */
    public function test_la_verification_d_un_magasin_n_inspecte_jamais_le_registre_d_un_autre(): void
    {
        $magasinB = $this->magasinActif('LYON2');
        $documentsDeB = $this->activite();
        DB::table('lignes_facture')->where('magasin_id', $magasinB->id)->where('id', $documentsDeB['ticket']->lignes->first()->id)->update(['montant_ttc' => '1.00']);
        DB::table('sequences_documents')->where('magasin_id', $magasinB->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 9]);
        $magasinA = $this->magasinActif();
        $this->encaisser(Client::factory()->create(), [[$this->produit('THE', 8), 1]], ModePaiement::Especes);

        $rapport = app(VerificateurIntegrite::class)->verifier($magasinA);

        $this->assertSame([], $this->anomalies($rapport));
        $this->assertSame(['vente' => 1, 'encaissement' => 1, 'evenement' => 1], $rapport->enregistrementsVerifies());
        $this->assertThrows(
            fn () => app(VerificateurIntegrite::class)->verifier($magasinB),
            LogicException::class,
            'La vérification de l\'intégrité ne porte que sur le registre du magasin actif.',
        );
    }
}
