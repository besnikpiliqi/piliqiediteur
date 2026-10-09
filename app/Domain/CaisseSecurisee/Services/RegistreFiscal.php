<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 *   l'enregistrement (magasin, caisse, chaîne, séquence, type, horodatage,
 *   opérateur, version du module, clé, signature précédente, document) et
 *   ses données, où rien ne s'écrit en nombre à virgule flottante : un
 *   montant s'écrit en centimes entiers.
 * - Les événements techniques (JET, chaîne des événements) qu'exige une
 *   écriture s'inscrivent d'eux-mêmes juste avant elle : l'initialisation
 *   (260) au tout premier enregistrement du magasin, le changement de
 *   version du module fiscal (250 majeure, 255 mineure) et celui de la clé
 *   de signature (270).
 *
 * Toutes les écritures fiscales d'un magasin se sérialisent sur sa ligne de
 * tetes_registre_fiscal (voir verrouiller()). Les verrous se prennent
 * toujours dans cet ordre : le registre, puis la ligne du document, puis
 * sequences_documents (voir DocumentNumberingService) ; un autre ordre
 * exposerait deux ventes simultanées à un interblocage.
 *
 * Lié en scoped() (voir AppServiceProvider) : le verrou pris dans une
 * transaction, et son instant, valent pour toutes les écritures fiscales
 * de cette transaction, quel que soit le code qui les fait.
 */
class RegistreFiscal
{
    public const FORMAT = 1;

    /**
     * Les verrous tenus, par magasin : la transaction qui tient le verrou
     * et l'instant donné à ses écritures.
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
     * maintenant, en UTC à la seconde, noté sur la ligne de verrou. Rappelé
     * avant la fin de la transaction qui l'a pris, transactions imbriquées
     * comprises, il redonne le même instant.
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
        $transaction = $this->transactionsEnCours()->last()
            ?? throw new LogicException('Le registre fiscal ne se verrouille que dans une transaction : hors transaction, son verrou tomberait aussitôt.');
        $magasinId = $magasin->getKey();

        if ($magasinId !== app(TenantContext::class)->id()) {
            throw new LogicException('Le registre fiscal ne se verrouille que pour le magasin actif.');
        }

        $verrouTenu = $this->verrous[$magasinId] ?? null;

        if ($verrouTenu !== null && $this->transactionsEnCours()->containsStrict($verrouTenu['transaction'])) {
            return $verrouTenu['instant'];
        }

        $this->signataire->cleId();

        $tete = $this->teteVerrouillee($magasinId);
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $dernierHorodatage = $tete->dernier_horodatage;

        if ($dernierHorodatage !== null && $instant->lessThan($dernierHorodatage)) {
            Log::critical('Registre fiscal : l\'horloge du serveur a reculé, opération fiscale refusée.', [
                'magasin_id' => $magasinId,
                'instant' => $instant->toIso8601String(),
                'dernier_horodatage' => $dernierHorodatage->toIso8601String(),
            ]);

            throw HorlogeRegressiveException::pour($magasinId, $instant, $dernierHorodatage);
        }

        if ($dernierHorodatage === null || $instant->greaterThan($dernierHorodatage)) {
            $tete->forceFill(['dernier_horodatage' => $instant])->save();
        }

        $this->verrous[$magasinId] = ['transaction' => $transaction, 'instant' => new InstantFiscal($instant)];

        return $this->verrous[$magasinId]['instant'];
    }

    /**
     * Inscrit un enregistrement signé dans une chaîne du registre du
     * magasin actif, sous son verrou (voir verrouiller()), après les
     * événements techniques qu'il rend nécessaires. Un événement technique
     * s'inscrit par journaliser().
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

        return $this->ecrire($chaine, $type, $donnees, $document);
    }

    /**
     * Inscrit un événement technique (JET) dans la chaîne des événements du
     * magasin actif : son code et son libellé, ses détails, et l'adresse IP
     * et le navigateur de la requête HTTP en cours, s'il y en a une.
     *
     * @param  array<array-key, mixed>  $details  ancienne et nouvelle valeur d'un paramètre, référence d'un document...
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function journaliser(CodeEvenement $code, array $details = [], ?Model $document = null): EnregistrementFiscal
    {
        return $this->ecrire(ChaineFiscale::Evenement, (string) $code->value, $this->evenement($code, $details), $document);
    }

    /**
     * @param  array<array-key, mixed>  $donnees
     */
    private function ecrire(ChaineFiscale $chaine, string $type, array $donnees, ?Model $document): EnregistrementFiscal
    {
        self::refuserCeQuiNeSeSignePas($donnees, 'donnees');

        $magasin = app(TenantContext::class)->magasin();

        if ($document !== null && (! $document->exists || (int) $document->getAttribute('magasin_id') !== $magasin->getKey())) {
            throw new LogicException('Le registre fiscal n\'enregistre qu\'un document déjà enregistré du magasin actif.');
        }

        $instant = $this->verrouiller($magasin);

        foreach ($this->evenementsAutomatiques($magasin->getKey()) as [$code, $details]) {
            $this->inscrire($magasin, $instant, ChaineFiscale::Evenement, (string) $code->value, $this->evenement($code, $details), null);
        }

        return $this->inscrire($magasin, $instant, $chaine, $type, $donnees, $document);
    }

    /**
     * Les événements techniques à inscrire avant le prochain enregistrement
     * du magasin, d'après le dernier : l'initialisation s'il n'y en a
     * aucun, sinon le changement de version du module fiscal, puis celui
     * de la clé de signature.
     *
     * @return list<array{0: CodeEvenement, 1: array<string, string>}>
     */
    private function evenementsAutomatiques(int $magasinId): array
    {
        $dernier = EnregistrementFiscal::query()
            ->where('magasin_id', $magasinId)
            ->latest('id')
            ->first(['version_module', 'cle_id']);

        if ($dernier === null) {
            return [[CodeEvenement::Initialisation, []]];
        }

        $evenements = [];

        if ($dernier->version_module !== $this->versionDuModule) {
            $memeMajeure = explode('.', $dernier->version_module)[0] === explode('.', $this->versionDuModule)[0];

            $evenements[] = [
                $memeMajeure ? CodeEvenement::VersionMineure : CodeEvenement::VersionMajeure,
                ['ancienne_valeur' => $dernier->version_module, 'nouvelle_valeur' => $this->versionDuModule],
            ];
        }

        $cleId = $this->signataire->cleId();

        if ($dernier->cle_id !== $cleId) {
            $evenements[] = [
                CodeEvenement::ParametreDeConformite,
                ['parametre' => 'cle_id', 'ancienne_valeur' => $dernier->cle_id, 'nouvelle_valeur' => $cleId],
            ];
        }

        return $evenements;
    }

    /**
     * Les données d'un événement technique. L'adresse IP et le navigateur
     * ne viennent que d'une requête HTTP routée : une commande ou un job
     * n'a qu'une requête de façade, sans visiteur.
     *
     * @param  array<array-key, mixed>  $details
     * @return array{code: int, libelle: string, details: array<array-key, mixed>, ip: string|null, agent_utilisateur: string|null}
     */
    private function evenement(CodeEvenement $code, array $details): array
    {
        $requete = request();
        $visiteur = $requete->route() !== null;
        $navigateur = $visiteur ? $requete->userAgent() : null;

        return [
            'code' => $code->value,
            'libelle' => $code->getLabel(),
            'details' => $details,
            'ip' => $visiteur ? $requete->ip() : null,
            'agent_utilisateur' => $navigateur === null ? null : mb_substr($navigateur, 0, 255),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $donnees
     */
    private function inscrire(Magasin $magasin, InstantFiscal $instant, ChaineFiscale $chaine, string $type, array $donnees, ?Model $document): EnregistrementFiscal
    {
        $precedent = EnregistrementFiscal::query()
            ->where('magasin_id', $magasin->getKey())
            ->where('chaine', $chaine->value)
            ->orderByDesc('sequence')
            ->first(['sequence', 'signature']);
        $sequence = ($precedent?->sequence ?? 0) + 1;
        /** @var User|null $operateur */
        $operateur = auth()->user();
        $cleId = $this->signataire->cleId();

        $contenu = json_encode([
            'format' => self::FORMAT,
            'magasin' => ['uuid' => $magasin->uuid, 'siren' => $magasin->siren, 'siret' => $magasin->siret],
            'caisse' => $magasin->uuid,
            'chaine' => $chaine->value,
            'sequence' => $sequence,
            'type' => $type,
            'horodatage' => $instant->pourSignature(),
            'operateur' => $operateur === null ? null : ['id' => $operateur->getKey(), 'nom' => $operateur->name],
            'version_module' => $this->versionDuModule,
            'cle_id' => $cleId,
            'signature_precedente' => $precedent?->signature,
            'document' => $document === null ? null : ['type' => $document->getMorphClass(), 'id' => $document->getKey()],
            'donnees' => $donnees,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $enregistrement = (new EnregistrementFiscal)->forceFill([
            'chaine' => $chaine,
            'sequence' => $sequence,
            'type' => $type,
            'document_type' => $document?->getMorphClass(),
            'document_id' => $document?->getKey(),
            'operateur_id' => $operateur?->getKey(),
            'horodatage' => $instant->utc,
            'contenu' => $contenu,
            'signature_precedente' => $precedent?->signature,
            'signature' => $this->signataire->signer($contenu),
            'algorithme' => SignataireFiscal::ALGORITHME,
            'cle_id' => $cleId,
            'version_module' => $this->versionDuModule,
        ]);
        $enregistrement->save();

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
