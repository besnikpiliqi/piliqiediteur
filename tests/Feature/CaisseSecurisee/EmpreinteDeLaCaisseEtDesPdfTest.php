<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Services\FicheDeSaisiePdfGenerator;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\ClotureCaissePdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\RecuPaiementPdfGenerator;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Facturation\Services\ReleveDesEncaissementsPdfGenerator;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Tests\TestCase;

/**
 * L'empreinte de la caisse sécurisée et des PDF (décision de l'éditeur du
 * 09/11/2026, D56, cahier de l'analyse statique, § 6.4) : une journée
 * entière d'un magasin -- un ticket réglé en deux modes, une facture à un
 * professionnel avec une remise et un service, un paiement de cette
 * facture et sa contre-passation, l'avoir d'un article du ticket (le bon
 * d'avoir du client comptoir), l'avoir d'un article de la facture, un
 * devis, une facture d'achat saisie sans la pièce du fournisseur, le
 * relevé de la caisse du vendeur, la clôture du jour et l'export du
 * journal des événements -- rend, au caractère près, ce que le code
 * rendait avant les lots de l'analyse statique, relevé alors et écrit
 * dans tests/Fixtures/empreinte-de-la-caisse :
 *
 * - le contenu signé de chaque enregistrement du registre fiscal, journal
 *   des événements compris, dans l'ordre où il s'écrit ;
 * - le journal des événements tel que son export l'écrit ;
 * - le relevé de la caisse du vendeur, la clôture du jour et l'aperçu de
 *   son e-reporting, tels qu'ils sont enregistrés ;
 * - le nom et le texte de chaque PDF : le ticket, la facture, les deux
 *   avoirs, les reçus du paiement et de sa contre-passation, le devis, la
 *   fiche de saisie de l'achat, le ticket Z et le relevé des encaissements
 *   du mois ;
 * - le rapport du vérificateur d'intégrité, sans anomalie.
 *
 * Le temps est figé à chaque geste, à une heure écrite avec son fuseau ;
 * le registre signe avec une clé fixe, tirée d'une graine écrite ici, et
 * jamais épinglée hors de ce test ; chaque uuid est tiré dans l'ordre
 * d'une suite écrite ici. Restent seuls à varier d'une exécution à
 * l'autre :
 *
 * - la signature, qu'ECDSA tire avec un aléa : le contenu et l'export
 *   écrivent à sa place une marque, ⟦signature:chaîne:séquence⟧, que le
 *   test remplace par la signature de cet enregistrement ; chaque
 *   signature précédente est ainsi la signature de l'enregistrement
 *   précédent de la même chaîne (null pour le premier) ;
 * - les identifiants que tire la base, dont la séquence ne recule pas à
 *   l'annulation de la transaction d'un test : la marque ⟦table:nom⟧, que
 *   le test remplace par l'identifiant de la ligne qu'il a créée sous ce
 *   nom.
 *
 * Ce test et ses fichiers ne changent pas pendant les lots de l'analyse
 * statique (TY1 à PS2).
 */
class EmpreinteDeLaCaisseEtDesPdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La graine de la clé de signature fixe du test : le scalaire privé de
     * la clé ECDSA P-256 est son SHA-256.
     */
    private const GRAINE_DE_LA_CLE = 'PILIQI : clé de signature fixe du test d\'empreinte de la caisse et des PDF';

    private const DOSSIER = 'tests/Fixtures/empreinte-de-la-caisse';

    /**
     * Les lignes que le test crée, par marque : ⟦table:nom⟧ => identifiant.
     *
     * @var array<string, int>
     */
    private array $identifiants = [];

    public function test_la_caisse_et_les_pdf_rendent_au_caractere_pres_leur_empreinte_d_avant_l_analyse_statique(): void
    {
        $this->signerAvecLaCleFixe();
        $this->tirerLesUuidsDansLOrdre();
        $this->travelTo(self::aParis('2026-10-09 08:30:00'));
        $magasin = $this->magasin();
        $vendeur = self::auteur();
        $this->nommer('users', 'vendeur', $vendeur->getKey());
        $this->nommer('users', 'proprietaire', $this->proprietaireDe($magasin)->getKey());
        $comptoir = $this->creerLeClientComptoir();
        $atelier = $this->creer('clients', 'atelier', Client::factory()->professionnel()->create([
            'nom' => 'Atelier Dupont SARL',
            'adresse' => '8 rue des Tanneurs',
            'complement_adresse' => null,
            'code_postal' => '69001',
            'ville' => 'Lyon',
            'telephone' => '04 78 00 00 01',
            'email' => 'compta@atelier-dupont.example',
            'delai_paiement_jours' => 30,
            'mode_paiement_prefere' => null,
            'notes' => null,
        ]));
        $cafe = $this->creer('produits', 'cafe', $this->produit('CAF-250', 'Café moulu 250 g', '12.00', '20'));
        $the = $this->creer('produits', 'the', $this->produit('THE-100', 'Thé vert 100 g', '6.50', '5.5'));
        $coffret = $this->creer('produits', 'coffret', $this->produit('COF-DEG', 'Coffret dégustation', '45.00', '20'));
        $livraison = $this->creer('services', 'livraison', Service::factory()->auPrixDe('15.00')->create(['nom' => 'Livraison sur rendez-vous']));

        $this->travelTo(self::aParis('2026-10-09 09:30:00'));
        $ticket = $this->creer('factures', 'ticket', app(EnregistrerVenteCaisseAction::class)->handle(
            $vendeur,
            $comptoir,
            [['produit_id' => $cafe->id, 'quantite' => 2], ['produit_id' => $the->id, 'quantite' => 1]],
            new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 1050), new PartDeReglement(ModePaiement::CarteBancaire, 2000, 'CB-0930')]),
            jeton: (string) Str::uuid(),
        ));
        $this->nommerLesLignes('lignes_facture', 'ticket', $ticket->lignes()->orderBy('id')->pluck('id')->all());
        $this->nommerLesLignes('paiements', 'ticket', $ticket->allocationsPaiement()->orderBy('id')->pluck('paiement_id')->all());

        $this->travelTo(self::aParis('2026-10-09 10:15:00'));
        $facture = $this->creer('factures', 'facture', app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle(
            $vendeur,
            $atelier,
            [['produit_id' => $coffret->id, 'quantite' => 3, 'remise_pourcentage' => '10'], ['service_id' => $livraison->id, 'quantite' => 1]],
            ['conditions_paiement' => null, 'notes' => 'Livraison prévue le 15/10/2026.'],
        ), jeton: (string) Str::uuid()));
        $this->nommerLesLignes('lignes_facture', 'facture', $facture->lignes()->orderBy('id')->pluck('id')->all());

        $this->travelTo(self::aParis('2026-10-09 10:45:00'));
        $virement = $this->creer('paiements', 'virement', app(EnregistrerPaiementAction::class)->handle(
            $vendeur,
            $atelier,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '50.00', 'reference' => 'VIR-2026-0042', 'recu_le' => '2026-10-09'],
            [['facture_id' => $facture->id, 'montant' => '50.00']],
            (string) Str::uuid(),
        ));

        $this->travelTo(self::aParis('2026-10-09 11:00:00'));
        $contrePassation = $this->creer('paiements', 'contre_passation', app(ContrePasserPaiementAction::class)->handle($vendeur, $virement, 'Virement saisi deux fois', (string) Str::uuid()));

        $this->travelTo(self::aParis('2026-10-09 11:30:00'));
        $bonDAvoir = $this->creer('avoirs', 'bon_d_avoir', app(EnregistrerAvoirAction::class)->handle($vendeur, $comptoir, [['ligne_facture_id' => $this->identifiants['⟦lignes_facture:ticket_1⟧'], 'quantite' => 1]], 'Retour du client', (string) Str::uuid()));
        $this->nommerLesLignes('lignes_avoir', 'bon_d_avoir', $bonDAvoir->lignes()->orderBy('id')->pluck('id')->all());

        $this->travelTo(self::aParis('2026-10-09 14:00:00'));
        $avoir = $this->creer('avoirs', 'avoir', app(EnregistrerAvoirAction::class)->handle($vendeur, $atelier, [['ligne_facture_id' => $this->identifiants['⟦lignes_facture:facture_1⟧'], 'quantite' => 1]], 'Coffret abîmé', (string) Str::uuid()));
        $this->nommerLesLignes('lignes_avoir', 'avoir', $avoir->lignes()->orderBy('id')->pluck('id')->all());

        $this->travelTo(self::aParis('2026-10-09 15:00:00'));
        $devis = $this->creer('devis', 'devis', app(EmettreDevisAction::class)->handle($vendeur, app(CreerDevisAction::class)->handle(
            $vendeur,
            $atelier,
            [['produit_id' => $coffret->id, 'quantite' => 2], ['service_id' => $livraison->id, 'quantite' => 1]],
            ['notes' => 'Devis valable pour une livraison en novembre.'],
        ), (string) Str::uuid()));

        $this->travelTo(self::aParis('2026-10-09 16:00:00'));
        $fournisseur = $this->creer('fournisseurs', 'grossiste', Fournisseur::factory()->create([
            'nom' => 'Grossiste du Rhône',
            'siret' => '40000000600017',
            'numero_tva' => 'FR32400000006',
            'adresse' => '3 quai Perrache',
            'complement_adresse' => null,
            'code_postal' => '69002',
            'ville' => 'Lyon',
            'telephone' => '04 72 00 00 02',
            'email' => 'commandes@grossiste-du-rhone.example',
        ]));
        $achat = $this->creer('factures_achat', 'achat', app(ValiderFactureAchatAction::class)->handle($vendeur, app(CreerFactureAchatAction::class)->handle(
            $vendeur,
            $fournisseur,
            [['produit_id' => $cafe->id, 'quantite' => 10, 'prix_unitaire_ht' => '4.25'], ['produit_id' => $the->id, 'quantite' => 6, 'prix_unitaire_ht' => '2.10']],
            ['date_facture' => '2026-10-08', 'numero_fournisseur' => 'GR-2026-118'],
        ), (string) Str::uuid()));

        $this->travelTo(self::aParis('2026-10-09 17:30:00'));
        $releve = $this->creer('clotures_caisse_personnelles', 'releve', app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur, (string) Str::uuid()));

        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
        $cloture = $this->creer('clotures_caisse', 'cloture', app(CloturerCaisseAction::class)->handle($vendeur, $magasin, '2026-10-09'));

        $this->travelTo(self::aParis('2026-10-09 19:05:00'));
        $exporter = app(ExporterJournalDesEvenementsAction::class)->handle($vendeur, '2026-10-09', '2026-10-09');
        $flux = fopen('php://memory', 'w+b');
        $exporter($flux);
        rewind($flux);
        $journal = (string) stream_get_contents($flux);
        fclose($flux);

        $this->travelTo(self::aParis('2026-10-09 19:10:00'));
        $recus = RecuPaiement::query()->orderBy('id')->get();
        $this->assertCount(2, $recus);
        [$recuDuVirement, $recuDeLaContrePassation] = [$recus[0], $recus[1]];
        $this->nommer('recus_paiement', 'virement', $recuDuVirement->getKey());
        $this->nommer('recus_paiement', 'contre_passation', $recuDeLaContrePassation->getKey());
        $this->assertSame([$virement->getKey()], $recuDuVirement->paiements()->pluck('id')->all());
        $this->assertSame([$contrePassation->getKey()], $recuDeLaContrePassation->paiements()->pluck('id')->all());

        $pdf = [
            'ticket' => $this->pdf(app(FacturePdfGenerator::class)->nomFichier($ticket), app(FacturePdfGenerator::class)->generer($ticket->fresh())),
            'facture' => $this->pdf(app(FacturePdfGenerator::class)->nomFichier($facture), app(FacturePdfGenerator::class)->generer($facture->fresh())),
            'bon-d-avoir' => $this->pdf(app(AvoirPdfGenerator::class)->nomFichier($bonDAvoir), app(AvoirPdfGenerator::class)->generer($bonDAvoir->fresh())),
            'avoir' => $this->pdf(app(AvoirPdfGenerator::class)->nomFichier($avoir), app(AvoirPdfGenerator::class)->generer($avoir->fresh())),
            'recu-du-virement' => $this->pdf(app(RecuPaiementPdfGenerator::class)->nomFichier($recuDuVirement), app(RecuPaiementPdfGenerator::class)->generer($recuDuVirement->fresh())),
            'recu-de-la-contre-passation' => $this->pdf(app(RecuPaiementPdfGenerator::class)->nomFichier($recuDeLaContrePassation), app(RecuPaiementPdfGenerator::class)->generer($recuDeLaContrePassation->fresh())),
            'devis' => $this->pdf(app(DevisPdfGenerator::class)->nomFichier($devis), app(DevisPdfGenerator::class)->generer($devis->fresh()->load('client', 'lignes', 'magasin'))),
            'fiche-de-saisie' => $this->pdf('fiche-de-saisie-'.$achat->numero.'.pdf', app(FicheDeSaisiePdfGenerator::class)->generer($achat->fresh())),
            'ticket-z' => $this->pdf(app(ClotureCaissePdfGenerator::class)->nomFichier($cloture), app(ClotureCaissePdfGenerator::class)->generer($cloture->fresh())),
            'releve-des-encaissements' => $this->pdf('encaissements-LYON1-2026-10.pdf', app(ReleveDesEncaissementsPdfGenerator::class)->generer($vendeur, $magasin, '2026-10', '2026-10-01', '2026-10-31')),
        ];

        $enregistrements = EnregistrementFiscal::query()->orderBy('id')->get();
        $signatures = [];
        $precedentes = [];
        $signaturesDesPrecedents = [];
        $derniereDeLaChaine = [];
        foreach ($enregistrements as $enregistrement) {
            $chaine = $enregistrement->chaine->value;
            $signatures["⟦signature:{$chaine}:{$enregistrement->sequence}⟧"] = $enregistrement->signature;
            $precedentes["{$chaine}:{$enregistrement->sequence}"] = $enregistrement->signature_precedente;
            $signaturesDesPrecedents["{$chaine}:{$enregistrement->sequence}"] = $derniereDeLaChaine[$chaine] ?? null;
            $derniereDeLaChaine[$chaine] = $enregistrement->signature;
        }
        $rapport = app(VerificateurIntegrite::class)->verifier($magasin);

        $this->assertSame($signaturesDesPrecedents, $precedentes);

        $obtenu = [
            'registre' => $enregistrements->map(fn (EnregistrementFiscal $enregistrement): string => $enregistrement->contenu)->implode("\n")."\n",
            'journal-des-evenements' => $journal,
            'releve-de-caisse' => self::enJson($releve->fresh()->getAttributes()),
            'cloture-du-jour' => self::enJson($cloture->fresh()->getAttributes()),
            'apercu-e-reporting' => self::enJson(app(EReportingDesParticuliers::class)->apercu($cloture->fresh())),
            'verification' => self::enJson([
                'conforme' => $rapport->estConforme(),
                'anomalies' => $rapport->nombreDAnomalies(),
                'enregistrements_verifies' => $rapport->enregistrementsVerifies(),
                'points_de_reprise' => $rapport->pointsDeReprise(),
            ]),
            ...array_combine(array_map(fn (string $nom): string => "pdf/{$nom}", array_keys($pdf)), $pdf),
        ];

        $this->assertSame($this->empreinte(array_keys($obtenu), $signatures), $obtenu);
    }

    /**
     * Le registre signe avec la clé fixe du test, seule épinglée : celle
     * que tirent les tests au hasard n'y entre pas, et son cle_id, signé
     * dans chaque enregistrement, est toujours le même.
     */
    private function signerAvecLaCleFixe(): void
    {
        $cle = openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => hash('sha256', self::GRAINE_DE_LA_CLE, true)]]);
        $this->assertNotFalse($cle);
        $this->assertTrue(openssl_pkey_export($cle, $clePrivee));
        $details = openssl_pkey_get_details($cle);
        $this->assertIsArray($details);

        config([
            'caisse_securisee.cle_privee' => base64_encode($clePrivee),
            'caisse_securisee.phrase_secrete' => null,
            'caisse_securisee.cles_publiques' => ['empreinte.pem' => $details['key']],
        ]);
        $this->app->forgetScopedInstances();
    }

    /**
     * Chaque uuid, du magasin, de la société, d'un compte ou d'un jeton de
     * demande, est le suivant d'une suite écrite ici, dans l'ordre où le
     * code le tire.
     */
    private function tirerLesUuidsDansLOrdre(): void
    {
        $rang = 0;
        Str::createUuidsUsing(function () use (&$rang): UuidInterface {
            return Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$rang));
        });
    }

    /**
     * Le magasin LYON1 « Ma Boutique », à Lyon, d'une société validée à
     * l'identité écrite en clair, magasin actif.
     */
    private function magasin(): Magasin
    {
        $magasin = Magasin::factory()
            ->avecLaSociete([
                'siren' => '100000009',
                'raison_sociale' => 'Les Délices de Jeanne',
                'forme_juridique' => FormeJuridique::Sas,
                'capital_social' => '10000.00',
                'immatriculation' => 'RCS Lyon',
                'numero_tva' => 'FR88100000009',
                'siege_adresse' => '1 rue de la République',
                'siege_complement_adresse' => null,
                'siege_code_postal' => '69002',
                'siege_ville' => 'Lyon',
            ])
            ->create([
                'code' => 'LYON1',
                'nom' => 'Ma Boutique',
                'siret' => '10000000900017',
                'adresse' => '12 rue Mercière',
                'complement_adresse' => null,
                'code_postal' => '69002',
                'ville' => 'Lyon',
                'email' => 'contact@ma-boutique.example',
                'telephone' => '04 72 00 00 00',
            ]);
        app(TenantContext::class)->set($magasin);
        $this->nommer('magasins', 'lyon1', $magasin->getKey());
        $this->nommer('societes', 'societe', (int) $magasin->societe_id);

        return $magasin;
    }

    /**
     * Le client comptoir du magasin, tel que la création d'un établissement
     * le crée (voir CreerEtablissementAction).
     */
    private function creerLeClientComptoir(): Client
    {
        return $this->creer('clients', 'comptoir', Client::forceCreate([
            'nom' => Client::NOM_COMPTOIR,
            'type_client' => TypeClient::Particulier,
            'est_comptoir' => true,
        ]));
    }

    private function produit(string $reference, string $nom, string $prixTtc, string $tauxTva): Produit
    {
        return Produit::factory()->create([
            'reference' => $reference,
            'code_barres' => null,
            'nom' => $nom,
            'taille' => null,
            'couleur' => null,
            'cmup_ht' => '0',
            'prix_vente_ttc' => $prixTtc,
            'taux_tva' => $tauxTva,
            'suivi_stock' => false,
            'seuil_alerte' => 0,
        ]);
    }

    /**
     * Nomme la ligne du modèle que le test vient de créer : ⟦table:nom⟧.
     *
     * @template TModele of Model
     *
     * @param  TModele  $modele
     * @return TModele
     */
    private function creer(string $table, string $nom, Model $modele): Model
    {
        $this->nommer($table, $nom, $modele->getKey());

        return $modele;
    }

    private function nommer(string $table, string $nom, int $identifiant): void
    {
        $this->identifiants["⟦{$table}:{$nom}⟧"] = $identifiant;
    }

    /**
     * @param  list<int>  $identifiants  dans leur ordre
     */
    private function nommerLesLignes(string $table, string $document, array $identifiants): void
    {
        foreach (array_values($identifiants) as $rang => $identifiant) {
            $this->nommer($table, $document.'_'.($rang + 1), $identifiant);
        }
    }

    /**
     * Le nom du fichier, puis le texte de chaque page, un texte par ligne.
     */
    private function pdf(string $nom, PdfDocument $pdf): string
    {
        $texte = "fichier : {$nom}\n";

        foreach ($this->pagesDuPdf($pdf->output()) as $rang => $page) {
            $texte .= sprintf("--- page %d (%s)\n", $rang + 1, $page['format']);

            foreach ($page['textes'] as $morceau) {
                $texte .= $morceau['texte']."\n";
            }
        }

        return $texte;
    }

    private static function enJson(mixed $valeur): string
    {
        return json_encode($valeur, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * L'empreinte relevée de chacun de ces fichiers, ses marques remplacées
     * par les identifiants de cette exécution et les signatures de son
     * registre.
     *
     * @param  list<string>  $fichiers
     * @param  array<string, string>  $signatures
     * @return array<string, string>
     */
    private function empreinte(array $fichiers, array $signatures): array
    {
        $marques = [...array_map('strval', $this->identifiants), ...$signatures];
        $empreinte = [];

        foreach ($fichiers as $fichier) {
            $releve = (string) file_get_contents(base_path(self::DOSSIER."/{$fichier}.txt"));
            $empreinte[$fichier] = strtr($releve, $marques);
        }

        return $empreinte;
    }
}
