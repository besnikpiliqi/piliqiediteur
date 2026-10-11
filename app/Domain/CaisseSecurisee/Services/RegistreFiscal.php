<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Le registre fiscal du magasin actif (art. 286 I-3° bis du CGI) : chaque
 * donnée fiscale y est signée (voir SignataireFiscal) et chaînée au moment
 * où elle s'écrit, dans la transaction qui la valide.
 *
 * - Chaque chaîne (ChaineFiscale) a, pour chaque magasin, sa séquence
 *   continue depuis 1, jamais remise à zéro, et chaque enregistrement
 *   signe la signature du précédent de la même chaîne.
 * - Le contenu signé (format 1) est un JSON encodé une seule fois et gardé
 *   tel quel (EnregistrementFiscal::contenu) : la signature se vérifie sur
 *   ces octets, jamais re-sérialisés. Il reprend l'en-tête de
 *   l'enregistrement (magasin -- son uuid, le SIREN de sa société et son
 *   SIRET --, caisse, chaîne, séquence, type, horodatage,
 *   opérateur, version du module, clé, signature précédente, document) et
 *   ses données, où rien ne s'écrit en nombre à virgule flottante : un
 *   montant s'écrit en centimes entiers.
 * - Chaque enregistrement de la chaîne des ventes porte en plus, dans son
 *   en-tête, son montant TTC (négatif pour un avoir) et les grands totaux
 *   perpétuels du magasin qu'il fait avancer (voir enregistrerVente()) :
 *   le réel, somme des montants, et l'absolu, somme de leurs valeurs
 *   absolues. Ils ne repartent jamais de zéro.
 * - Les événements techniques (JET, chaîne des événements) qu'exige une
 *   écriture s'inscrivent d'eux-mêmes juste avant elle : l'initialisation
 *   (260) au tout premier enregistrement du magasin, le changement de
 *   version du module fiscal (250 majeure, 255 mineure) et celui de la clé
 *   de signature (270).
 * - L'adresse IP et le navigateur de la requête qui fait inscrire un
 *   événement technique ne sont jamais signés : ils vont dans sa trace,
 *   rangée à part dans la même transaction et effacée après la durée de
 *   conservation des traces (voir TraceEvenement).
 *
 * Toutes les écritures fiscales d'un magasin se sérialisent sur sa ligne de
 * tetes_registre_fiscal (voir verrouiller()), qui tient aussi l'état de ses
 * chaînes : la séquence et la signature du dernier enregistrement de
 * chacune, les grands totaux de la chaîne des ventes, la version du module
 * et la clé du dernier enregistrement du magasin, et sa dernière journée de
 * caisse clôturée (voir derniereJourneeCloturee()). Cet état ne se lit que
 * sur cette ligne, verrouillée, jamais dans enregistrements_fiscaux ni
 * clotures_caisse : sous PostgreSQL (READ COMMITTED), une lecture
 * verrouillée qui a dû attendre ne revoit que les lignes qu'elle avait
 * trouvées avant d'attendre, et ignorerait l'enregistrement ou la clôture
 * qu'une autre caisse a validés pendant ce temps ; la ligne de tête, qui
 * existe toujours, se relit au contraire à jour une fois le verrou
 * obtenu, comme tout ce que lisent les requêtes suivantes.
 *
 * Les verrous se prennent toujours dans cet ordre : les registres des
 * magasins concernés, par identifiant croissant, puis la ligne de la
 * société (ses réglages), puis celle du compte modifié, puis la ligne du
 * document, puis sequences_documents (voir DocumentNumberingService, qui
 * relit aussi la ligne du magasin sous un verrou partagé quand il crée une
 * série), et enfin la tête de la chaîne du journal d'audit (voir
 * JournalAudit) ; un autre ordre exposerait deux ventes, ou une vente et
 * un changement de réglage de la société, à un interblocage. Une vente ne
 * verrouille jamais la ligne de sa société. Les registres se verrouillent
 * donc en tête de toute transaction qui écrit au registre, y compris celle
 * d'une action qui modifie un paramètre ayant un impact sur la conformité
 * (voir sousVerrouDesMagasins()).
 *
 * Lié en scoped() (voir AppServiceProvider) : l'instant que donne le verrou
 * vaut pour toutes les écritures fiscales de la transaction, transactions
 * imbriquées comprises, quel que soit le code qui les fait.
 */
class RegistreFiscal
{
    public const FORMAT = 1;

    /**
     * Les verrous tenus, par magasin : la transaction la plus externe, dont
     * seule la fin libère le verrou de ligne, et l'instant donné à ses
     * écritures.
     *
     * @var array<int, array{transaction: DatabaseTransactionRecord, instant: InstantFiscal}>
     */
    private array $verrous = [];

    /**
     * @param  string  $versionDuModule  celle du code, ModuleFiscal::VERSION : seul un test d'une montée de version en passe une autre
     */
    public function __construct(
        private readonly SignataireFiscal $signataire,
        private readonly string $versionDuModule = ModuleFiscal::VERSION,
    ) {}

    /**
     * Verrouille le registre du magasin actif jusqu'à la fin de la
     * transaction en cours et donne l'instant de ses écritures fiscales :
     * maintenant, en UTC à la seconde, noté sur la ligne de verrou comme
     * dernier verrou du registre. Le verrou de ligne ne tombe qu'à la
     * validation de la transaction la plus externe : jusque-là, rappelé
     * depuis n'importe quelle transaction imbriquée, il redonne le même
     * instant, et le note de nouveau si un point de sauvegarde annulé l'a
     * effacé.
     *
     * Rien n'est verrouillé hors d'une transaction (le verrou tomberait
     * aussitôt), pour un autre magasin que le magasin actif, sans clé de
     * signature, ni quand l'horloge du serveur donne une heure antérieure
     * au dernier verrou du registre.
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function verrouiller(Magasin $magasin): InstantFiscal
    {
        return $this->verrou($magasin)[1];
    }

    /**
     * Exécute $operation sous le verrou du registre du magasin actif (voir
     * verrouiller()), premier verrou de sa transaction : dans une
     * transaction qu'il ouvre lui-même, le registre se verrouille avant que
     * $operation ne s'exécute ; dans la transaction déjà ouverte d'un
     * appelant, celui-ci doit l'avoir verrouillé en tête, avant tout autre
     * verrou. Une transaction en cours qui ne le tient pas encore est
     * refusée : elle a pu verrouiller des lignes (un produit, une facture
     * d'achat) qu'une vente, qui verrouille le registre en premier, attend
     * ensuite, et le verrouiller après elles exposerait à un interblocage.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function sousVerrou(Magasin $magasin, Closure $operation): mixed
    {
        return $this->enTeteDeTransaction(new Collection([$magasin]), fn () => $this->verrouiller($magasin), $operation);
    }

    /**
     * Exécute $operation sous le verrou des registres de ces magasins, pris
     * par identifiant croissant, chacun sous son magasin (voir
     * TenantContext::runAs()), comme sousVerrou() pour un seul : un réglage
     * de la société qui s'inscrit au journal des événements de chacun de
     * ses magasins (voir JournaliseSesParametresDeConformite). Seulement
     * des magasins de la société active : jamais le registre d'une autre
     * société. Dans la transaction déjà ouverte d'un appelant, celui-ci
     * doit les avoir tous verrouillés en tête. Fermé par défaut : un
     * registre qui ne peut pas se verrouiller refuse toute l'opération.
     *
     * @template TResultat
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     *
     * @throws LogicException quand l'un des magasins n'est pas de la société active
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function sousVerrouDesMagasins(Collection $magasins, Closure $operation): mixed
    {
        $contexte = app(TenantContext::class);
        $societeId = $contexte->societe()->getKey();
        $magasins = $magasins->sortBy(fn (Magasin $magasin): int => $magasin->getKey())->values();

        if ($magasins->contains(fn (Magasin $magasin): bool => (int) $magasin->societe_id !== $societeId)) {
            throw new LogicException('Le registre fiscal d\'un magasin ne se verrouille que dans le contexte de sa société.');
        }

        return $this->enTeteDeTransaction($magasins, function () use ($contexte, $magasins): void {
            foreach ($magasins as $magasin) {
                $contexte->runAs($magasin, fn () => $this->verrouiller($magasin));
            }
        }, $operation);
    }

    /**
     * Exécute $operation dans une transaction qui verrouille d'abord les
     * registres de ces magasins ; une transaction déjà ouverte doit les
     * tenir tous.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function enTeteDeTransaction(Collection $magasins, Closure $verrouiller, Closure $operation): mixed
    {
        $transaction = $this->transactionsEnCours()->first();

        foreach ($magasins as $magasin) {
            if ($transaction !== null && ($this->verrous[$magasin->getKey()]['transaction'] ?? null) !== $transaction) {
                throw new LogicException('Le registre fiscal se verrouille en tête de transaction, avant tout autre verrou : la transaction en cours doit le verrouiller dès son début.');
            }
        }

        return DB::transaction(function () use ($verrouiller, $operation): mixed {
            $verrouiller();

            return $operation();
        });
    }

    /**
     * La dernière journée de caisse clôturée du magasin actif (AAAA-MM-JJ),
     * null tant qu'aucune ne l'est, lue sur la ligne de verrou, sous le
     * verrou de la transaction en cours (voir verrouiller()) : une vente qui
     * a attendu la fin de la clôture de sa journée la voit, quelle que soit
     * la photo que ses lectures sans verrou ont prise avant.
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function derniereJourneeCloturee(Magasin $magasin): ?string
    {
        return $this->verrou($magasin)[0]->derniere_journee_cloturee;
    }

    /**
     * Note sur la ligne de verrou la journée que la transaction en cours
     * clôture (voir CloturerCaisseAction), sous le même verrou.
     *
     * @param  string  $journee  AAAA-MM-JJ
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function noterLaJourneeCloturee(Magasin $magasin, string $journee): void
    {
        $this->verrou($magasin)[0]->forceFill(['derniere_journee_cloturee' => $journee])->save();
    }

    /**
     * Inscrit un enregistrement signé dans une chaîne du registre du
     * magasin actif, sous son verrou (voir verrouiller()), après les
     * événements techniques qu'il rend nécessaires. Une vente s'inscrit
     * par enregistrerVente(), un événement technique par journaliser().
     *
     * @param  array<array-key, mixed>  $donnees  null, booléens, entiers, chaînes et tableaux seulement
     * @param  Model|null  $document  le document enregistré, du magasin actif
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function enregistrer(ChaineFiscale $chaine, string $type, array $donnees, ?Model $document = null): EnregistrementFiscal
    {
        if ($chaine === ChaineFiscale::Evenement) {
            throw new LogicException('Un événement technique s\'inscrit par journaliser(), avec son code.');
        }

        if ($chaine === ChaineFiscale::Vente) {
            throw new LogicException('Une vente s\'inscrit par enregistrerVente(), avec son montant : il fait avancer les grands totaux.');
        }

        return $this->ecrire($chaine, $type, $donnees, $document, null);
    }

    /**
     * Inscrit un ticket, une facture ou un avoir dans la chaîne des ventes,
     * comme enregistrer(), avec son montant TTC en centimes (négatif pour
     * un avoir) : l'en-tête signé porte ce montant et les grands totaux
     * perpétuels du magasin après lui, le réel (précédent + montant) et
     * l'absolu (précédent + valeur absolue du montant).
     *
     * @param  array<array-key, mixed>  $donnees  null, booléens, entiers, chaînes et tableaux seulement
     * @param  Model|null  $document  le document enregistré, du magasin actif
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function enregistrerVente(string $type, int $montantTtcCentimes, array $donnees, ?Model $document = null): EnregistrementFiscal
    {
        return $this->ecrire(ChaineFiscale::Vente, $type, $donnees, $document, $montantTtcCentimes);
    }

    /**
     * Inscrit un événement technique (JET) dans la chaîne des événements du
     * magasin actif : son code, son libellé et ses détails. L'adresse IP et
     * le navigateur de la requête HTTP en cours, s'il y en a une, vont dans
     * sa trace (voir inscrire()).
     *
     * @param  array<array-key, mixed>  $details  ancienne et nouvelle valeur d'un paramètre, référence d'un document...
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function journaliser(CodeEvenement $code, array $details = [], ?Model $document = null): EnregistrementFiscal
    {
        return $this->ecrire(ChaineFiscale::Evenement, (string) $code->value, $this->evenement($code, $details), $document, null);
    }

    /**
     * Un magasin qui ne peut pas vendre n'inscrit ni vente ni encaissement
     * (voir Magasin::verifierQuIlPeutVendre()) : en lecture seule, sa
     * société en cours de vérification, ou son établissement pas encore
     * confirmé. Le magasin se relit en base, sous le verrou de son
     * registre, avec sa société : un changement validé pendant que la
     * vente attendait le verrou est vu. La transaction de l'écriture
     * refusée annule ce qui la précède. Ses événements techniques, eux,
     * continuent.
     *
     * @param  array<array-key, mixed>  $donnees
     * @param  int|null  $montantVente  le montant TTC en centimes d'un enregistrement de la chaîne des ventes, null pour toute autre chaîne
     *
     * @throws LogicException
     */
    private function ecrire(ChaineFiscale $chaine, string $type, array $donnees, ?Model $document, ?int $montantVente): EnregistrementFiscal
    {
        self::refuserCeQuiNeSeSignePas($donnees, 'donnees');

        $magasin = app(TenantContext::class)->magasin();

        if ($document !== null && (! $document->exists || (int) $document->getAttribute('magasin_id') !== $magasin->getKey())) {
            throw new LogicException('Le registre fiscal n\'enregistre qu\'un document déjà enregistré du magasin actif.');
        }

        [$tete, $instant] = $this->verrou($magasin);

        if ($chaine !== ChaineFiscale::Evenement) {
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlPeutVendre();
        }

        foreach ($this->evenementsAutomatiques($tete) as [$code, $details]) {
            $this->inscrire($magasin, $tete, $instant, ChaineFiscale::Evenement, (string) $code->value, $this->evenement($code, $details), null, null);
        }

        return $this->inscrire($magasin, $tete, $instant, $chaine, $type, $donnees, $document, $montantVente);
    }

    /**
     * Le verrou du registre du magasin (voir verrouiller()) : sa ligne de
     * tête, lue verrouillée, et l'instant de la transaction. La ligne se
     * relit à chaque appel, car un point de sauvegarde annulé a pu effacer
     * ce que la transaction y avait écrit.
     *
     * @return array{0: TeteRegistreFiscal, 1: InstantFiscal}
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function verrou(Magasin $magasin): array
    {
        $transaction = $this->transactionsEnCours()->first()
            ?? throw new LogicException('Le registre fiscal ne se verrouille que dans une transaction : hors transaction, son verrou tomberait aussitôt.');
        $magasinId = $magasin->getKey();

        if ($magasinId !== app(TenantContext::class)->id()) {
            throw new LogicException('Le registre fiscal ne se verrouille que pour le magasin actif.');
        }

        $this->signataire->cleId();

        $tete = $this->teteVerrouillee($magasinId);
        $verrouTenu = $this->verrous[$magasinId] ?? null;
        $instant = $verrouTenu !== null && $verrouTenu['transaction'] === $transaction
            ? $verrouTenu['instant']
            : new InstantFiscal(CarbonImmutable::now('UTC')->startOfSecond());
        $dernierVerrou = $tete->dernier_horodatage;

        if ($dernierVerrou !== null && $instant->utc->lessThan($dernierVerrou)) {
            Log::critical('Registre fiscal : l\'horloge du serveur a reculé, opération fiscale refusée.', [
                'magasin_id' => $magasinId,
                'instant' => $instant->utc->toIso8601String(),
                'dernier_verrou' => $dernierVerrou->toIso8601String(),
            ]);

            throw HorlogeRegressiveException::pour($magasinId, $instant->utc, $dernierVerrou);
        }

        if ($dernierVerrou === null || $instant->utc->greaterThan($dernierVerrou)) {
            $tete->forceFill(['dernier_horodatage' => $instant->utc])->save();
        }

        $this->verrous[$magasinId] = ['transaction' => $transaction, 'instant' => $instant];

        return [$tete, $instant];
    }

    /**
     * Les événements techniques à inscrire avant le prochain enregistrement
     * du magasin, d'après le dernier, tel que le note la ligne de tête :
     * l'initialisation s'il n'y en a aucun, sinon le changement de version
     * du module fiscal, puis celui de la clé de signature.
     *
     * @return list<array{0: CodeEvenement, 1: array<string, string>}>
     */
    private function evenementsAutomatiques(TeteRegistreFiscal $tete): array
    {
        if ($tete->version_module === null) {
            return [[CodeEvenement::Initialisation, []]];
        }

        $evenements = [];

        if ($tete->version_module !== $this->versionDuModule) {
            $memeMajeure = explode('.', $tete->version_module)[0] === explode('.', $this->versionDuModule)[0];

            $evenements[] = [
                $memeMajeure ? CodeEvenement::VersionMineure : CodeEvenement::VersionMajeure,
                ['ancienne_valeur' => $tete->version_module, 'nouvelle_valeur' => $this->versionDuModule],
            ];
        }

        $cleId = $this->signataire->cleId();

        if ($tete->cle_id !== $cleId) {
            $evenements[] = [
                CodeEvenement::ParametreDeConformite,
                ['parametre' => 'cle_id', 'ancienne_valeur' => $tete->cle_id, 'nouvelle_valeur' => $cleId],
            ];
        }

        return $evenements;
    }

    /**
     * Les données signées d'un événement technique : jamais l'adresse IP
     * ni le navigateur, qui vont dans sa trace (voir inscrire()).
     *
     * @param  array<array-key, mixed>  $details
     * @return array{code: int, libelle: string, details: array<array-key, mixed>}
     */
    private function evenement(CodeEvenement $code, array $details): array
    {
        return [
            'code' => $code->value,
            'libelle' => $code->getLabel(),
            'details' => $details,
        ];
    }

    /**
     * Inscrit l'enregistrement à la suite de la tête de sa chaîne, puis
     * avance cette tête sur la ligne de verrou, dans la même transaction :
     * un point de sauvegarde annulé efface l'un et l'autre. Un
     * enregistrement de la chaîne des ventes y avance aussi les grands
     * totaux ; un événement technique note sa trace (voir
     * TraceEvenement::noterPour()), dans la même transaction encore : une
     * trace qui ne s'écrit pas annule l'événement.
     *
     * @param  array<array-key, mixed>  $donnees
     */
    private function inscrire(Magasin $magasin, TeteRegistreFiscal $tete, InstantFiscal $instant, ChaineFiscale $chaine, string $type, array $donnees, ?Model $document, ?int $montantVente): EnregistrementFiscal
    {
        $chaines = $tete->chaines ?? [];
        $teteDeLaChaine = $chaines[$chaine->value] ?? ['sequence' => 0, 'signature' => null];
        $sequence = $teteDeLaChaine['sequence'] + 1;
        $signaturePrecedente = $teteDeLaChaine['signature'];
        /** @var User|null $operateur */
        $operateur = auth()->user();
        $cleId = $this->signataire->cleId();
        $grandsTotaux = $montantVente === null ? null : [
            'montant' => $montantVente,
            'perpetuel_reel' => ($teteDeLaChaine['gt_reel'] ?? 0) + $montantVente,
            'perpetuel_absolu' => ($teteDeLaChaine['gt_absolu'] ?? 0) + abs($montantVente),
        ];

        $contenu = json_encode([
            'format' => self::FORMAT,
            'magasin' => ['uuid' => $magasin->uuid, 'siren' => $magasin->societe->siren, 'siret' => $magasin->siret],
            'caisse' => $magasin->uuid,
            'chaine' => $chaine->value,
            'sequence' => $sequence,
            'type' => $type,
            'horodatage' => $instant->pourSignature(),
            'operateur' => $operateur === null ? null : ['id' => $operateur->getKey(), 'nom' => $operateur->name],
            'version_module' => $this->versionDuModule,
            'cle_id' => $cleId,
            'signature_precedente' => $signaturePrecedente,
            ...($grandsTotaux === null ? [] : ['gt' => $grandsTotaux]),
            'document' => $document === null ? null : ['type' => $document->getMorphClass(), 'id' => $document->getKey()],
            'donnees' => $donnees,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = $this->signataire->signer($contenu);

        $enregistrement = (new EnregistrementFiscal)->forceFill([
            'chaine' => $chaine,
            'sequence' => $sequence,
            'type' => $type,
            'document_type' => $document?->getMorphClass(),
            'document_id' => $document?->getKey(),
            'operateur_id' => $operateur?->getKey(),
            'horodatage' => $instant->utc,
            'contenu' => $contenu,
            'signature_precedente' => $signaturePrecedente,
            'signature' => $signature,
            'algorithme' => SignataireFiscal::ALGORITHME,
            'cle_id' => $cleId,
            'version_module' => $this->versionDuModule,
        ]);
        $enregistrement->save();

        if ($chaine === ChaineFiscale::Evenement) {
            TraceEvenement::noterPour($enregistrement);
        }

        $chaines[$chaine->value] = ['sequence' => $sequence, 'signature' => $signature, ...($grandsTotaux === null ? [] : [
            'gt_reel' => $grandsTotaux['perpetuel_reel'],
            'gt_absolu' => $grandsTotaux['perpetuel_absolu'],
        ])];
        $tete->forceFill(['chaines' => $chaines, 'version_module' => $this->versionDuModule, 'cle_id' => $cleId])->save();

        return $enregistrement;
    }

    /**
     * La ligne de verrou du magasin, verrouillée (SELECT ... FOR UPDATE),
     * créée à sa première écriture fiscale. INSERT IGNORE : une transaction
     * concurrente qui crée la même ligne n'est pas une erreur, la lecture
     * verrouillée trouve sa ligne dès qu'elle est validée.
     */
    private function teteVerrouillee(int $magasinId): TeteRegistreFiscal
    {
        $tete = TeteRegistreFiscal::query()->where('magasin_id', $magasinId)->lockForUpdate()->first();

        if ($tete !== null) {
            return $tete;
        }

        TeteRegistreFiscal::query()->insertOrIgnore([
            'magasin_id' => $magasinId,
            'created_at' => $maintenant = now(),
            'updated_at' => $maintenant,
        ]);

        return TeteRegistreFiscal::query()->where('magasin_id', $magasinId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Les transactions ouvertes par l'application sur la connexion par
     * défaut, de la plus externe à la plus profonde. Le gestionnaire de
     * transactions des tests en écarte celle qui enveloppe chaque test
     * (RefreshDatabase), comme il le fait pour les rappels afterCommit() :
     * elle n'appartient pas à l'application.
     *
     * @return Collection<int, DatabaseTransactionRecord>
     */
    private function transactionsEnCours(): Collection
    {
        $connexion = DB::connection()->getName();

        return app('db.transactions')->callbackApplicableTransactions()
            ->filter(static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connexion)
            ->values();
    }

    /**
     * Seuls null, booléens, entiers, chaînes et tableaux s'inscrivent : un
     * nombre à virgule flottante ne se relit pas toujours à l'identique (un
     * montant s'écrit en centimes entiers), et un objet s'encoderait selon
     * sa classe plutôt que selon le registre.
     *
     * @param  array<array-key, mixed>  $valeurs
     */
    private static function refuserCeQuiNeSeSignePas(array $valeurs, string $chemin): void
    {
        foreach ($valeurs as $cle => $valeur) {
            $ici = "{$chemin}.{$cle}";

            if (is_array($valeur)) {
                self::refuserCeQuiNeSeSignePas($valeur, $ici);
            } elseif (is_float($valeur)) {
                throw new InvalidArgumentException("{$ici} : un montant fiscal s'écrit en centimes entiers, jamais en nombre à virgule flottante.");
            } elseif (! ($valeur === null || is_bool($valeur) || is_int($valeur) || is_string($valeur))) {
                throw new InvalidArgumentException("{$ici} : seuls null, booléens, entiers, chaînes et tableaux s'inscrivent au registre fiscal.");
            }
        }
    }
}
