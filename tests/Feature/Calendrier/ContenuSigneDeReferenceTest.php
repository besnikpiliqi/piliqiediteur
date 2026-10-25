<?php

namespace Tests\Feature\Calendrier;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le contenu signé d'un ticket, de son encaissement, d'une livraison
 * intracommunautaire avec sa vérification VIES et d'un avoir, à des
 * instants où le jour de Paris n'est pas celui de l'UTC : il reste octet
 * pour octet celui que fixent les fichiers de référence
 * (tests/Fixtures/contenu-signe), écrits en clair. Seuls les identifiants
 * que tire la base ou le hasard (identifiants, uuid du magasin, clé et
 * signature précédente) y sont remplacés par « … ».
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
     * @return array<string, array{CarbonImmutable, string, string}>
     */
    public static function instants(): array
    {
        return [
            'jour' => [self::enUtc('2026-10-09 08:00:00'), '2026-10-09', 'jour'],
            'nuit d\'été' => [self::enUtc('2026-07-14 22:30:00'), '2026-07-15', 'nuit-d-ete'],
            'nuit d\'hiver' => [self::enUtc('2026-11-15 23:30:00'), '2026-11-16', 'nuit-d-hiver'],
            'nouvel an' => [self::enUtc('2026-12-31 23:30:00'), '2027-01-01', 'nouvel-an'],
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
            'livraison_date' => $jourDeParis,
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
