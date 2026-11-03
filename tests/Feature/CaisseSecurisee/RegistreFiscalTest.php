<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\InstantFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Le registre fiscal : chaînes signées et chaînées par magasin, verrou et
 * horloge, événements techniques automatiques, inaltérabilité garantie
 * par le modèle et par la base. Les tests signent avec la paire éphémère
 * que Tests\TestCase épingle.
 */
class RegistreFiscalTest extends TestCase
{
    use RefreshDatabase;

    private function magasinActif(array $attributs = []): Magasin
    {
        $magasin = Magasin::factory()->create($attributs);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Un enregistrement dans sa propre transaction, au nom du système (voir
     * OperateurCourant) ; une vente y porte pour montant son
     * total_ttc_centimes.
     *
     * @param  array<array-key, mixed>  $donnees
     */
    private function enregistrer(ChaineFiscale $chaine = ChaineFiscale::Vente, string $type = 'ticket', array $donnees = ['total_ttc_centimes' => 1250], ?Model $document = null): EnregistrementFiscal
    {
        return $this->auNomDuSysteme(fn (): EnregistrementFiscal => DB::transaction(fn (): EnregistrementFiscal => $chaine === ChaineFiscale::Vente
            ? app(RegistreFiscal::class)->enregistrerVente($type, $donnees['total_ttc_centimes'] ?? 0, $donnees, $document)
            : app(RegistreFiscal::class)->enregistrer($chaine, $type, $donnees, $document)));
    }

    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     */
    private function auNomDuSysteme(Closure $operation): mixed
    {
        return app(OperateurCourant::class)->pendantLeSysteme($operation);
    }

    /**
     * Attend le refus de l'appel au registre, fait dans une transaction,
     * puis vérifie la base avant d'annuler cette transaction : ce que le
     * registre aurait écrit avant de refuser s'y voit encore, alors que
     * l'annulation l'effacerait.
     *
     * @param  Closure(RegistreFiscal): mixed  $appel
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): void  $verifierLaBase
     */
    private function assertRefuseAvantDEcrire(Closure $appel, string $exception, string $message, Closure $verifierLaBase): void
    {
        DB::beginTransaction();

        try {
            $this->assertThrows(fn () => $this->auNomDuSysteme(fn () => $appel(app(RegistreFiscal::class))), $exception, $message);
            $verifierLaBase();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Closure(RegistreFiscal): mixed  $appel
     * @param  class-string<Throwable>  $exception
     */
    private function assertRefuseSansRienEcrire(Closure $appel, string $exception, string $message): void
    {
        $this->assertRefuseAvantDEcrire($appel, $exception, $message, function (): void {
            $this->assertDatabaseCount('tetes_registre_fiscal', 0);
            $this->assertDatabaseCount('enregistrements_fiscaux', 0);
        });
    }

    /**
     * Le registre du magasin actif, dans l'ordre d'écriture : chaîne,
     * séquence et type de chaque enregistrement.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function registre(): array
    {
        return EnregistrementFiscal::query()->orderBy('id')->get()
            ->map(fn (EnregistrementFiscal $enregistrement): array => [$enregistrement->chaine->value, $enregistrement->sequence, $enregistrement->type])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function contenu(EnregistrementFiscal $enregistrement): array
    {
        return json_decode($enregistrement->contenu, true, flags: JSON_THROW_ON_ERROR);
    }

    private function clePubliqueDeTest(): string
    {
        return Arr::first(config('caisse_securisee.cles_publiques'));
    }

    /**
     * Le cle_id d'une clé publique PEM, calculé sans le signataire : le
     * SHA-256 du DER SubjectPublicKeyInfo, corps base64 du PEM.
     */
    private function cleIdDe(string $clePubliquePem): string
    {
        return hash('sha256', base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $clePubliquePem)));
    }

    public function test_chaque_chaine_suit_sa_propre_sequence_depuis_1_dans_chaque_magasin(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasinA = $this->magasinActif();
        $this->enregistrer(ChaineFiscale::Vente);
        $this->enregistrer(ChaineFiscale::Encaissement, 'paiement');
        $this->enregistrer(ChaineFiscale::Vente);
        $this->enregistrer(ChaineFiscale::Encaissement, 'paiement');
        $this->enregistrer(ChaineFiscale::Vente);
        $this->magasinActif();

        $premiereVenteDeB = $this->enregistrer(ChaineFiscale::Vente);

        $this->assertSame([
            ['evenement', 1, '260'],
            ['vente', 1, 'ticket'],
        ], $this->registre());
        $this->assertNull($premiereVenteDeB->signature_precedente);
        app(TenantContext::class)->set($magasinA);
        $this->assertSame([
            ['evenement', 1, '260'],
            ['vente', 1, 'ticket'],
            ['encaissement', 1, 'paiement'],
            ['vente', 2, 'ticket'],
            ['encaissement', 2, 'paiement'],
            ['vente', 3, 'ticket'],
        ], $this->registre());
    }

    public function test_chaque_enregistrement_signe_la_signature_du_precedent_de_sa_chaine(): void
    {
        $this->magasinActif();

        $premiereVente = $this->enregistrer(ChaineFiscale::Vente);
        $premierEncaissement = $this->enregistrer(ChaineFiscale::Encaissement, 'paiement');
        $deuxiemeVente = $this->enregistrer(ChaineFiscale::Vente);
        $deuxiemeEncaissement = $this->enregistrer(ChaineFiscale::Encaissement, 'paiement');

        $this->assertNull($premiereVente->signature_precedente);
        $this->assertNull($premierEncaissement->signature_precedente);
        $this->assertSame($premiereVente->signature, $deuxiemeVente->signature_precedente);
        $this->assertSame($premierEncaissement->signature, $deuxiemeEncaissement->signature_precedente);
        $this->assertSame($premiereVente->signature, $this->contenu($deuxiemeVente)['signature_precedente']);
    }

    public function test_le_contenu_signe_se_verifie_tel_quel_et_reprend_l_en_tete_de_l_enregistrement(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:30'));
        $magasin = Magasin::factory()->avecLaSociete(['siren' => '100000009'])->create(['siret' => '10000000900017']);
        app(TenantContext::class)->set($magasin);
        $vendeuse = User::factory()->responsableDeTest($magasin, self::MOT_DE_PASSE_CHOISI)->create(['name' => 'Zoé Lefèvre']);
        $facture = Facture::factory()->create();
        $cleId = $this->cleIdDe($this->clePubliqueDeTest());

        $enregistrement = app(OperateurCourant::class)->pendant($vendeuse, fn (): EnregistrementFiscal => DB::transaction(
            fn (): EnregistrementFiscal => app(RegistreFiscal::class)->enregistrerVente('facture', 12000, ['numero' => 'FAC-LYON1-2026-000001', 'total_ttc_centimes' => 12000, 'acompte' => null, 'professionnel' => true], $facture),
        ));

        $initialisation = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->sole();
        $this->assertSame([
            'format' => 1,
            'magasin' => ['uuid' => $magasin->uuid, 'siren' => '100000009', 'siret' => '10000000900017'],
            'caisse' => $magasin->uuid,
            'chaine' => 'vente',
            'sequence' => 1,
            'type' => 'facture',
            'horodatage' => '20261009081530Z',
            'operateur' => ['id' => $vendeuse->id, 'nom' => 'Zoé Lefèvre'],
            'version_module' => ModuleFiscal::VERSION,
            'cle_id' => $cleId,
            'signature_precedente' => null,
            'gt' => ['montant' => 12000, 'perpetuel_reel' => 12000, 'perpetuel_absolu' => 12000],
            'document' => ['type' => 'facture', 'id' => $facture->id],
            'donnees' => ['numero' => 'FAC-LYON1-2026-000001', 'total_ttc_centimes' => 12000, 'acompte' => null, 'professionnel' => true],
        ], $this->contenu($enregistrement));
        $this->assertStringContainsString('"nom":"Zoé Lefèvre"', $enregistrement->contenu);
        $this->assertSame(1, openssl_verify($enregistrement->contenu, base64_decode(strtr($enregistrement->signature, '-_', '+/')), $this->clePubliqueDeTest(), OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify($initialisation->contenu, base64_decode(strtr($initialisation->signature, '-_', '+/')), $this->clePubliqueDeTest(), OPENSSL_ALGO_SHA256));

        $enregistrement = $enregistrement->fresh();
        $this->assertSame('2026-10-09T08:15:30+00:00', $enregistrement->horodatage->toIso8601String());
        $this->assertSame(Instant::enBase(self::enUtc('2026-10-09 08:15:30')), DB::table('enregistrements_fiscaux')->where('id', $enregistrement->id)->value('horodatage'));
        $this->assertSame('facture', $enregistrement->document_type);
        $this->assertTrue($enregistrement->document->is($facture));
        $this->assertTrue($enregistrement->operateur->is($vendeuse));
        $this->assertSame('ES256', $enregistrement->algorithme);
        $this->assertSame($cleId, $enregistrement->cle_id);
        $this->assertSame(ModuleFiscal::VERSION, $enregistrement->version_module);
    }

    /**
     * Le verrou se prend ici d'abord dans une transaction imbriquée, validée
     * avant la fin de la transaction englobante : le verrou de ligne, lui,
     * reste tenu jusqu'à la validation de celle-ci, et son instant aussi.
     */
    public function test_les_ecritures_d_une_transaction_portent_toutes_l_instant_de_son_verrou(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:30'));
        $magasin = $this->magasinActif();

        [$instant, $redonne, $vente, $encaissement, $evenement] = $this->auNomDuSysteme(fn (): array => DB::transaction(function () use ($magasin): array {
            $registre = app(RegistreFiscal::class);
            [$instant, $vente] = DB::transaction(function () use ($registre, $magasin): array {
                $instant = $registre->verrouiller($magasin);
                $this->travel(5)->seconds();

                return [$instant, $registre->enregistrerVente('ticket', 0, [])];
            });
            $this->travel(5)->seconds();
            $encaissement = $registre->enregistrer(ChaineFiscale::Encaissement, 'paiement', []);

            return DB::transaction(fn (): array => [$instant, $registre->verrouiller($magasin), $vente, $encaissement, $registre->journaliser(CodeEvenement::ParametreDeConformite)]);
        }));
        $suivante = $this->enregistrer(ChaineFiscale::Vente);

        $this->assertSame('2026-10-09T08:15:30+00:00', $instant->utc->toIso8601String());
        $this->assertSame($instant, $redonne);
        $this->assertSame('20261009081530Z', $this->contenu($vente)['horodatage']);
        $this->assertSame('20261009081530Z', $this->contenu($encaissement)['horodatage']);
        $this->assertSame('20261009081530Z', $this->contenu($evenement)['horodatage']);
        $this->assertSame('20261009081540Z', $this->contenu($suivante)['horodatage']);
    }

    /**
     * Un point de sauvegarde annulé efface ce qu'il avait écrit, ligne de
     * tête comprise (ici créée par lui) : ni la vente abandonnée ni son
     * initialisation ne restent. La transaction englobante garde son
     * instant et le note de nouveau comme dernier verrou du registre.
     */
    public function test_un_point_de_sauvegarde_annule_ne_laisse_ni_ses_enregistrements_ni_un_instant_oublie(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:15:30'));
        $this->magasinActif();

        $vente = $this->auNomDuSysteme(fn (): EnregistrementFiscal => DB::transaction(function (): EnregistrementFiscal {
            $registre = app(RegistreFiscal::class);
            rescue(fn () => DB::transaction(function () use ($registre): never {
                $registre->enregistrerVente('ticket', 0, []);

                throw new RuntimeException('Vente abandonnée.');
            }), report: false);
            $this->travel(5)->seconds();

            return $registre->enregistrerVente('ticket', 0, []);
        }));
        $this->travelTo(self::aParis('2026-10-09 10:15:29'));

        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'ticket']], $this->registre());
        $this->assertNull($vente->signature_precedente);
        $this->assertSame('20261009081530Z', $this->contenu($vente)['horodatage']);
        $this->assertThrows(fn () => $this->enregistrer(), HorlogeRegressiveException::class, 'a déjà été verrouillé à 09/10/2026 08:15:30 UTC');
    }

    /**
     * Sous PostgreSQL (READ COMMITTED), une lecture verrouillée du dernier
     * enregistrement d'une chaîne qui a attendu ne revoit que les lignes
     * trouvées avant d'attendre, et ignorerait ce qu'une autre caisse a
     * validé pendant ce temps. Ici le registre est vide, mais la ligne de
     * tête, lue verrouillée, sait qu'une autre caisse du magasin a déjà
     * inscrit l'initialisation et quatre ventes : c'est elle qui décide de
     * la séquence, de la signature précédente et des événements à
     * inscrire.
     */
    public function test_l_etat_des_chaines_se_lit_sur_la_ligne_de_tete_verrouillee_et_non_sur_une_photo_du_registre(): void
    {
        $this->magasinActif();
        Model::unguarded(fn (): TeteRegistreFiscal => TeteRegistreFiscal::create([
            'chaines' => [
                'evenement' => ['sequence' => 1, 'signature' => 'signature-de-l-initialisation'],
                'vente' => ['sequence' => 4, 'signature' => 'signature-de-la-quatrieme-vente', 'gt_reel' => 50000, 'gt_absolu' => 70000],
            ],
            'version_module' => ModuleFiscal::VERSION,
            'cle_id' => $this->cleIdDe($this->clePubliqueDeTest()),
        ]));

        $vente = $this->enregistrer(ChaineFiscale::Vente);

        $this->assertSame([['vente', 5, 'ticket']], $this->registre());
        $this->assertSame('signature-de-la-quatrieme-vente', $vente->signature_precedente);
        $this->assertSame('signature-de-la-quatrieme-vente', $this->contenu($vente)['signature_precedente']);
        $this->assertSame(['montant' => 1250, 'perpetuel_reel' => 51250, 'perpetuel_absolu' => 71250], $this->contenu($vente)['gt']);
    }

    /**
     * Le réel somme les montants, l'absolu leurs valeurs absolues : un
     * avoir diminue l'un et augmente l'autre. Seule la chaîne des ventes
     * les porte, et chaque magasin a les siens.
     */
    public function test_chaque_vente_fait_avancer_les_grands_totaux_reel_et_absolu_du_magasin(): void
    {
        $magasinA = $this->magasinActif();
        $premiere = $this->enregistrer(ChaineFiscale::Vente, 'facture', ['total_ttc_centimes' => 12000]);
        $encaissement = $this->enregistrer(ChaineFiscale::Encaissement, 'paiement', ['montant' => 12000]);
        $deuxieme = $this->enregistrer(ChaineFiscale::Vente, 'ticket', ['total_ttc_centimes' => 5000]);
        $avoir = $this->enregistrer(ChaineFiscale::Vente, 'avoir', ['total_ttc_centimes' => -2000]);
        $this->magasinActif();

        $premiereDeB = $this->enregistrer(ChaineFiscale::Vente, 'ticket', ['total_ttc_centimes' => 700]);

        $this->assertSame(['montant' => 700, 'perpetuel_reel' => 700, 'perpetuel_absolu' => 700], $this->contenu($premiereDeB)['gt']);
        app(TenantContext::class)->set($magasinA);
        $this->assertSame(['montant' => 12000, 'perpetuel_reel' => 12000, 'perpetuel_absolu' => 12000], $this->contenu($premiere)['gt']);
        $this->assertSame(['montant' => 5000, 'perpetuel_reel' => 17000, 'perpetuel_absolu' => 17000], $this->contenu($deuxieme)['gt']);
        $this->assertSame(['montant' => -2000, 'perpetuel_reel' => 15000, 'perpetuel_absolu' => 19000], $this->contenu($avoir)['gt']);
        $this->assertArrayNotHasKey('gt', $this->contenu($encaissement));
        $this->assertSame(['sequence' => 3, 'signature' => $avoir->signature, 'gt_reel' => 15000, 'gt_absolu' => 19000], TeteRegistreFiscal::query()->sole()->chaines['vente']);
    }

    public function test_le_registre_refuse_d_ecrire_hors_transaction(): void
    {
        $this->magasinActif();

        $this->assertThrows(
            fn () => $this->auNomDuSysteme(fn () => app(RegistreFiscal::class)->enregistrerVente('ticket', 0, [])),
            LogicException::class,
            'Le registre fiscal ne se verrouille que dans une transaction',
        );

        $this->assertDatabaseCount('tetes_registre_fiscal', 0);
        $this->assertDatabaseCount('enregistrements_fiscaux', 0);
    }

    public function test_le_registre_ne_se_verrouille_que_pour_le_magasin_actif(): void
    {
        $autreMagasin = Magasin::factory()->create();
        $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->verrouiller($autreMagasin),
            LogicException::class,
            'Le registre fiscal ne se verrouille que pour le magasin actif.',
        );
    }

    public function test_sans_cle_de_signature_le_registre_ne_se_verrouille_pas(): void
    {
        config(['caisse_securisee.cle_privee' => null]);
        $magasin = $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->verrouiller($magasin),
            ModuleFiscalNonConfigureException::class,
            'la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente',
        );
    }

    public function test_une_horloge_qui_recule_est_refusee_et_signalee(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:05'));
        $magasin = $this->magasinActif();
        $this->enregistrer();
        $this->travelTo(self::aParis('2026-10-09 10:00:04'));
        Log::spy();

        $this->assertRefuseAvantDEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrerVente('ticket', 0, []),
            HorlogeRegressiveException::class,
            "L'horloge du serveur a reculé : il est 09/10/2026 08:00:04 UTC, mais le registre fiscal du magasin n°{$magasin->id} a déjà été verrouillé à 09/10/2026 08:00:05 UTC.",
            fn () => $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'ticket']], $this->registre()),
        );

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_un_verrou_sans_ecriture_fixe_aussi_l_heure_sous_laquelle_l_horloge_ne_recule_plus(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:05'));
        $magasin = $this->magasinActif();
        DB::transaction(fn (): InstantFiscal => app(RegistreFiscal::class)->verrouiller($magasin));
        $this->travelTo(self::aParis('2026-10-09 10:00:04'));

        $this->assertRefuseAvantDEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrerVente('ticket', 0, []),
            HorlogeRegressiveException::class,
            "le registre fiscal du magasin n°{$magasin->id} a déjà été verrouillé à 09/10/2026 08:00:05 UTC.",
            fn () => $this->assertDatabaseCount('enregistrements_fiscaux', 0),
        );
    }

    public function test_le_premier_enregistrement_du_magasin_est_precede_de_l_initialisation_et_elle_seule(): void
    {
        $this->magasinActif();

        $this->enregistrer();
        $this->enregistrer();

        $initialisation = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->sole();
        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'ticket'], ['vente', 2, 'ticket']], $this->registre());
        $this->assertSame([
            'code' => 260,
            'libelle' => 'Initialisation du registre fiscal du magasin',
            'details' => [],
        ], $this->contenu($initialisation)['donnees']);
    }

    /**
     * @return array<string, array{0: string, 1: CodeEvenement}>
     */
    public static function monteesDeVersion(): array
    {
        return [
            'version mineure' => ['1.1', CodeEvenement::VersionMineure],
            'version majeure' => ['2.0', CodeEvenement::VersionMajeure],
        ];
    }

    #[DataProvider('monteesDeVersion')]
    public function test_un_changement_de_version_du_module_est_journalise_avant_l_enregistrement(string $nouvelleVersion, CodeEvenement $code): void
    {
        $this->magasinActif();
        $this->app->instance(RegistreFiscal::class, new RegistreFiscal(app(SignataireFiscal::class), '1.0'));
        $this->enregistrer();
        $this->app->instance(RegistreFiscal::class, new RegistreFiscal(app(SignataireFiscal::class), $nouvelleVersion));

        $vente = $this->enregistrer();

        $changement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('sequence', 2)->sole();
        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'ticket'], ['evenement', 2, (string) $code->value], ['vente', 2, 'ticket']], $this->registre());
        $this->assertSame(['ancienne_valeur' => '1.0', 'nouvelle_valeur' => $nouvelleVersion], $this->contenu($changement)['donnees']['details']);
        $this->assertSame($nouvelleVersion, $changement->version_module);
        $this->assertSame($nouvelleVersion, $vente->version_module);
    }

    public function test_un_changement_de_cle_de_signature_est_journalise_avant_l_enregistrement(): void
    {
        $this->magasinActif();
        $this->enregistrer();
        $ancienneCleId = $this->cleIdDe($this->clePubliqueDeTest());
        $nouvellePaire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($nouvellePaire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest(), 'nouvelle.pem' => $nouvellePaire['cle_publique']],
        ]);
        $this->app->forgetInstance(RegistreFiscal::class);

        $vente = $this->enregistrer();

        $changement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('sequence', 2)->sole();
        $this->assertSame([['evenement', 1, '260'], ['vente', 1, 'ticket'], ['evenement', 2, '270'], ['vente', 2, 'ticket']], $this->registre());
        $this->assertSame(['parametre' => 'cle_id', 'ancienne_valeur' => $ancienneCleId, 'nouvelle_valeur' => $nouvellePaire['cle_id']], $this->contenu($changement)['donnees']['details']);
        $this->assertSame($nouvellePaire['cle_id'], $changement->cle_id);
        $this->assertSame($nouvellePaire['cle_id'], $vente->cle_id);
        $this->assertSame(1, openssl_verify($vente->contenu, base64_decode(strtr($vente->signature, '-_', '+/')), $nouvellePaire['cle_publique'], OPENSSL_ALGO_SHA256));
    }

    public function test_journaliser_inscrit_le_code_le_libelle_et_les_details_de_l_evenement(): void
    {
        $this->magasinActif();
        $details = ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_normal'];

        $evenement = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, $details));

        $this->assertSame([['evenement', 1, '260'], ['evenement', 2, '270']], $this->registre());
        $this->assertSame($evenement->signature_precedente, EnregistrementFiscal::query()->where('type', '260')->value('signature'));
        $this->assertSame([
            'code' => 270,
            'libelle' => 'Modification d\'un paramètre ayant un impact sur la conformité',
            'details' => $details,
        ], $this->contenu($evenement)['donnees']);
        $this->assertDatabaseCount('traces_evenements', 0);
    }

    /**
     * S13 : l'adresse IP et le navigateur de la requête HTTP qui fait
     * inscrire un événement technique vont dans sa trace, rangée à part et
     * effacée après la durée de conservation des traces (1 an par défaut),
     * jamais dans son contenu signé.
     */
    public function test_journaliser_range_l_adresse_ip_et_le_navigateur_dans_la_trace(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->magasinActif();
        Route::get('/test/journaliser', function () use ($magasin): int {
            app(TenantContext::class)->set($magasin);

            return DB::transaction(fn (): int => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite)->id);
        });

        $reponse = $this->withHeader('User-Agent', 'Mozilla/5.0 (Navigateur de test)')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/test/journaliser');

        $evenement = EnregistrementFiscal::query()->findOrFail($reponse->getContent());
        $this->assertSame(['code', 'libelle', 'details'], array_keys($this->contenu($evenement)['donnees']));
        $this->assertSame(
            ['203.0.113.7', 'Mozilla/5.0 (Navigateur de test)', self::enUtc('2027-10-09 08:00:00')->getTimestamp()],
            [$evenement->trace->ip, $evenement->trace->agent_utilisateur, $evenement->trace->expire_le->getTimestamp()],
        );
    }

    /**
     * Ni l'événement demandé ni celui qu'il fait inscrire d'office avant
     * lui (l'initialisation du registre) ne signent l'adresse IP ou le
     * navigateur de la requête : chacun n'a que sa trace.
     */
    public function test_aucun_contenu_signe_ne_contient_d_adresse_ip_ni_de_navigateur(): void
    {
        $magasin = $this->magasinActif();
        Route::get('/test/journaliser', function () use ($magasin): string {
            app(TenantContext::class)->set($magasin);
            DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite));

            return 'ok';
        });

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Navigateur de test)')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/test/journaliser')
            ->assertSee('ok');

        $evenements = EnregistrementFiscal::query()->orderBy('id')->get();
        $this->assertSame([['evenement', 1, '260'], ['evenement', 2, '270']], $this->registre());
        foreach ($evenements as $evenement) {
            $this->assertStringNotContainsString('203.0.113.7', $evenement->contenu);
            $this->assertStringNotContainsString('Navigateur de test', $evenement->contenu);
        }
        $this->assertSame($evenements->modelKeys(), TraceEvenement::query()->orderBy('enregistrement_fiscal_id')->pluck('enregistrement_fiscal_id')->all());
    }

    public function test_un_evenement_technique_ne_s_inscrit_que_par_journaliser(): void
    {
        $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrer(ChaineFiscale::Evenement, '270', []),
            LogicException::class,
            'journaliser()',
        );
    }

    public function test_une_vente_ne_s_inscrit_qu_avec_son_montant(): void
    {
        $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrer(ChaineFiscale::Vente, 'ticket', ['total_ttc_centimes' => 1250]),
            LogicException::class,
            'Une vente s\'inscrit par enregistrerVente(), avec son montant',
        );
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function donneesNonSignables(): array
    {
        return [
            'montant à virgule flottante' => [['total_ttc' => 12.5], 'donnees.total_ttc : un montant fiscal s\'écrit en centimes entiers'],
            'montant à virgule flottante dans une ligne' => [['lignes' => [['quantite' => 2, 'pu_ttc' => 6.0]]], 'donnees.lignes.0.pu_ttc : un montant fiscal s\'écrit en centimes entiers'],
            'objet' => [['emise_le' => now()], 'donnees.emise_le : seuls null, booléens, entiers, chaînes et tableaux'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $donnees
     */
    #[DataProvider('donneesNonSignables')]
    public function test_une_donnee_qui_ne_se_relit_pas_a_l_identique_est_refusee_sans_rien_ecrire(array $donnees, string $message): void
    {
        $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrerVente('ticket', 0, $donnees),
            InvalidArgumentException::class,
            $message,
        );
    }

    public function test_un_document_d_un_autre_magasin_est_refuse(): void
    {
        $this->magasinActif();
        $factureDUnAutreMagasin = Facture::factory()->create();
        $this->magasinActif();

        $this->assertRefuseSansRienEcrire(
            fn (RegistreFiscal $registre) => $registre->enregistrerVente('facture', 0, [], $factureDUnAutreMagasin),
            LogicException::class,
            'Le registre fiscal n\'enregistre qu\'un document déjà enregistré du magasin actif.',
        );
    }

    public function test_le_modele_refuse_de_modifier_un_enregistrement(): void
    {
        $this->magasinActif();
        $enregistrement = $this->enregistrer();
        $contenu = $enregistrement->contenu;

        $this->assertThrows(fn () => $enregistrement->forceFill(['contenu' => '{}'])->save(), DonneeFiscaleInalterableException::class);

        $this->assertSame($contenu, DB::table('enregistrements_fiscaux')->where('id', $enregistrement->id)->value('contenu'));
    }

    public function test_le_modele_refuse_de_supprimer_un_enregistrement(): void
    {
        $this->magasinActif();
        $enregistrement = $this->enregistrer();

        $this->assertThrows(fn () => $enregistrement->delete(), DonneeFiscaleInalterableException::class);

        $this->assertModelExists($enregistrement);
    }

    /**
     * @return array<string, array{0: Closure(int): mixed}>
     */
    public static function modificationsSansLeModele(): array
    {
        return [
            'DB::table()' => [static fn (int $id): int => DB::table('enregistrements_fiscaux')->where('id', $id)->update(['contenu' => '{}'])],
            'update() en masse' => [static fn (int $id): int => EnregistrementFiscal::query()->whereKey($id)->update(['contenu' => '{}'])],
        ];
    }

    /**
     * @param  Closure(int): mixed  $modifier
     */
    #[DataProvider('modificationsSansLeModele')]
    public function test_la_base_refuse_de_modifier_un_enregistrement(Closure $modifier): void
    {
        $this->magasinActif();
        $enregistrement = $this->enregistrer();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $modifier($enregistrement->id)), QueryException::class, 'enregistrements_fiscaux : table en ajout seul, modification refusée');

        $this->assertSame($enregistrement->contenu, DB::table('enregistrements_fiscaux')->where('id', $enregistrement->id)->value('contenu'));
    }

    /**
     * @return array<string, array{0: Closure(int): mixed}>
     */
    public static function suppressionsSansLeModele(): array
    {
        return [
            'DB::table()' => [static fn (int $id): int => DB::table('enregistrements_fiscaux')->where('id', $id)->delete()],
            'delete() en masse' => [static fn (int $id): mixed => EnregistrementFiscal::query()->whereKey($id)->delete()],
        ];
    }

    /**
     * @param  Closure(int): mixed  $supprimer
     */
    #[DataProvider('suppressionsSansLeModele')]
    public function test_la_base_refuse_de_supprimer_un_enregistrement(Closure $supprimer): void
    {
        $this->magasinActif();
        $enregistrement = $this->enregistrer();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => $supprimer($enregistrement->id)), QueryException::class, 'enregistrements_fiscaux : table en ajout seul, suppression refusée');

        $this->assertModelExists($enregistrement);
    }
}
