<?php

namespace Tests\Feature\Calendrier;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Database\Factories\MagasinFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le contenu signé d'un ticket, de son encaissement, d'une livraison
 * intracommunautaire avec sa vérification VIES et d'un avoir, à des
 * instants où le jour de Paris n'est pas celui de l'UTC, et aux deux
 * passages de l'heure qui se répète au passage à l'heure d'hiver ; puis
 * ceux de magasins de la Martinique et de La Réunion, à des instants où
 * leur jour n'est pas celui de Paris : il reste octet pour octet celui que
 * fixent les fichiers de référence (tests/Fixtures/contenu-signe), écrits
 * en clair. Seuls les identifiants que tire la base ou le hasard
 * (identifiants, uuid du magasin, clé et signature précédente) y sont
 * remplacés par « … ».
 */
class ContenuSigneDeReferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les clés dont la valeur dépend de la base ou du hasard, et non de ce
     * que signe le document.
     */
    private const CLES_TIREES = ['uuid', 'caisse', 'cle_id', 'signature_precedente', 'id', 'cree_par'];

    /**
     * Les versions qui changent le module fiscal sans changer la structure
     * du contenu signé du site réel, avec la version dont elles gardent la
     * structure : la 3.0 (lot DEMO-COPIE, D.7) change le vérificateur et
     * n'ajoute la clé « demonstration » qu'aux en-têtes et aux émetteurs
     * des copies du site de démonstration, jamais à ceux du site réel, que
     * fixent les fichiers de référence.
     */
    private const MEME_STRUCTURE_QUE = ['3.0' => '2.1'];

    /**
     * La structure du contenu signé que signe chaque version du module
     * fiscal : le SHA-256 des chemins de ses clés dans les fichiers de
     * référence (voir cheminsDesCles()), qui le fixent octet pour octet.
     * Toute modification du module fiscal en change la version (voir
     * ModuleFiscal) : une structure nouvelle prend une nouvelle version,
     * ajoutée ici, et aucune version ne s'efface. La 2.0 est celle que le
     * code signait juste avant la date de l'opération de la facture (T49),
     * qui fait la 2.1 : le bloc « document » d'une facture et d'un ticket
     * gagne date_operation (début et fin), son bloc « livraison » perd sa
     * date. La 3.0 (lot DEMO-COPIE) garde la structure signée du site réel :
     * seul le vérificateur et les copies du site de démonstration changent.
     * La 3.1 (lot IDENT, décision P14.7) ajoute à l'émetteur d'un document
     * dont la société déclare une mention facultative (liquidation,
     * location-gérance, EIRL, assujetti unique) le bloc
     * « mentions_facultatives », que fixe le fichier de référence
     * mentions-facultatives.json ; celui d'une société qui n'en déclare
     * aucune garde sa forme.
     */
    private const STRUCTURES_SIGNEES = [
        '2.0' => '8d3cf5148390ac0c2b822501bfa82522df919f3ec6a59ab74f84ccc6ee14b3a8',
        '2.1' => '5e7c5deb1b8ad9421220957cfac7f0609c36aaea4258c415157f086c976d70bd',
        '3.0' => '5e7c5deb1b8ad9421220957cfac7f0609c36aaea4258c415157f086c976d70bd',
        '3.1' => 'fe71c8e0b6834fff51ac2f7c80f6ea94f0633726bd6356aa990c697a8a39024b',
    ];

    /**
     * @return array<string, array{CarbonImmutable, string, string}>
     */
    public static function instants(): array
    {
        return [
            'jour' => [self::enUtc('2026-10-09 08:00:00'), '2026-10-09', 'jour'],
            'nuit d\'été' => [self::enUtc('2026-07-14 22:30:00'), '2026-07-15', 'nuit-d-ete'],
            'nuit d\'hiver' => [self::enUtc('2026-11-15 23:30:00'), '2026-11-16', 'nuit-d-hiver'],
            'nouvel an' => [self::enUtc('2026-12-31 23:30:00'), '2027-01-01', 'nouvel-an'],
            'premier passage de l\'heure répétée' => [self::enUtc('2026-10-25 00:30:00'), '2026-10-25', 'premier-passage'],
            'second passage de l\'heure répétée' => [self::enUtc('2026-10-25 01:30:00'), '2026-10-25', 'second-passage'],
        ];
    }

    /**
     * Le ticket, son encaissement, la facture et l'avoir émis à cet
     * instant, dont le jour de Paris est $jourDeParis, signent
     * exactement le contenu de leur fichier de référence.
     */
    #[DataProvider('instants')]
    public function test_le_contenu_signe_d_un_ticket_d_une_facture_d_un_avoir_et_d_une_consultation_vies_reste_identique(CarbonImmutable $instant, string $jourDeParis, string $reference): void
    {
        $this->travelTo($instant);
        $this->magasinDeReference();
        $cafe = Produit::factory()->create(['reference' => 'CAF-250', 'nom' => 'Café moulu 250 g', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $vis = Produit::factory()->create(['reference' => 'VIS-001', 'nom' => 'Vis à bois', 'prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false]);
        $particulier = Client::factory()->create(['nom' => 'Paul Martin', 'email' => 'paul.martin@exemple.fr', 'telephone' => '01 23 45 67 89']);
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create(['nom' => 'Muster GmbH', 'code_postal' => '10115', 'ville' => 'Berlin', 'email' => 'einkauf@muster.example', 'telephone' => '01 98 76 54 32']);
        ConsultationVies::forceCreate([
            'client_id' => $allemand->id,
            'numero_tva' => 'DE123456789',
            'valide' => true,
            'identifiant_consultation' => 'WAPIAAAAW1234567',
            'consultee_le' => now(),
            'reponse' => '{"valid":true}',
        ]);

        $ticket = self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cafe->id, 'quantite' => 2]], ModePaiement::Especes);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $allemand, [['produit_id' => $vis->id, 'quantite' => 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'date_operation_fin' => $jourDeParis,
            'transport_par' => TransportPar::Acquereur,
        ]));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $particulier, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $contenus = [
            'ticket' => $this->contenuSigne($ticket, ChaineFiscale::Vente),
            'encaissement' => $this->contenuSigne($ticket->allocationsPaiement()->sole()->paiement, ChaineFiscale::Encaissement),
            'facture' => $this->contenuSigne($facture, ChaineFiscale::Vente),
            'avoir' => $this->contenuSigne($avoir, ChaineFiscale::Vente),
        ];

        $this->assertSame($this->contenuDeReference($reference), $contenus);
    }

    /**
     * Un magasin d'outre-mer signe ses dates avec le décalage de son
     * territoire et ses jours dans son calendrier : en Martinique, le
     * ticket de 20:00 le 16 novembre (minuit UTC), son règlement et la
     * clôture de sa journée ; à La Réunion, la facture de 00:30 le
     * 1er janvier 2027 (encore le 31 décembre à Paris), numérotée en 2027,
     * et l'avoir qui la corrige, qui cite son jour.
     */
    public function test_le_contenu_signe_d_un_magasin_d_outre_mer_porte_le_decalage_et_le_jour_de_son_territoire(): void
    {
        $this->travelTo(self::enUtc('2026-11-17 00:00:00'));
        $martinique = $this->magasinDOutreMer(Territoire::Martinique, '200000008', 'FDF1', 'Les Saveurs de Fort-de-France');
        $rhum = Produit::factory()->create(['reference' => 'RHUM-70', 'nom' => 'Rhum agricole 70 cl', 'prix_vente_ttc' => 24, 'taux_tva' => '8.5', 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(['nom' => 'Marie Joseph', 'email' => 'marie.joseph@exemple.fr', 'telephone' => '05 96 00 00 01']), [['produit_id' => $rhum->id, 'quantite' => 1]], ModePaiement::Especes);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $martinique, '2026-11-16');
        $contenus = [
            'ticket' => $this->contenuSigne($ticket, ChaineFiscale::Vente),
            'encaissement' => $this->contenuSigne($ticket->allocationsPaiement()->sole()->paiement, ChaineFiscale::Encaissement),
            'cloture' => $this->contenuSigne($cloture, ChaineFiscale::Evenement),
        ];

        $this->travelTo(self::enUtc('2026-12-31 20:30:00'));
        $this->magasinDOutreMer(Territoire::Reunion, '300000007', 'RUN1', 'Les Épices de Saint-Denis');
        $vanille = Produit::factory()->create(['reference' => 'VAN-010', 'nom' => 'Vanille Bourbon 10 gousses', 'prix_vente_ttc' => '21.70', 'taux_tva' => '8.5', 'suivi_stock' => false]);
        $hotel = Client::factory()->professionnel()->create(['nom' => 'Hôtel du Barachois', 'adresse' => '2 rue du Barachois', 'code_postal' => '97400', 'ville' => 'Saint-Denis', 'email' => 'achats@barachois.example', 'telephone' => '02 62 00 00 02', 'delai_paiement_jours' => 30]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $hotel, [['produit_id' => $vanille->id, 'quantite' => 3]]));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $hotel, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $contenus += [
            'facture' => $this->contenuSigne($facture, ChaineFiscale::Vente),
            'avoir' => $this->contenuSigne($avoir, ChaineFiscale::Vente),
        ];

        $this->assertSame($this->contenuDeReference('outre-mer'), $contenus);
    }

    /**
     * L'émetteur d'une société qui déclare des mentions facultatives
     * (décision P14.7) signe le bloc « mentions_facultatives », que fixe le
     * fichier de référence mentions-facultatives.json : celui d'un
     * entrepreneur individuel à responsabilité limitée, locataire-gérant de
     * son fonds et en liquidation, sur son ticket, sa facture à un
     * professionnel et l'avoir qui la corrige. Les cinq clés du bloc, leur
     * ordre et leur type y sont écrits en clair, dont l'appartenance à un
     * assujetti unique, qu'aucun document ne peut porter à vrai (une telle
     * société ne peut rien vendre).
     */
    public function test_le_contenu_signe_d_une_societe_qui_declare_des_mentions_facultatives_porte_leur_bloc_tel_que_le_fixe_la_reference(): void
    {
        $this->travelTo(self::enUtc('2026-10-09 08:00:00'));
        $this->magasinDUnEntrepreneurEirl();
        $reparation = Produit::factory()->create(['reference' => 'REP-001', 'nom' => 'Réparation de vélo', 'prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $particulier = Client::factory()->create(['nom' => 'Paul Martin', 'email' => 'paul.martin@exemple.fr', 'telephone' => '01 23 45 67 89']);
        $atelier = Client::factory()->professionnel()->create(['nom' => 'Ateliers du Rhône', 'adresse' => '4 quai Perrache', 'code_postal' => '69002', 'ville' => 'Lyon', 'email' => 'achats@ateliers-du-rhone.example', 'telephone' => '04 72 00 00 02', 'delai_paiement_jours' => 30]);

        $ticket = self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $reparation->id, 'quantite' => 1]], ModePaiement::Especes);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $atelier, [['produit_id' => $reparation->id, 'quantite' => 3]]));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $atelier, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $contenus = [
            'ticket' => $this->contenuSigne($ticket, ChaineFiscale::Vente),
            'facture' => $this->contenuSigne($facture, ChaineFiscale::Vente),
            'avoir' => $this->contenuSigne($avoir, ChaineFiscale::Vente),
        ];

        $this->assertSame($this->contenuDeReference('mentions-facultatives'), $contenus);
    }

    /**
     * Une structure nouvelle du contenu signé (une clé ajoutée, retirée ou
     * déplacée dans les fichiers de référence) ne se signe jamais sous la
     * version d'une autre structure : la version du module fiscal est celle
     * de la structure que fixent les fichiers de référence, et deux versions
     * ne signent jamais la même, sauf celles de MEME_STRUCTURE_QUE.
     */
    public function test_une_structure_nouvelle_du_contenu_signe_prend_une_nouvelle_version_du_module_fiscal(): void
    {
        $chemins = [];

        foreach (glob(base_path('tests/Fixtures/contenu-signe/*.json')) ?: [] as $fichier) {
            $chemins = [...$chemins, ...self::cheminsDesCles(json_decode((string) file_get_contents($fichier), true, flags: JSON_THROW_ON_ERROR))];
        }

        $chemins = array_values(array_unique($chemins));
        sort($chemins, SORT_STRING);
        $structure = hash('sha256', implode("\n", $chemins));

        $propres = array_diff_key(self::STRUCTURES_SIGNEES, self::MEME_STRUCTURE_QUE);
        $this->assertSame(array_values(array_unique($propres)), array_values($propres), 'Deux versions du module fiscal ne signent jamais la même structure, sauf une version qui change le module sans changer le contenu signé du site réel.');

        foreach (self::MEME_STRUCTURE_QUE as $version => $precedente) {
            $this->assertSame(self::STRUCTURES_SIGNEES[$precedente], self::STRUCTURES_SIGNEES[$version], "La version {$version} signe la structure de la {$precedente}.");
        }
        $this->assertSame(
            $structure,
            self::STRUCTURES_SIGNEES[ModuleFiscal::VERSION] ?? null,
            "La structure du contenu signé ({$structure}) n'est pas celle de la version ".ModuleFiscal::VERSION.' du module fiscal : passez-le à une nouvelle version et ajoutez-la à STRUCTURES_SIGNEES.',
        );
    }

    /**
     * Les chemins des clés de cette valeur décodée, chacun « a.b.c », un
     * élément de liste s'écrivant « [] » ; une valeur qui n'est pas un
     * tableau est une feuille.
     *
     * @return list<string>
     */
    private static function cheminsDesCles(mixed $valeur, string $chemin = ''): array
    {
        if (! is_array($valeur)) {
            return [$chemin];
        }

        $chemins = [$chemin];

        foreach ($valeur as $cle => $enfant) {
            $segment = array_is_list($valeur) ? '[]' : (string) $cle;
            $chemins = [...$chemins, ...self::cheminsDesCles($enfant, $chemin === '' ? $segment : $chemin.'.'.$segment)];
        }

        return $chemins;
    }

    /**
     * Un magasin de ce territoire, d'une société validée à l'identité
     * écrite en clair, sur l'établissement 00017 de ce SIREN, magasin
     * actif.
     */
    private function magasinDOutreMer(Territoire $territoire, string $siren, string $code, string $raisonSociale): Magasin
    {
        [$codePostal, $ville] = MagasinFactory::lieuDuTerritoire($territoire);
        $magasin = Magasin::factory()
            ->dansLeTerritoire($territoire)
            ->avecLaSociete([
                'siren' => $siren,
                'raison_sociale' => $raisonSociale,
                'forme_juridique' => FormeJuridique::Sarl,
                'capital_social' => '5000.00',
                'immatriculation' => 'RCS '.$ville,
                'numero_tva' => IdentifiantsInsee::numeroTvaPropose($siren),
                'siege_adresse' => '1 rue de la République',
                'siege_code_postal' => $codePostal,
                'siege_ville' => $ville,
            ])
            ->create([
                'code' => $code,
                'nom' => 'Ma Boutique',
                'siret' => $siren.'00017',
                'adresse' => '12 rue Victor Hugo',
                'email' => 'contact@'.strtolower($code).'.example',
                'telephone' => '05 96 00 00 00',
            ]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Le magasin LYON1 « Ma Boutique », à Lyon, d'un entrepreneur individuel
     * à responsabilité limitée à l'identité écrite en clair, locataire-gérant
     * de son fonds et en liquidation, magasin actif.
     */
    private function magasinDUnEntrepreneurEirl(): Magasin
    {
        $magasin = Magasin::factory()
            ->avecLaSociete([
                'siren' => '400000006',
                'raison_sociale' => 'Pascal Bertrand',
                'forme_juridique' => FormeJuridique::EntrepreneurIndividuel,
                'capital_social' => null,
                'immatriculation' => 'RM 69 400 000 006',
                'numero_tva' => IdentifiantsInsee::numeroTvaPropose('400000006'),
                'siege_adresse' => '8 rue Mercière',
                'siege_code_postal' => '69002',
                'siege_ville' => 'Lyon',
                'en_liquidation' => true,
                'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant,
                'eirl' => true,
                'eirl_objet' => 'Réparation et vente de vélos',
            ])
            ->create([
                'code' => 'LYON1',
                'nom' => 'Cycles Bertrand',
                'siret' => '40000000600017',
                'adresse' => '8 rue Mercière',
                'code_postal' => '69002',
                'ville' => 'Lyon',
                'email' => 'contact@cycles-bertrand.example',
                'telephone' => '04 72 00 00 01',
            ]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Le magasin LYON1 « Ma Boutique », à Lyon, d'une société validée à
     * l'identité écrite en clair, magasin actif.
     */
    private function magasinDeReference(): Magasin
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
                'siege_code_postal' => '69002',
                'siege_ville' => 'Lyon',
            ])
            ->create([
                'code' => 'LYON1',
                'nom' => 'Ma Boutique',
                'siret' => '10000000900017',
                'adresse' => '12 rue Mercière',
                'code_postal' => '69002',
                'ville' => 'Lyon',
                'email' => 'contact@ma-boutique.example',
                'telephone' => '04 72 00 00 00',
            ]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Le contenu signé du document dans cette chaîne, ses valeurs tirées
     * par la base ou le hasard remplacées par « … ».
     *
     * @return array<string, mixed>
     */
    private function contenuSigne(Model $document, ChaineFiscale $chaine): array
    {
        $enregistrement = EnregistrementFiscal::query()
            ->where('chaine', $chaine->value)
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->sole();

        return $this->sansValeursTirees($enregistrement->contenuSigne());
    }

    /**
     * @param  array<array-key, mixed>  $valeurs
     * @return array<array-key, mixed>
     */
    private function sansValeursTirees(array $valeurs): array
    {
        foreach ($valeurs as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeurs[$cle] = $this->sansValeursTirees($valeur);
            } elseif ($valeur !== null && is_string($cle) && (in_array($cle, self::CLES_TIREES, true) || str_ends_with($cle, '_id'))) {
                $valeurs[$cle] = '…';
            }
        }

        return $valeurs;
    }

    /**
     * @return array<string, mixed>
     */
    private function contenuDeReference(string $reference): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/contenu-signe/{$reference}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
