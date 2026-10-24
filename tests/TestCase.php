<?php

namespace Tests;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\GardesDesDroits;
use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Testing\TestResponse;
use Laravel\SerializableClosure\SerializableClosure;
use Livewire\Features\SupportTesting\Testable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

abstract class TestCase extends BaseTestCase
{
    /**
     * La paire de clés de signature fiscale des tests, générée une seule
     * fois par processus : aucune clé n'est jamais commitée.
     *
     * @var array{cle_id: string, cle_privee: string, cle_publique: string}|null
     */
    private static ?array $paireFiscaleDesTests = null;

    /**
     * La clé du journal d'audit des tests, tirée une seule fois par
     * processus : aucune clé n'est jamais commitée.
     */
    private static ?string $cleDuJournalAuditDesTests = null;

    /**
     * Chaque application de test signe avec la paire éphémère des tests,
     * seule clé épinglée, et chaîne son journal d'audit avec la clé
     * éphémère des tests : ni la clé d'un .env local ni une clé épinglée
     * dans le dépôt n'entrent dans les tests.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        self::$paireFiscaleDesTests ??= SignataireFiscal::genererUnePaire();
        self::$cleDuJournalAuditDesTests ??= base64_encode(random_bytes(32));

        $app['config']->set([
            'caisse_securisee.cle_privee' => base64_encode(self::$paireFiscaleDesTests['cle_privee']),
            'caisse_securisee.phrase_secrete' => null,
            'caisse_securisee.cles_publiques' => ['tests.pem' => self::$paireFiscaleDesTests['cle_publique']],
            'journal_audit.cle' => self::$cleDuJournalAuditDesTests,
            'journal_audit.cle_id' => 'tests',
            'journal_audit.cles_precedentes' => [],
        ]);

        return $app;
    }

    /**
     * Aucun test n'interroge un service réel : une requête HTTP sortante
     * sans réponse simulée (Http::fake()) échoue au lieu de partir.
     *
     * Aucun test ne dépend du hasard : Faker et Str::random() sont semés
     * au début de chaque test par une graine tirée du nom de sa classe, de
     * sa méthode et de son jeu de données (voir graineDuTest()), si bien
     * qu'un test tire les mêmes valeurs à chaque exécution, dans quelque
     * ordre et quelque processus qu'il tourne. Faker sème le générateur de
     * PHP (mt_srand) : la graine atteint ainsi le générateur de la locale
     * de l'application que partagent fake() et les fabriques. Un
     * générateur de Faker resté d'un test précédent ressème au hasard ce
     * générateur de PHP en se détruisant (Faker\Generator::__destruct()),
     * et le ramasse-miettes peut le détruire en plein test : il est donc
     * détruit avant que ce test ne sème le sien. Str::random() donne
     * l'identifiant de chaque composant Livewire et le jeton CSRF d'une
     * page, que lisent les assertions sur le HTML : il tire ses chaînes
     * d'un générateur à lui (voir chainesAleatoiresSemees()), que Laravel
     * remet au hasard à la fin du test (Str::resetFactoryState()).
     */
    protected function setUp(): void
    {
        parent::setUp();

        gc_collect_cycles();
        fake()->seed($this->graineDuTest());
        Str::createRandomStringsUsing(self::chainesAleatoiresSemees($this->graineDuTest()));
        Http::preventStrayRequests();
    }

    /**
     * La graine du hasard de ce test : l'empreinte CRC32 du nom de sa
     * classe, de sa méthode et de son jeu de données.
     */
    protected function graineDuTest(): int
    {
        return crc32(static::class.'::'.$this->nameWithDataSet());
    }

    /**
     * Le tirage de Str::random() semé par cette graine : des lettres et
     * des chiffres, comme le tirage de Laravel, toujours les mêmes dans le
     * même ordre pour une même graine.
     *
     * @return Closure(int): string
     */
    protected static function chainesAleatoiresSemees(int $graine): Closure
    {
        $hasard = new Randomizer(new Xoshiro256StarStar($graine));

        return fn (int $longueur): string => $longueur > 0
            ? $hasard->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $longueur)
            : '';
    }

    /**
     * Une société et un magasin que son propriétaire y crée (voir
     * CreerEtablissementAction), son établissement vérifié : l'identité de
     * la société est celle que le répertoire Sirene préremplit à
     * l'inscription (voir SocieteFactory::commeApresLInscription()), sans
     * capital ni immatriculation. La société est ensuite validée, et le
     * SIRET du magasin réservé, comme à sa validation ; le magasin est en activité depuis
     * MagasinFactory::DEBUT_D_ACTIVITE, avant toute journée que visitent
     * les tests. Avec ['a_verifier' => true], la société reste en cours de
     * vérification. Le magasin devient le magasin actif.
     *
     * Les options nom, code, nic et email sont celles du magasin ; l'option
     * siren est celui de la société, qu'elle a dès sa création (il ne change
     * jamais) ; toute autre, sauf a_verifier, se règle sur la société avant
     * la création du magasin (regime_tva...). Avec $proprietaire, le
     * propriétaire d'une société sans magasin (voir UserFactory), le magasin
     * s'ouvre dans la société de ce compte, sans option siren.
     *
     * @param  array<string, mixed>  $options
     */
    protected function ouvrirUneSociete(array $options = [], ?User $proprietaire = null): Magasin
    {
        app(TenantContext::class)->clear();
        $this->assertFalse($proprietaire !== null && array_key_exists('siren', $options), 'Le SIREN d\'une société existante ne change jamais.');
        $proprietaire ??= User::factory()->create(array_key_exists('siren', $options) ? ['societe_id' => Societe::factory()->state(['siren' => $options['siren']])->commeApresLInscription()] : []);
        $societe = $proprietaire->societe;
        $reglages = Arr::except($options, ['nom', 'code', 'nic', 'email', 'a_verifier', 'siren']);

        if ($reglages !== []) {
            app(TenantContext::class)->runAsSociete($societe, fn (): bool => $societe->forceFill($reglages)->save());
        }

        $magasin = app(CreerEtablissementAction::class)->handle($proprietaire, $societe, [
            'nom' => $options['nom'] ?? 'Ma Boutique',
            'code' => $options['code'] ?? null,
            'email' => $options['email'] ?? null,
            'nic' => $options['nic'] ?? MagasinFactory::nicLibre($societe),
        ]);

        if (! ($options['a_verifier'] ?? false)) {
            $societe->forceFill(['validee_le' => now()])->save();
            app(SiretsDesMagasins::class)->reserver($magasin);
            MagasinFactory::ouvrirSaPremierePeriode($magasin, $proprietaire);
        }

        $magasin = $magasin->fresh();
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Une société inscrite comme par la confirmation de son lien (voir
     * CreerSocieteAction), qui attend le code de son courrier recommandé,
     * sans magasin : son entreprise contrôlée au répertoire Sirene factice,
     * son propriétaire au nom de Jeanne DUPONT, la présidente que donne le
     * registre national factice, son mot de passe MOT_DE_PASSE_CHOISI, et
     * son premier courrier parti par le prestataire factice. Aucun magasin
     * n'est le magasin actif.
     */
    protected function inscrireUneSociete(?string $siren = null, ?string $email = null): Societe
    {
        app(TenantContext::class)->clear();

        return app(CreerSocieteAction::class)->handle($siren ?? SocieteFactory::sirenFictif(), $email ?? fake()->unique()->safeEmail(), self::MOT_DE_PASSE_CHOISI);
    }

    /**
     * Le code que porte le dernier courrier de ce motif (la vérification,
     * sauf autre motif) de la société, tel que son destinataire le lit
     * (XXXX-XXXX-XXXX) dans le PDF que le prestataire factice a reçu : le
     * logiciel n'en garde que l'empreinte.
     */
    protected function codeDuCourrier(Societe $societe, MotifCode $motif = MotifCode::Verification): string
    {
        $reference = (string) app(TenantContext::class)->runAsSociete($societe, fn () => CodeConfirmation::query()
            ->where('motif', $motif->value)
            ->orderByDesc('id')
            ->value('reference_envoi'));
        $pdf = app(ServiceEnvoiRecommande::class)->envois()[$reference]['pdf'];
        $texte = implode(' ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));

        $this->assertMatchesRegularExpression('/\b[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}\b/', $texte);
        preg_match('/\b([0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4})\b/', $texte, $code);

        return $code[1];
    }

    /**
     * Le propriétaire inscrit saisit le code de son courrier sur la page du
     * code, avec l'adresse et le mot de passe de son inscription (voir
     * SaisirCodeDeConfirmationAction) : la société est validée. Rend le
     * propriétaire.
     */
    protected function saisirLeCodeDuCourrier(Societe $societe): User
    {
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();

        return app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $this->codeDuCourrier($societe), (string) $proprietaire->email, self::MOT_DE_PASSE_CHOISI, '', '127.0.0.1');
    }

    /**
     * Une autre personne que celle qui a inscrit la société saisit le code
     * de son courrier, avec cette adresse et le mot de passe
     * MOT_DE_PASSE_CHOISI (voir SaisirCodeDeConfirmationAction) : la
     * société est validée et cette personne en devient le propriétaire, par
     * un nouveau compte dont l'adresse attend la confirmation de son lien.
     * Rend le nouveau propriétaire.
     */
    protected function saisirLeCodeCommeUneAutrePersonne(Societe $societe, string $email): User
    {
        $code = $this->codeDuCourrier($societe);
        app(TenantContext::class)->clear();

        return app(SaisirCodeDeConfirmationAction::class)->handle((string) $societe->siren, $code, $email, self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI, '127.0.0.1');
    }

    /**
     * Le jeton du dernier lien de confirmation d'une adresse de connexion
     * envoyé à cette adresse (voir DemanderUneNouvelleAdresseAction), sous
     * Mail::fake().
     */
    protected function jetonDeLAdresseEnAttente(string $adresse): string
    {
        $mail = Mail::sent(ConfirmationNouvelleAdresseMail::class, fn (ConfirmationNouvelleAdresseMail $mail): bool => $mail->hasTo($adresse))->last();
        $this->assertInstanceOf(ConfirmationNouvelleAdresseMail::class, $mail, "Aucun lien de confirmation n'est parti à {$adresse}.");

        return Str::afterLast($mail->lien, '/');
    }

    /**
     * Le magasin que le propriétaire crée dans l'espace de sa société (voir
     * CreerEtablissementAction), sur l'établissement de son siège au
     * répertoire Sirene factice, ou sur ce NIC. Aucun magasin n'est le
     * magasin actif ensuite.
     */
    protected function creerLeMagasinDuSiege(Societe $societe, string $nom = 'Ma Boutique', ?string $nic = null): Magasin
    {
        app(TenantContext::class)->clear();

        return app(CreerEtablissementAction::class)->handle($societe->proprietaire()->firstOrFail(), $societe, [
            'nom' => $nom,
            'nic' => $nic ?? substr(FauxRepertoireSirene::siretDuSiege($societe->siren), 9),
        ]);
    }

    /**
     * Un autre magasin de la société de $magasin, créé par son propriétaire
     * (voir CreerEtablissementAction) : dans une société validée, son SIRET
     * se réserve, sa période d'activité s'ouvre et les droits implicites du
     * propriétaire s'inscrivent à son journal des événements. Il devient le
     * magasin actif.
     *
     * @param  array{nom?: string, code?: string, nic?: string, email?: string}  $donnees
     */
    protected function ouvrirUnAutreMagasin(Magasin $magasin, array $donnees = []): Magasin
    {
        $societe = $magasin->societe;
        $autre = app(CreerEtablissementAction::class)->handle($this->proprietaireDe($magasin), $societe, [
            'nom' => $donnees['nom'] ?? 'Autre boutique',
            'code' => $donnees['code'] ?? null,
            'email' => $donnees['email'] ?? null,
            'nic' => $donnees['nic'] ?? MagasinFactory::nicLibre($societe),
        ]);
        app(TenantContext::class)->set($autre);

        return $autre;
    }

    /**
     * Règle la société du magasin, comme son propriétaire dans les
     * paramètres de la société : un réglage de conformité (régime de TVA,
     * option pour les débits...) s'inscrit au journal des événements de
     * chacun de ses magasins (voir JournaliseSesParametresDeConformite).
     *
     * @param  array<string, mixed>  $reglages
     */
    protected function reglerLaSociete(Magasin $magasin, array $reglages): Societe
    {
        $societe = $magasin->societe()->firstOrFail();
        $societe->forceFill($reglages)->save();
        $magasin->setRelation('societe', $societe);

        return $societe;
    }

    /**
     * Le mot de passe que la personne choisit elle-même, après son
     * identifiant : il suit la règle du mot de passe choisi (voir
     * RegleDuMotDePasse).
     */
    protected const MOT_DE_PASSE_CHOISI = 'mot-de-passe-sur';

    /**
     * Un nouveau membre du magasin, comme dans le vrai parcours : créé par
     * le propriétaire pour ce seul magasin (voir CreerMembreAction), avec
     * les autorisations données ou, sans liste, celles du modèle de son
     * rôle (aucune pour un responsable, qui n'a pas de case), puis la
     * personne choisit son mot de passe dans son profil (voir
     * ChangerSonMotDePasseAction) ; une adresse donnée, elle la renseigne
     * elle-même dans son profil (voir renseignerSonAdresse()). Le magasin
     * reste ensuite le magasin actif.
     *
     * @param  list<string>|null  $autorisations
     * @param  array{name?: string, email?: string}  $identite
     */
    protected function membre(Magasin $magasin, RoleMagasin $role, ?array $autorisations = null, array $identite = []): User
    {
        $compte = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle(
            $this->proprietaireDe($magasin),
            $magasin,
            ['name' => $identite['name'] ?? fake()->name()],
            $role,
            $autorisations,
        ));

        if (isset($identite['email'])) {
            $this->renseignerSonAdresse($compte, $identite['email']);
        }

        return $this->choisirSonMotDePasse($compte);
    }

    /**
     * Les cases que précochait le modèle « comptable » de magasin, que
     * l'éditeur a supprimé (un expert-comptable de la société, en lecture
     * seule, le remplace) : un vendeur à qui on les coche garde, dans les
     * tests des policies, les mêmes droits que l'ancien comptable.
     *
     * @return list<string>
     */
    protected static function casesDeLAncienComptable(): array
    {
        return [
            PermissionRegistry::CLIENT_VIEW,
            PermissionRegistry::FOURNISSEUR_VIEW,
            PermissionRegistry::PRODUIT_VIEW,
            PermissionRegistry::DEVIS_VIEW,
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::AVOIR_FOURNISSEUR_VIEW,
            PermissionRegistry::FACTURE_VIEW_TOUTES,
            PermissionRegistry::FACTURE_ENVOYER,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::PAIEMENT_CREER,
            PermissionRegistry::PAIEMENT_CONTRE_PASSER,
            PermissionRegistry::AVOIR_VIEW,
            PermissionRegistry::AVOIR_ENVOYER,
            PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK,
            PermissionRegistry::CLOTURE_CAISSE_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
            PermissionRegistry::CAISSE_SECURISEE_CONSULTER,
        ];
    }

    /**
     * Un vendeur du magasin, comme membre(), créé au début de l'activité
     * des magasins de test (MagasinFactory::DEBUT_D_ACTIVITE), avant toute
     * opération du test : un test qui voyage ensuite dans le temps ne
     * trouve pas le registre du magasin verrouillé à une heure postérieure
     * à ses ventes. La société du magasin reçoit son propriétaire si elle
     * n'en a pas encore.
     *
     * @param  list<string>|null  $autorisations
     */
    protected function vendeurDeLaPremiereHeure(Magasin $magasin, ?array $autorisations = null): User
    {
        $maintenant = CarbonImmutable::now();
        $this->travelTo(CarbonImmutable::parse(MagasinFactory::DEBUT_D_ACTIVITE, config('app.timezone')));

        try {
            if ($magasin->societe()->firstOrFail()->proprietaire()->doesntExist()) {
                User::factory()->for($magasin->societe)->create();
            }

            return $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        } finally {
            $this->travelTo($maintenant);
        }
    }

    /**
     * Un responsable de ce magasin, créé par le propriétaire pour ce seul
     * magasin, son mot de passe choisi.
     */
    protected function responsable(Magasin $magasin): User
    {
        return $this->membre($magasin, RoleMagasin::Responsable);
    }

    /**
     * Un administrateur de la société du magasin, créé par le propriétaire
     * (voir CreerAdministrateurAction), son mot de passe choisi : de tous
     * les magasins, ou de la sélection donnée, avec ces autorisations de
     * société et ces cases de magasin (le modèle de l'administrateur à
     * défaut).
     *
     * @param  list<int>|null  $selection
     * @param  list<string>  $autorisationsSociete
     * @param  array<int|string, mixed>|null  $autorisationsMagasin
     */
    protected function administrateur(Magasin $magasin, ?array $selection = null, array $autorisationsSociete = [], ?array $autorisationsMagasin = null): User
    {
        $compte = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerAdministrateurAction::class)->handle(
            $this->proprietaireDe($magasin),
            $magasin->societe()->firstOrFail(),
            ['name' => fake()->name()],
            $selection,
            $autorisationsMagasin ?? PermissionRegistry::modeleDeLAdministrateur(),
            $autorisationsSociete,
        ));

        return $this->choisirSonMotDePasse($compte);
    }

    /**
     * Un expert-comptable de la société du magasin, créé par le
     * propriétaire (voir CreerExpertComptableAction), son mot de passe
     * choisi.
     */
    protected function expertComptable(Magasin $magasin): User
    {
        $compte = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerExpertComptableAction::class)->handle(
            $this->proprietaireDe($magasin),
            $magasin->societe()->firstOrFail(),
            ['name' => fake()->name()],
        ));

        return $this->choisirSonMotDePasse($compte);
    }

    /**
     * Un membre du magasin créé par le propriétaire, dont le mot de passe
     * est encore son identifiant : le compte et son identifiant.
     *
     * @return array{0: User, 1: string}
     */
    protected function compteAuMotDePasseIdentifiant(Magasin $magasin, RoleMagasin $role): array
    {
        $compte = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle(
            $this->proprietaireDe($magasin),
            $magasin,
            ['name' => fake()->name()],
            $role,
        ));

        return [$compte, (string) $compte->identifiantDeConnexion()];
    }

    /**
     * La personne choisit son mot de passe dans son profil, à la place de
     * son identifiant ; le magasin actif reste le même.
     */
    protected function choisirSonMotDePasse(User $compte): User
    {
        app(ChangerSonMotDePasseAction::class)->handle($compte, self::MOT_DE_PASSE_CHOISI);

        return $compte->refresh();
    }

    /**
     * La personne renseigne elle-même son adresse de contact, comme dans
     * son profil (voir ModifierProfil::save()) : le seul chemin qui écrit
     * l'adresse d'un compte qui n'est pas propriétaire.
     */
    protected function renseignerSonAdresse(User $compte, string $adresse): User
    {
        $compte->forceFill(['email' => User::normaliserEmail($adresse)])->save();

        return $compte->refresh();
    }

    /**
     * Le propriétaire demande la transmission de la propriété à cet
     * administrateur (voir DemanderLaTransmissionDeLaProprieteAction) : le
     * jeton du lien parti à l'adresse de l'administrateur, lu dans le
     * courriel retenu par la fausse messagerie.
     */
    protected function demanderLaTransmission(User $proprietaire, User $administrateur, string $motDePasse = 'password'): string
    {
        if (! Mail::getFacadeRoot() instanceof MailFake) {
            Mail::fake();
        }

        app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, $motDePasse);

        return $this->jetonDuDernierLienDeTransmission();
    }

    /**
     * Le jeton du dernier lien de transmission de la propriété envoyé.
     */
    protected function jetonDuDernierLienDeTransmission(): string
    {
        $lien = (string) Mail::sent(TransmissionDeLaProprieteMail::class)->last()?->lien;

        return Str::afterLast($lien, '/');
    }

    /**
     * Un administrateur de tous les magasins qui remplit les conditions de
     * la transmission de la propriété : son mot de passe choisi, son
     * adresse renseignée par lui.
     *
     * @param  list<string>|null  $autorisationsMagasin
     */
    protected function administrateurPretAReprendre(Magasin $magasin, string $adresse = 'repreneur@example.test', ?array $autorisationsMagasin = null): User
    {
        return $this->renseignerSonAdresse($this->administrateur($magasin, null, AutorisationsSociete::toutes(), $autorisationsMagasin), $adresse);
    }

    /**
     * Retire une garde des droits de la base (voir GardesDesDroits), comme
     * le ferait un administrateur de la base, pour y glisser le temps d'un
     * test une ligne qu'elle refuse : la transaction du test la rétablit.
     */
    protected function retirerLaGardeDesDroits(string $garde): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("DROP TRIGGER {$garde} ON ".match ($garde) {
                GardesDesDroits::AFFECTATIONS => 'affectations',
                GardesDesDroits::CASES => 'autorisations_affectations',
                GardesDesDroits::AUTORISATIONS_SOCIETE => 'autorisations_societe',
                GardesDesDroits::ROLES => 'users',
            });

            return;
        }

        foreach (GardesDesDroits::declencheursSqlite($garde) as $declencheur) {
            DB::unprepared("DROP TRIGGER {$declencheur}");
        }
    }

    /**
     * Ce compte, connecté, dans l'espace d'administration de la société,
     * comme pour un écran de ce panneau testé par Livewire. La session est
     * repartie de zéro : celle d'un compte connecté avant n'y reste pas.
     */
    protected function dansLEspaceDeLaSociete(Societe $societe, User $compte): void
    {
        $this->flushSession();
        app(TenantContext::class)->clear();
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe, isQuiet: true);
        app(TenantContext::class)->poserSociete($societe);
    }

    /**
     * Ce compte, connecté, dans le panneau de ce magasin, comme pour un
     * écran de ce panneau testé par Livewire. La session est repartie de
     * zéro.
     */
    protected function dansLeMagasin(Magasin $magasin, User $compte): void
    {
        $this->flushSession();
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin, isQuiet: true);
        app(TenantContext::class)->set($magasin);
    }

    /**
     * Une requête forgée : l'action d'en-tête de la page, appelée par
     * Livewire comme si son bouton était là, avec ces données. Une action
     * masquée ou non autorisée ne se monte pas, et rien ne s'exécute.
     *
     * @param  array<string, mixed>  $donnees
     */
    protected function forgerLAction(Testable $page, string $action, array $donnees = []): Testable
    {
        return $page->call('mountAction', $action)->set('mountedActionsData.0', $donnees)->call('callMountedAction');
    }

    /**
     * Une requête forgée sur l'action d'une ligne d'un tableau, pour cet
     * enregistrement, avec ces données.
     *
     * @param  array<string, mixed>  $donnees
     */
    protected function forgerLActionDeLigne(Testable $page, string $action, Model $enregistrement, array $donnees = []): Testable
    {
        return $page->call('mountTableAction', $action, (string) $enregistrement->getKey())->set('mountedTableActionsData.0', $donnees)->call('callMountedTableAction');
    }

    /**
     * L'instantané Livewire du composant de cette page HTML, tel que le
     * navigateur le renverrait : la page reste ouverte, et la requête
     * suivante le rejoue (voir requeteLivewire()).
     */
    protected function instantaneLivewire(string $html, string $nomDuComposant): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $trouves);

        foreach ($trouves[1] as $brut) {
            $instantane = html_entity_decode($brut, ENT_QUOTES);

            if (str_contains(json_decode($instantane, true)['memo']['name'] ?? '', $nomDuComposant)) {
                return $instantane;
            }
        }

        $this->fail("Aucun composant {$nomDuComposant} dans la page.");
    }

    /**
     * Une vraie requête Livewire (POST /livewire/update), comme celle d'un
     * navigateur dont la page est restée ouverte : elle repasse par les
     * intergiciels persistants du panneau, que Livewire::test ne rejoue
     * pas.
     *
     * @param  array<string, mixed>  $modifications
     * @param  list<mixed>  $parametres
     */
    protected function requeteLivewire(string $instantane, array $modifications, string $methode, array $parametres = []): TestResponse
    {
        return $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
            'components' => [[
                'snapshot' => $instantane,
                'updates' => $modifications,
                'calls' => [['path' => '', 'method' => $methode, 'params' => $parametres]],
            ]],
        ]);
    }

    /**
     * Une session ouverte de ce compte dans la table des sessions, et son
     * « se souvenir de moi », comme sur un autre appareil ; l'identifiant
     * de la session.
     */
    protected function ouvrirUneSessionAilleurs(User $compte): string
    {
        $session = Str::random(40);
        DB::table('sessions')->insert(['id' => $session, 'user_id' => $compte->getKey(), 'ip_address' => '192.0.2.1', 'user_agent' => 'Autre appareil', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
        $compte->forceFill(['remember_token' => 'jeton-de-l-autre-appareil'])->save();

        return $session;
    }

    /**
     * Les sessions ouvertes et le « se souvenir de moi » de ce compte
     * sont-ils tous coupés ?
     */
    protected function assertSessionsFermees(User $compte): void
    {
        $this->assertSame(0, DB::table('sessions')->where('user_id', $compte->getKey())->count(), 'Une session de ce compte reste ouverte.');
        $this->assertNotSame('jeton-de-l-autre-appareil', $compte->refresh()->getRememberToken(), 'Le « se souvenir de moi » de ce compte vaut toujours.');
    }

    /**
     * Les droits avant et après de chaque modification des droits (JET 130)
     * du journal des événements du magasin, dans l'ordre de sa chaîne.
     *
     * @return list<array{membre: array{id: int, nom: string}, avant: array<string, mixed>|null, apres: array<string, mixed>|null}>
     */
    protected function modificationsDesDroits(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ModificationDesDroits->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->all());
    }

    /**
     * Les entrées du journal d'audit de la société, dans l'ordre de leur
     * inscription, pour cette action.
     *
     * @return list<EntreeAudit>
     */
    protected function entreesDAudit(Societe $societe, ActionAuditee $action): array
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): array => EntreeAudit::query()
            ->where('action', $action->value)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Les contrôles de l'établissement du magasin au répertoire Sirene,
     * inscrits au registre des vérifications de sa société, dans l'ordre :
     * le résultat et le SIRET contrôlé de chacun.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function controlesDeLEtablissement(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => VerificationSociete::query()
            ->where('magasin_id', $magasin->id)
            ->where('type', TypeVerification::SireneEtablissement->value)
            ->orderBy('id')
            ->get()
            ->map(fn (VerificationSociete $controle): array => [$controle->resultat->value, $controle->details['siret']])
            ->all());
    }

    /**
     * Le propriétaire de la société du magasin, qui a tous les droits dans
     * chacun de ses magasins.
     */
    protected function proprietaireDe(Magasin $magasin): User
    {
        return $magasin->societe()->firstOrFail()->proprietaire()->firstOrFail();
    }

    /**
     * Le responsable de test du magasin : un membre responsable de ce seul
     * magasin, qui y a toutes les autorisations sans case, vente comprise,
     * que le propriétaire n'a jamais. Créé à la première demande par la
     * fabrique (voir UserFactory::responsableDeTest()), sans action, donc
     * sans rien inscrire au registre fiscal, au journal des événements ni
     * au journal d'audit : il se crée à toute heure, même après le dernier
     * verrou du registre ; retrouvé ensuite par son nom et son magasin.
     * Statique : un processus concurrent l'appelle aussi, une fois que le
     * test l'a obtenu avant de lancer ses processus.
     */
    protected static function responsableDeTest(Magasin $magasin): User
    {
        $existant = User::query()
            ->where('societe_id', $magasin->societe_id)
            ->where('name', 'Responsable de test')
            ->whereHas('affectations', fn (Builder $affectations): Builder => $affectations->where('magasin_id', $magasin->getKey())->where('role', RoleMagasin::Responsable->value))
            ->first();

        return $existant ?? User::factory()->responsableDeTest($magasin, self::MOT_DE_PASSE_CHOISI)->create();
    }

    /**
     * L'auteur des actions qu'un test appelle directement, comme le ferait
     * l'écran : la personne connectée, sinon le responsable de test du
     * magasin actif (voir responsableDeTest()), qui peut vendre, ce que le
     * propriétaire ne fait jamais. Statique : un processus concurrent
     * l'appelle aussi.
     */
    protected static function auteur(): User
    {
        $connecte = auth()->user();

        return $connecte instanceof User ? $connecte : self::responsableDeTest(app(TenantContext::class)->magasin());
    }

    /**
     * Une vente à la caisse réglée d'un seul mode pour tout ce qui reste à
     * payer, une fois imputés le crédit du client et les bons d'avoir
     * présentés, comme la caisse le propose (voir
     * resteAPayerAuComptant()) ; rien à payer, aucune part. Statique : un
     * processus concurrent l'appelle aussi.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  list<string>  $bonsDAvoir
     */
    protected static function vendreAuComptant(User $auteur, Client $client, array $lignes, ModePaiement $mode = ModePaiement::Especes, ?string $reference = null, array $bonsDAvoir = [], ?ConfirmationVenteSansStock $confirmation = null, ?string $jeton = null): Facture
    {
        $reste = self::resteAPayerAuComptant($client, $lignes, $bonsDAvoir);
        $reglement = new ReglementComptant($reste > 0 ? [new PartDeReglement($mode, $reste, $reference)] : [], $bonsDAvoir);

        return app(EnregistrerVenteCaisseAction::class)->handle($auteur, $client, $lignes, $reglement, $confirmation, $jeton);
    }

    /**
     * Ce qui reste à payer d'une vente à la caisse, en centimes, comme
     * l'écran de la caisse le montre : son total TTC calculé par le
     * constructeur de lignes de la vente, moins le crédit du client et les
     * bons d'avoir présentés (voir ApercuDuReglement).
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  list<string>  $bonsDAvoir
     */
    protected static function resteAPayerAuComptant(Client $client, array $lignes, array $bonsDAvoir = []): int
    {
        $totalTtc = app(ConstructeurLigneDocument::class)->apercu($lignes, ContexteDeVente::pour($client, app(TenantContext::class)->magasin()))->totaux['total_ttc'];

        return ApercuDuReglement::aLaCaisse($client, app(CalculateurLigne::class)->enCentiemes($totalTtc), $bonsDAvoir)->resteARepartir;
    }

    /**
     * Les pages d'un PDF produit par dompdf, dans leur ordre, lues sans
     * aucun outil externe : le format de chacune (sa MediaBox, en points),
     * et chaque texte qu'elle imprime, avec sa position en points depuis le
     * coin bas gauche de la page et sa largeur imprimée, en points, d'après
     * son corps et la chasse de chacun de ses caractères dans sa police
     * (voir chassesDesPolices()). Dompdf écrit chaque morceau de ligne par
     * un opérateur « BT x y Td /Fn corps Tf [(texte)] TJ ET », le texte en
     * UTF-16 dans un flux compressé, ses parenthèses, ses barres obliques
     * inverses et ses retours chariot échappés. Les données d'un flux se
     * lisent sur la longueur que déclare son dictionnaire : compressées,
     * elles peuvent finir par un retour chariot, qu'un motif arrêté au
     * « endstream » retirerait.
     *
     * @return list<array{format: string, textes: list<array{x: float, y: float, largeur: float, texte: string}>}>
     */
    protected function pagesDuPdf(string $pdf): array
    {
        $chasses = $this->chassesDesPolices($pdf);
        preg_match_all('/<< \/Type \/Page\s(.*?)>>/s', $pdf, $pages);

        return array_map(function (string $page) use ($pdf, $chasses): array {
            preg_match('/\/MediaBox \[([^\]]+)\]/', $page, $format);
            preg_match('/\/Contents (\d+) 0 R/', $page, $contenu);
            preg_match('/\n'.$contenu[1].' 0 obj\s*<<(.*?)>>\s*stream\r?\n/s', $pdf, $flux, PREG_OFFSET_CAPTURE);
            preg_match('/\/Length (\d+)/', $flux[1][0], $longueur);
            $donnees = substr($pdf, $flux[0][1] + strlen($flux[0][0]), (int) $longueur[1]);
            $operateurs = str_contains($flux[1][0], '/FlateDecode') ? gzuncompress($donnees) : $donnees;
            preg_match_all('/BT ([-\d.]+) ([-\d.]+) Td \/(F\d+) ([\d.]+) Tf\s+\[\(((?:\\\\.|[^\\\\)])*)\)\] TJ ET/s', $operateurs, $textes, PREG_SET_ORDER);

            return [
                'format' => $format[1],
                'textes' => array_map(function (array $texte) use ($chasses): array {
                    $octets = (string) preg_replace_callback('/\\\\(.)/s', fn (array $echappe): string => $echappe[1] === 'r' ? "\r" : $echappe[1], $texte[5]);
                    $police = $chasses[$texte[3]];

                    return [
                        'x' => (float) $texte[1],
                        'y' => (float) $texte[2],
                        'largeur' => array_sum(array_map(
                            fn (int $caractere): int => $police['chasses'][$caractere] ?? $police['defaut'],
                            $octets === '' ? [] : array_values(unpack('n*', $octets)),
                        )) * (float) $texte[4] / 1000,
                        'texte' => mb_convert_encoding($octets, 'UTF-8', 'UTF-16BE'),
                    ];
                }, $textes),
            ];
        }, $pages[1]);
    }

    /**
     * Chaque texte de la page s'imprime entre ses marges de gauche et de
     * droite : une page A4 de 595,28 points de large, avec 14 mm de marge
     * de chaque côté (point 4).
     *
     * @param  array{format: string, textes: list<array{x: float, y: float, largeur: float, texte: string}>}  $page
     */
    protected function assertChaqueTexteTientEntreLesMarges(array $page): void
    {
        $marge = 14 * 72 / 25.4;
        $horsDesMarges = array_values(array_filter(
            $page['textes'],
            fn (array $texte): bool => $texte['x'] < $marge - 0.01 || 595.28 - $marge + 0.01 < $texte['x'] + $texte['largeur'],
        ));

        $this->assertSame([], array_map(fn (array $texte): string => sprintf('%s (de %.2f à %.2f points)', $texte['texte'], $texte['x'], $texte['x'] + $texte['largeur']), $horsDesMarges));
    }

    /**
     * La chasse des caractères de chaque police d'un PDF de dompdf, par nom
     * de police (F1, F2…), en millièmes du corps : chaque police renvoie à
     * une police descendante (DescendantFonts) dont le tableau /W donne la
     * chasse de chaque caractère employé, désigné par son code Unicode
     * (« code [chasse chasse …] », ou « premier dernier chasse » pour une
     * suite), et /DW celle des autres.
     *
     * @return array<string, array{defaut: int, chasses: array<int, int>}>
     */
    private function chassesDesPolices(string $pdf): array
    {
        $objet = function (string $numero) use ($pdf): string {
            preg_match('/\n'.$numero.' 0 obj\s*<<(.*?)>>\s*endobj/s', $pdf, $trouve);

            return $trouve[1];
        };
        preg_match_all('/\/(F\d+) (\d+) 0 R/', $pdf, $polices, PREG_SET_ORDER);

        $chassesDesPolices = [];
        foreach ($polices as [, $nom, $numero]) {
            preg_match('/\/DescendantFonts \[(\d+) 0 R\]/', $objet($numero), $descendante);
            $definition = $objet($descendante[1]);
            preg_match('/\/DW (\d+)/', $definition, $defaut);
            preg_match('/\/W \[((?:\s*\d+\s*\[[^\]]*\]|\s*\d+\s+\d+\s+\d+)*)\s*\]/', $definition, $tableau);
            preg_match_all('/(\d+)\s*\[([^\]]*)\]|(\d+)\s+(\d+)\s+(\d+)/', $tableau[1] ?? '', $entrees, PREG_SET_ORDER);

            $chasses = [];
            foreach ($entrees as $entree) {
                if (($entree[3] ?? '') !== '') {
                    $chasses += array_fill_keys(range((int) $entree[3], (int) $entree[4]), (int) $entree[5]);
                } else {
                    foreach (preg_split('/\s+/', trim($entree[2]), flags: PREG_SPLIT_NO_EMPTY) as $decalage => $chasse) {
                        $chasses[(int) $entree[1] + $decalage] = (int) $chasse;
                    }
                }
            }

            $chassesDesPolices[$nom] = ['defaut' => (int) ($defaut[1] ?? 1000), 'chasses' => $chasses];
        }

        return $chassesDesPolices;
    }

    /**
     * Complète l'identité légale de la société du magasin, comme son
     * propriétaire dans Administration de la société > Identité : il peut
     * alors émettre des factures à des professionnels (voir
     * Magasin::mentionsLegalesManquantes()).
     */
    protected function completerIdentiteLegale(Magasin $magasin): Magasin
    {
        $societe = $magasin->societe()->firstOrFail();
        $societe->forceFill([
            'raison_sociale' => 'Ma Boutique',
            'forme_juridique' => FormeJuridique::Sas,
            'capital_social' => '10000.00',
            'immatriculation' => 'RCS Lyon',
            'siege_adresse' => '1 place Bellecour',
            'siege_code_postal' => '69001',
            'siege_ville' => 'Lyon',
        ])->save();
        $magasin->setRelation('societe', $societe);

        return $magasin;
    }

    /**
     * L'opération, exécutée dans un point de sauvegarde (une transaction
     * imbriquée dans celle du test) : sous PostgreSQL, une erreur SQL
     * annule toute la transaction en cours, et le test ne pourrait plus
     * rien lire ensuite ; le point de sauvegarde n'annule que l'opération
     * refusée, sur les deux moteurs.
     */
    protected function dansUnPointDeSauvegarde(Closure $operation): Closure
    {
        return static fn (): mixed => DB::transaction($operation);
    }

    /**
     * L'instant présent à l'horloge du serveur de base de données, en UTC à
     * la seconde : celle que lisent les déclencheurs qui ne laissent
     * effacer une ligne qu'une fois expirée (voir DeclencheursDeProtection).
     * Elle peut différer de l'heure de l'application, voyagée par un test
     * ou décalée par faketime : une échéance comparée par la base se
     * construit depuis elle.
     */
    protected function maintenantSelonLaBase(): CarbonImmutable
    {
        $maintenant = DB::connection()->getDriverName() === 'pgsql'
            ? DB::selectOne("select to_char(now() at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as maintenant")->maintenant
            : DB::selectOne('select CURRENT_TIMESTAMP as maintenant')->maintenant;

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $maintenant, 'UTC');
    }

    /**
     * Range ces tables, sous PostgreSQL, dans l'ordre inverse de leurs
     * identifiants (CLUSTER sur un index décroissant ; le DDL et la
     * réécriture s'annulent avec la transaction du test) : une requête
     * sans tri rend alors leurs lignes à l'envers de leur enregistrement,
     * comme après qu'un VACUUM a libéré une place qu'une ligne plus récente
     * a reprise, ou après une restauration. SQLite range toujours les
     * lignes d'une table dans l'ordre de leur identifiant : rien n'y
     * change, et le test n'y vérifie que l'ordre que donne l'application.
     */
    protected function inverserLOrdrePhysiqueDesLignes(string ...$tables): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("CREATE INDEX {$table}_ordre_inverse ON {$table} (id DESC)");
            DB::statement("CLUSTER {$table} USING {$table}_ordre_inverse");
        }
    }

    /**
     * Retire un déclencheur de protection (voir DeclencheursDeProtection),
     * comme le ferait un administrateur de la base, pour falsifier une
     * table en ajout seul le temps d'un test. Le DDL de PostgreSQL et de
     * SQLite est transactionnel : le retour en arrière de la transaction
     * du test le rétablit.
     */
    protected function retirerLeDeclencheur(string $table, string $declencheur): void
    {
        DB::unprepared(DB::connection()->getDriverName() === 'pgsql'
            ? "DROP TRIGGER {$declencheur} ON {$table}"
            : "DROP TRIGGER {$declencheur}");
    }

    /**
     * Fait $pendant pendant la prochaine consultation de ce SIRET au
     * répertoire Sirene factice, une seule fois, avant sa réponse : ce qui
     * s'y fait passe pendant que l'action qui consulte attend l'INSEE,
     * avant qu'elle ne prenne ses verrous, comme un geste fait au même
     * moment dans un autre onglet. Le répertoire factice du conteneur est
     * remplacé par un neuf, avec ses réponses par défaut, que le test règle
     * ensuite comme l'ancien.
     *
     * @param  Closure(): void  $pendant
     */
    protected function pendantLaConsultationDe(string $siret, Closure $pendant): FauxRepertoireSirene
    {
        $repertoire = new class($siret, $pendant) extends FauxRepertoireSirene
        {
            public function __construct(private readonly string $siretGuette, private ?Closure $pendant) {}

            public function etablissement(string $siret): ?EtablissementSirene
            {
                $pendant = $siret === $this->siretGuette ? $this->pendant : null;

                if ($pendant !== null) {
                    $this->pendant = null;
                    $pendant();
                }

                return parent::etablissement($siret);
            }
        };
        $this->app->instance(RepertoireSirene::class, $repertoire);

        return $repertoire;
    }

    /**
     * Deux processus ne partagent une base qu'avec un serveur de base de
     * données : les tests de concurrence ne tournent que sous PostgreSQL,
     * la base SQLite des tests vivant dans la mémoire de leur processus.
     */
    protected function exigerUnServeurDeBaseDeDonnees(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deux processus ne partagent une base qu\'avec un serveur : ce test ne tourne que sous PostgreSQL.');
        }
    }

    /**
     * Lance l'opération dans un autre processus PHP, sur la base du test
     * (voir tests/Fixtures/processus-concurrent.php), sans attendre sa
     * fin : une seconde caisse qui agit au même instant. L'opération ne
     * voit que ce que le test a validé ; elle est statique et ne capture
     * que des valeurs simples (des identifiants). Quand le test a figé son
     * heure, l'autre processus se fige au même instant : sinon il lirait
     * l'heure réelle, et ce qu'il fait tomberait un autre jour que ce que
     * fait le test. Il reprend aussi le préfixe du cache du test, propre à
     * son processus de test parallèle : un cache en base est le même pour
     * les deux.
     */
    protected function demarrerUnProcessusConcurrent(Closure $operation): InvokedProcess
    {
        $demande = serialize([
            'base' => DB::connection()->getDatabaseName(),
            'caisse_securisee' => config('caisse_securisee'),
            'journal_audit' => config('journal_audit'),
            'prefixe_du_cache' => config('cache.prefix'),
            'maintenant' => Carbon::getTestNow()?->format('Y-m-d\TH:i:s.uP'),
            'operation' => new SerializableClosure($operation),
        ]);

        return Process::path(base_path())
            ->timeout(60)
            ->input(base64_encode($demande))
            ->start([PHP_BINARY, base_path('tests/Fixtures/processus-concurrent.php')]);
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient le
     * verrou du registre fiscal, et attend qu'elle attende ce verrou : deux
     * caisses qui agissent au même instant, la seconde passant après la
     * première. $concurrente reçoit le processus démarré.
     *
     * Le verrou est tenu à la première requête qui suit sa demande sans
     * plus porter sur la tête du registre : quand le registre n'a pas
     * encore de tête, la première demande ne trouve aucune ligne à
     * verrouiller, et la tête s'insère puis se verrouille ensuite.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    protected function concurrencerDesQueLeRegistreEstVerrouille(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        $verrouDemande = false;

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, &$verrouDemande, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            if (str_contains($requete->sql, '"tetes_registre_fiscal"')) {
                $verrouDemande = $verrouDemande || str_contains($requete->sql, 'for update');

                return;
            }

            if ($verrouDemande) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Attend que l'autre processus attende un verrou que tient le test, ou
     * qu'il ait fini, dix secondes au plus. L'attente se lit dans
     * pg_stat_activity par une connexion à part, hors de la transaction du
     * test, fermée ensuite. Elle porte son propre nom : fermée sous celui
     * de la connexion du test, elle effacerait du gestionnaire de
     * transactions celles que l'opération du test a ouvertes.
     */
    protected function attendreQueLeProcessusAttendeUnVerrou(InvokedProcess $processus): void
    {
        $observateur = DB::getDefaultConnection().'_observateur';
        config(["database.connections.{$observateur}" => [...DB::connection()->getConfig(), 'name' => $observateur]]);
        $limite = microtime(true) + 10;

        try {
            while ($processus->running() && microtime(true) < $limite) {
                $attentes = DB::connection($observateur)->selectOne("select count(*) as nombre from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'")->nombre;

                if ($attentes > 0) {
                    return;
                }

                usleep(20_000);
            }
        } finally {
            DB::purge($observateur);
        }
    }

    /**
     * Une vraie requête HTTP démarre sans magasin actif : seul le
     * middleware du panneau (BindTenantContextFromFilamentTenant) le
     * positionne, depuis l'URL. Une requête de test repart donc d'un
     * TenantContext neuf, au lieu d'hériter de celui que la préparation du
     * test a positionné ; ce dernier est rendu au test une fois la réponse
     * reçue, pour ses vérifications.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $contexteDuTest = $this->app->make(TenantContext::class);
        $this->app->forgetInstance(TenantContext::class);

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app->instance(TenantContext::class, $contexteDuTest);
        }
    }
}
