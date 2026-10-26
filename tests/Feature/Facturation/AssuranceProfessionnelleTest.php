<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'assurance professionnelle de la société (voir IdentiteSociete,
 * ModifierIdentiteSocieteAction et Emetteur::ligneDeLAssurance()) :
 * facultative dans tous les types qui ont un magasin, jamais exigée pour
 * numéroter un devis ni émettre une vente ; renseignée, elle s'imprime
 * dans le pied de chaque PDF de vente, sous l'identité de la société, et
 * se fige dans l'émetteur signé à l'émission. Un cabinet d'expertise
 * comptable, sans magasin ni document de vente, n'en a pas.
 */
class AssuranceProfessionnelleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'assurance que la société renseigne d'abord.
     */
    private const ASSURANCE = [
        'assurance_assureur' => 'Mutuelle du Bâtiment',
        'assurance_adresse' => '12 rue des Artisans, 69003 Lyon',
        'assurance_contrat' => 'DEC-2026-0042',
        'assurance_couverture' => 'France métropolitaine',
    ];

    /**
     * La ligne qu'elle imprime dans le pied des documents.
     */
    private const LIGNE = 'Assurance professionnelle : contrat n° DEC-2026-0042, Mutuelle du Bâtiment, 12 rue des Artisans, 69003 Lyon, couverture : France métropolitaine';

    /**
     * L'assurance qui la remplace ensuite.
     */
    private const NOUVELLE_ASSURANCE = [
        'assurance_assureur' => 'Garantie des Artisans',
        'assurance_adresse' => '3 place Carnot, 69002 Lyon',
        'assurance_contrat' => 'GA-2027-7',
        'assurance_couverture' => 'France et Union européenne',
    ];

    private const NOUVELLE_LIGNE = 'Assurance professionnelle : contrat n° GA-2027-7, Garantie des Artisans, 3 place Carnot, 69002 Lyon, couverture : France et Union européenne';

    /**
     * @return array<string, array{0: TypeDActivite, 1: string}>
     */
    public static function documentsSansAssurance(): array
    {
        $cas = [];
        foreach ([TypeDActivite::Commerce, TypeDActivite::Services] as $type) {
            foreach (['devis', 'facture à un professionnel', 'ticket'] as $document) {
                $cas["{$type->getLabel()}, {$document}"] = [$type, $document];
            }
        }

        return $cas;
    }

    /**
     * Sans assurance renseignée, rien ne manque : le devis se numérote, la
     * facture à un professionnel et le ticket s'émettent, et leur pied
     * n'imprime aucune ligne d'assurance.
     */
    #[DataProvider('documentsSansAssurance')]
    public function test_sans_assurance_un_devis_se_numerote_et_une_vente_s_emet_dans_le_commerce_et_les_services(TypeDActivite $type, string $document): void
    {
        $magasin = $this->magasin($type);

        $html = match ($document) {
            'devis' => app(DevisPdfGenerator::class)->html($this->devis($this->professionnel())),
            'facture à un professionnel' => app(FacturePdfGenerator::class)->html($this->emettre($this->professionnel())),
            'ticket' => app(FacturePdfGenerator::class)->html($this->ticket()),
        };

        $this->assertSame([null, null, null, null], array_values($magasin->societe->only(ModifierIdentiteSocieteAction::CHAMPS_DE_L_ASSURANCE)));
        $this->assertNull(Emetteur::duMagasin($magasin->fresh())->ligneDeLAssurance());
        $this->assertStringNotContainsString('Assurance professionnelle', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function champsSansAssureur(): array
    {
        return [
            'adresse' => ['assurance_adresse'],
            'n° de contrat' => ['assurance_contrat'],
            'couverture géographique' => ['assurance_couverture'],
        ];
    }

    /**
     * Un champ de l'assurance renseigné sans l'assureur ou le garant
     * (absent, vide ou fait d'espaces) est refusé, par l'écran comme par
     * l'action, et rien ne s'enregistre : aucune ligne incomplète ne
     * s'imprimerait.
     */
    #[DataProvider('champsSansAssureur')]
    public function test_un_champ_d_assurance_sans_assureur_ni_garant_est_refuse(string $champ): void
    {
        $magasin = $this->magasin(TypeDActivite::Services);
        $societe = $magasin->societe;

        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $societe, [$champ => self::ASSURANCE[$champ]]),
            fn (ValidationException $refus): bool => $refus->errors() === ['assurance_assureur' => [ModifierIdentiteSocieteAction::MESSAGE_ASSUREUR_EXIGE]],
        );
        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $societe, ['assurance_assureur' => '   ', $champ => self::ASSURANCE[$champ]]),
            fn (ValidationException $refus): bool => $refus->errors() === ['assurance_assureur' => [ModifierIdentiteSocieteAction::MESSAGE_ASSUREUR_EXIGE]],
        );

        $this->dansLEspaceDeLaSociete($societe, $this->proprietaireDe($magasin));
        Livewire::test(IdentiteSociete::class)
            ->fillForm([$champ => self::ASSURANCE[$champ]])
            ->call('save')
            ->assertHasFormErrors(['assurance_assureur'])
            ->assertSee(ModifierIdentiteSocieteAction::MESSAGE_ASSUREUR_EXIGE);

        $this->assertSame([null, null, null, null], array_values($societe->fresh()->only(ModifierIdentiteSocieteAction::CHAMPS_DE_L_ASSURANCE)));
    }

    /**
     * Renseignée, l'assurance s'imprime dans le pied du devis, de la
     * facture, du ticket et de l'avoir, juste sous l'identité de la
     * société, et le pied réserve sa hauteur : ses 143 caractères y
     * prennent deux lignes de 3 mm. Un champ vide ne laisse ni libellé ni
     * virgule.
     */
    public function test_l_assurance_renseignee_s_imprime_dans_le_pied_des_devis_des_factures_des_tickets_et_des_avoirs(): void
    {
        $magasin = $this->magasin(TypeDActivite::Services);
        app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, self::ASSURANCE);
        $client = $this->professionnel();
        $facture = $this->emettre($client);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);

        foreach ([
            'devis' => app(DevisPdfGenerator::class)->html($this->devis($client)),
            'facture' => app(FacturePdfGenerator::class)->html($facture->fresh()),
            'ticket' => app(FacturePdfGenerator::class)->html($this->ticket()),
            'avoir' => app(AvoirPdfGenerator::class)->html($avoir->fresh()),
        ] as $document => $html) {
            $this->assertSame(self::LIGNE, $this->pieds($html)[0], $document);
            $this->assertMatchesRegularExpression('/<div class="identite">[^<]*<\/div>\s*<div class="pied">'.preg_quote(e(self::LIGNE), '/').'<\/div>/', $html, $document);
        }

        $pied = PiedDePage::pour($facture->fresh(), $facture->fresh()->emetteur());
        $this->assertSame([self::LIGNE, 143], [$pied->lignes[0], mb_strlen(self::LIGNE)]);
        $this->assertSame((new PiedDePage($pied->identite, array_slice($pied->lignes, 1)))->hauteurEnMillimetres() + 6, $pied->hauteurEnMillimetres());

        app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, ['assurance_adresse' => '', 'assurance_contrat' => '']);
        $this->assertSame('Assurance professionnelle : Mutuelle du Bâtiment, couverture : France métropolitaine', Emetteur::duMagasin($magasin->fresh())->ligneDeLAssurance());
    }

    /**
     * La facture, le ticket et l'avoir émis gardent l'assurance du jour de
     * leur émission quand elle change ensuite ; un brouillon et un devis,
     * jamais signés, impriment celle d'aujourd'hui. Chaque changement
     * s'inscrit au journal d'audit de la société, avec les anciennes et
     * les nouvelles valeurs.
     */
    public function test_l_assurance_se_fige_a_l_emission_et_son_changement_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->magasin(TypeDActivite::Commerce);
        $proprietaire = $this->proprietaireDe($magasin);
        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $magasin->societe, self::ASSURANCE);
        $client = $this->professionnel();
        $facture = $this->emettre($client);
        $ticket = $this->ticket();
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);
        $devis = $this->devis($client);

        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $magasin->societe, self::NOUVELLE_ASSURANCE);

        $this->assertSame([self::LIGNE, self::LIGNE, self::LIGNE], [
            $this->pieds(app(FacturePdfGenerator::class)->html($facture->fresh()))[0],
            $this->pieds(app(FacturePdfGenerator::class)->html($ticket->fresh()))[0],
            $this->pieds(app(AvoirPdfGenerator::class)->html($avoir->fresh()))[0],
        ]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [$this->ligneDeService()]);
        $this->assertSame([self::NOUVELLE_LIGNE, self::NOUVELLE_LIGNE], [
            $this->pieds(app(FacturePdfGenerator::class)->html($brouillon))[0],
            $this->pieds(app(DevisPdfGenerator::class)->html($devis->fresh()))[0],
        ]);

        $changements = app(TenantContext::class)->runAsSociete($magasin->societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::IdentiteModifiee->value)->orderBy('sequence')->get());
        $this->assertSame([
            [self::parCle(array_fill_keys(ModifierIdentiteSocieteAction::CHAMPS_DE_L_ASSURANCE, null)), self::parCle(self::ASSURANCE)],
            [self::parCle(self::ASSURANCE), self::parCle(self::NOUVELLE_ASSURANCE)],
        ], $changements->map(fn (EntreeAudit $entree): array => [self::parCle($entree->anciennes_valeurs), self::parCle($entree->nouvelles_valeurs)])->all());
        $this->assertSame([$proprietaire->id, $proprietaire->id], $changements->map(fn (EntreeAudit $entree): int => (int) $entree->auteur_id)->all());
    }

    /**
     * Un cabinet d'expertise comptable n'a ni magasin ni document de
     * vente : la page de son identité n'a pas de bloc d'assurance, et
     * l'action n'en écrit aucun champ, même reçu.
     */
    public function test_un_cabinet_n_a_pas_de_bloc_d_assurance(): void
    {
        app(RepertoireSirene::class)->exercer(FauxRepertoireSirene::SIREN_CABINET, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $cabinet = $this->inscrireUneSociete(FauxRepertoireSirene::SIREN_CABINET, type: TypeDActivite::Cabinet)->fresh();
        $proprietaire = $cabinet->proprietaire()->firstOrFail();

        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $cabinet, [...self::ASSURANCE, 'capital_social' => '5000', 'immatriculation' => 'RCS Paris']);

        $this->assertSame(['immatriculation' => 'RCS Paris', 'assurance_assureur' => null, 'assurance_adresse' => null, 'assurance_contrat' => null, 'assurance_couverture' => null], $cabinet->fresh()->only(['immatriculation', ...ModifierIdentiteSocieteAction::CHAMPS_DE_L_ASSURANCE]));
        $this->dansLEspaceDeLaSociete($cabinet->fresh(), $proprietaire);
        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $cabinet))
            ->assertOk()
            ->assertSeeText('Identité légale')
            ->assertDontSeeText(['Assurance professionnelle', 'Assureur ou garant']);
        Livewire::test(IdentiteSociete::class)
            ->assertFormFieldIsHidden('assurance_assureur')
            ->fillForm(['assurance_assureur' => 'Mutuelle du Bâtiment'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($cabinet->fresh()->assurance_assureur);
    }

    /**
     * Le magasin d'une société de ce type, à l'identité légale complète
     * pour facturer un professionnel et sans assurance renseignée. Il
     * devient le magasin actif.
     */
    private function magasin(TypeDActivite $type): Magasin
    {
        return $this->ouvrirUneSociete(['type' => $type, 'capital_social' => '10000.00', 'immatriculation' => 'RCS Lyon']);
    }

    private function professionnel(): Client
    {
        return Client::factory()->professionnel()->create();
    }

    /**
     * Un service à 30,00 € TTC, au taux de 20 %, que tous les types qui
     * ont un magasin vendent.
     *
     * @return array<string, mixed>
     */
    private function ligneDeService(): array
    {
        return ['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'quantite' => 1];
    }

    private function devis(Client $client): Devis
    {
        return app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [$this->ligneDeService()]))->fresh();
    }

    private function emettre(Client $client): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [$this->ligneDeService()]))->fresh();
    }

    private function ticket(): Facture
    {
        return self::vendreAuComptant(self::auteur(), Client::factory()->create(), [$this->ligneDeService()])->fresh();
    }

    /**
     * Le texte de chaque ligne du pied du document, sous l'identité de la
     * société, dans l'ordre.
     *
     * @return list<string>
     */
    private function pieds(string $html): array
    {
        preg_match_all('/<div class="pied">(.*?)<\/div>/s', $html, $pieds);

        return array_map(fn (string $pied): string => html_entity_decode(trim($pied), ENT_QUOTES), $pieds[1]);
    }

    /**
     * Les valeurs triées par champ, comme le journal d'audit les garde.
     *
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private static function parCle(array $valeurs): array
    {
        ksort($valeurs);

        return $valeurs;
    }
}
