<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TeteRegistreFiscal;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use ErrorException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Exceptions\MathException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use LogicException;
use stdClass;
use TypeError;
use ValueError;

/**
 * La vérification de l'intégrité du registre fiscal du magasin actif (art.
 * 286 I-3° bis du CGI) : elle relit ce qui a été signé et le confronte à la
 * base, sans rien écrire, et rend ses anomalies dans un RapportIntegrite.
 *
 * 1. Les chaînes du registre (ChaineFiscale), enregistrement par
 *    enregistrement : séquences continues depuis 1, sans trou, aucune
 *    inférieure à 1 ; signature précédente égale à celle de
 *    l'enregistrement précédent, absente du seul premier ; signature
 *    valide pour une clé ÉPINGLÉE, seule à faire foi (voir
 *    SignataireFiscal) ; horodatages qui ne reculent pas ; en-tête signé
 *    identique aux colonnes ; grands totaux perpétuels qui avancent du
 *    montant de chaque vente. Puis la ligne de tête du registre, qui doit
 *    noter le dernier enregistrement de chaque chaîne.
 * 2. Les documents : le bloc « document » d'un ticket, d'une facture ou
 *    d'un avoir, client figé compris, et pour un encaissement le paiement,
 *    celui qu'il contre-passe, ses imputations et le crédit d'avoir qu'il
 *    impute, recalculés depuis la base (voir ContenuFiscal), doivent être
 *    ceux qui ont été signés, et la date d'émission l'instant de la
 *    signature : une modification directe en base se voit ici. Chaque
 *    document numéroté, et chaque paiement, a exactement un
 *    enregistrement, et chaque enregistrement désigne un document numéroté
 *    du bon type (une contre-passation sous le type « contre_passation »,
 *    tout autre paiement sous le type « paiement »). Chaque imputation
 *    d'un paiement ou d'un avoir figure dans exactement un contenu signé :
 *    celui de son paiement, du paiement qui a imputé ce crédit d'avoir ou
 *    de la contre-passation qui l'a rendu, en négatif, de la facture
 *    qu'un crédit d'avoir a réglée à son émission, ou de l'avoir qui l'a
 *    imputée sur ses factures. Le règlement que reprend aussi le contexte
 *    d'un ticket au comptoir n'en est qu'une copie : seul le paiement,
 *    dans la chaîne des encaissements, la signe pour son compte. Ce qui
 *    est imputé sur chaque facture émise (paiements et crédits d'avoir,
 *    contre-passations déduites) reste entre zéro et son total TTC, et ce
 *    que chaque avoir émis a crédité, entre zéro et le sien : une
 *    imputation signée en règle dépasse ce qui restait dû si elle ne l'a
 *    pas relu sous le verrou du registre.
 * 3. La numérotation des tickets, des factures et des avoirs : continue
 *    dans chaque série et chaque année, de 1 au dernier numéro que la
 *    série a attribué, chaque numéro dans l'année de l'émission de son
 *    document.
 *
 * Avec des points de reprise, ceux de la vérification précédente (voir
 * VerifierIntegriteAction), elle ne reprend chaque chaîne qu'après son
 * point de reprise, dont l'enregistrement doit être resté celui qu'elle
 * avait vérifié, et ne recalcule que les documents et les imputations de
 * ces nouveaux enregistrements. Elle relit aussi toute ligne de séquence
 * inférieure à 1 : un simple ajout, que les déclencheurs de la table
 * laissent passer, ne peut glisser une ligne avant le point de reprise
 * qu'à une telle séquence, les autres étant déjà prises dans une chaîne
 * sans trou (l'index unique des séquences refuse un doublon). Le point de
 * reprise d'une chaîne est son dernier enregistrement dont la signature se
 * vérifie : un enregistrement dont la signature ne se vérifie pas est relu,
 * et signalé de nouveau, par la reprise suivante, au lieu d'en devenir le
 * point de départ. La ligne de tête, la correspondance entre documents et
 * enregistrements, les montants imputés et la numérotation se vérifient
 * toujours entièrement.
 *
 * Tout se lit en flux (par paquets) ou se compte en base : la mémoire
 * d'une vérification, complète ou non, ne grandit pas avec le registre.
 * Pour une reprise, elle suit ce qui s'y est inscrit depuis la précédente ;
 * le rapport ne garde que les premières anomalies (voir RapportIntegrite),
 * et seules les imputations signées introuvables en base et les numéros
 * hors de leur série se retiennent jusqu'à la fin de la vérification.
 *
 * Rien n'est verrouillé, pour ne jamais arrêter les ventes : tout se lit
 * dans une même photo de la base (voir dansUnInstantaneCoherent()), sans
 * qu'une vente validée en cours de route n'y paraisse à moitié. Ce qui
 * s'inscrit pendant la vérification sera vérifié par la suivante. Les
 * clôtures de caisse et les archives ne sont pas encore vérifiées : leur
 * forme définitive attend les décisions de l'éditeur.
 */
class VerificateurIntegrite
{
    /**
     * Le nombre de numéros manquants ou de séquences qu'un message énumère
     * au plus : le décompte, lui, est toujours complet.
     */
    private const LISTE_MAXIMUM = 20;

    /**
     * Les types de la chaîne des ventes et de celle des encaissements, et
     * l'alias (Relation::morphMap()) du document que chacun enregistre.
     */
    private const DOCUMENTS_PAR_TYPE = [
        'vente' => ['ticket' => 'facture', 'facture' => 'facture', 'avoir' => 'avoir'],
        'encaissement' => ['paiement' => 'paiement', 'contre_passation' => 'paiement'],
    ];

    /**
     * Le modèle de chaque alias de document.
     */
    private const MODELES = [
        'facture' => Facture::class,
        'avoir' => Avoir::class,
        'paiement' => Paiement::class,
    ];

    private RapportIntegrite $rapport;

    private Magasin $magasin;

    /**
     * Les cle_id des clés publiques épinglées.
     *
     * @var list<string>
     */
    private array $clesEpinglees = [];

    /**
     * Pour une reprise seulement, les documents dont l'enregistrement a été
     * vérifié, par alias : ceux dont elle vérifie les imputations.
     *
     * @var array{facture: list<int>, avoir: list<int>, paiement: list<int>}
     */
    private array $documentsVerifies = ['facture' => [], 'avoir' => [], 'paiement' => []];

    /**
     * Les imputations signées par un enregistrement vérifié et introuvables
     * en base, par clé (voir cleDImputation()).
     *
     * @var array<string, array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null, montant: int|null}>
     */
    private array $imputationsIntrouvables = [];

    /**
     * Le dernier document dont les imputations signées ont été lues, par
     * alias : les imputations d'un même paiement, d'une même facture ou
     * d'un même avoir se suivent en base.
     *
     * @var array<string, array{document_id: int, imputations: list<array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null, montant: int|null}>}>
     */
    private array $dernieresImputationsLues = [];

    public function __construct(
        private readonly SignataireFiscal $signataire,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Vérifie le registre du magasin actif, entièrement, ou après les
     * points de reprise de la vérification précédente. Elle est complète
     * quand aucune chaîne ne repart d'un point de reprise.
     *
     * @param  array<string, array{sequence: int, signature: string}>|null  $pointsDeReprise  par valeur de ChaineFiscale ; null pour tout vérifier
     *
     * @throws ModuleFiscalNonConfigureException une clé épinglée est invalide
     */
    public function verifier(Magasin $magasin, ?array $pointsDeReprise = null): RapportIntegrite
    {
        if ($magasin->getKey() !== app(TenantContext::class)->id()) {
            throw new LogicException('La vérification de l\'intégrité ne porte que sur le registre du magasin actif.');
        }

        $reprises = [];

        foreach (ChaineFiscale::cases() as $chaine) {
            if (isset($pointsDeReprise[$chaine->value])) {
                $reprises[$chaine->value] = $pointsDeReprise[$chaine->value];
            }
        }

        $this->rapport = new RapportIntegrite($reprises === []);
        $this->magasin = $magasin;
        $this->clesEpinglees = array_keys($this->signataire->clesEpinglees());
        $this->documentsVerifies = ['facture' => [], 'avoir' => [], 'paiement' => []];
        $this->imputationsIntrouvables = [];
        $this->dernieresImputationsLues = [];

        $this->dansUnInstantaneCoherent(function () use ($reprises): void {
            $this->verifierLesChainesInconnues();

            foreach (ChaineFiscale::cases() as $chaine) {
                $this->verifierLaChaine($chaine, $reprises[$chaine->value] ?? null);
            }

            $this->verifierLaTete();
            $this->verifierLesEnregistrementsDesDocuments();
            $this->verifierLesImputations();
            $this->verifierLesMontantsImputes();
            $this->verifierLaNumerotation();
        });

        return $this->rapport;
    }

    /**
     * Toutes les lectures de la vérification voient la même photo de la
     * base, prise à sa première lecture :
     * - sous PostgreSQL, dont le niveau par défaut (READ COMMITTED) prend
     *   une nouvelle photo à chaque requête, la transaction s'ouvre en
     *   REPEATABLE READ READ ONLY, réglé par sa toute première
     *   instruction. Une transaction en lecture seule ne subit jamais
     *   d'erreur de sérialisation ; elle refuse en plus toute écriture.
     *   Appelée dans une transaction déjà ouverte (les tests, que
     *   RefreshDatabase enveloppe chacun dans la sienne), elle en garde le
     *   niveau : PostgreSQL ne le change qu'en tête de la transaction la
     *   plus externe, jamais dans un point de sauvegarde ;
     * - sous SQLite, une transaction lit toujours une même photo.
     */
    private function dansUnInstantaneCoherent(Closure $verification): void
    {
        $connexion = DB::connection();

        if ($connexion->getDriverName() !== 'pgsql' || $connexion->transactionLevel() > 0) {
            $connexion->transaction($verification);

            return;
        }

        $connexion->transaction(function () use ($connexion, $verification): void {
            $connexion->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $verification();
        });
    }

    /**
     * @return Builder<EnregistrementFiscal>
     */
    private function enregistrements(): Builder
    {
        return EnregistrementFiscal::query();
    }

    private function verifierLesChainesInconnues(): void
    {
        $this->enregistrements()
            ->whereNotIn('chaine', array_map(static fn (ChaineFiscale $chaine): string => $chaine->value, ChaineFiscale::cases()))
            ->toBase()
            ->selectRaw('chaine, count(*) as nombre')
            ->groupBy('chaine')
            ->get()
            ->each(fn (stdClass $chaine) => $this->rapport->signaler(AnomalieFiscale::defaut(
                'chaine',
                "{$chaine->nombre} enregistrement(s) dans une chaîne inconnue du registre (« {$chaine->chaine} »).",
                ['chaine' => (string) $chaine->chaine],
            )));
    }

    // ---------------------------------------------------------------
    // 1. Les chaînes
    // ---------------------------------------------------------------

    /**
     * Parcourt la chaîne dans l'ordre de ses séquences, depuis le début ou
     * après son point de reprise, et note dans le rapport ce qu'elle en a
     * vérifié, avec son nouveau point de reprise : le dernier
     * enregistrement dont la signature se vérifie. Toute ligne de séquence
     * inférieure à 1, qu'aucune chaîne n'attribue, se relit d'abord, même
     * par une reprise, et se vérifie seule : elle ne prolonge rien.
     *
     * @param  array{sequence: int, signature: string}|null  $pointDeReprise
     */
    private function verifierLaChaine(ChaineFiscale $chaine, ?array $pointDeReprise): void
    {
        $precedent = $pointDeReprise === null ? null : $this->repartirDu($chaine, $pointDeReprise);
        $pointDeRepriseSuivant = $precedent !== null && $precedent['signature_valide'] ? self::pointDeReprise($precedent) : null;
        $verifies = 0;

        $this->enregistrements()
            ->where('chaine', $chaine->value)
            ->where('sequence', '<', 1)
            ->lazyById(500, 'sequence')
            ->each(function (EnregistrementFiscal $horsSequence) use ($chaine, &$verifies): void {
                $this->verifierLEnregistrement($chaine, $horsSequence, null);
                $verifies++;
            });

        $this->enregistrements()
            ->where('chaine', $chaine->value)
            ->where('sequence', '>', $pointDeReprise['sequence'] ?? 0)
            ->lazyById(500, 'sequence')
            ->each(function (EnregistrementFiscal $enregistrement) use ($chaine, &$precedent, &$pointDeRepriseSuivant, &$verifies): void {
                $precedent = $this->verifierLEnregistrement($chaine, $enregistrement, $precedent);
                $verifies++;

                if ($precedent['signature_valide']) {
                    $pointDeRepriseSuivant = self::pointDeReprise($precedent);
                }
            });

        $this->rapport->noterLaChaine($chaine, $verifies, $pointDeRepriseSuivant);
    }

    /**
     * L'enregistrement du point de reprise, d'où la chaîne repart. La
     * vérification précédente en a vérifié la signature, sans quoi il n'en
     * serait pas un : il doit avoir gardé la même, et son contenu se
     * vérifier encore avec elle, sinon il a changé depuis. Seule exception,
     * une clé qui n'est pas épinglée, que le dépôt a pu retirer depuis : la
     * reprise le dit, sans prétendre que l'enregistrement a changé.
     * L'enregistrement qui le suit doit signer la signature du point de
     * reprise, même s'il a disparu ou changé depuis.
     *
     * @param  array{sequence: int, signature: string}  $pointDeReprise
     * @return array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null, signature_valide: bool}
     */
    private function repartirDu(ChaineFiscale $chaine, array $pointDeReprise): array
    {
        $reference = ['chaine' => $chaine->value, 'sequence' => $pointDeReprise['sequence']];
        $enregistrement = $this->enregistrements()->where('chaine', $chaine->value)->where('sequence', $pointDeReprise['sequence'])->first();
        $situation = sprintf('L\'enregistrement n° %d %s', $pointDeReprise['sequence'], self::nomDeLaChaine($chaine));
        $etat = ['sequence' => $pointDeReprise['sequence'], 'signature' => $pointDeReprise['signature'], 'horodatage' => null, 'gt' => null, 'signature_valide' => false];

        if ($enregistrement === null) {
            $this->rapport->signaler(AnomalieFiscale::rupture('reprise', "{$situation}, dernier vérifié par la vérification précédente, a disparu.", $reference));

            return $etat;
        }

        $aChange = "{$situation} a changé depuis la vérification précédente : il n'est plus celui qu'elle a vérifié.";
        $defaut = match (true) {
            $enregistrement->signature !== $pointDeReprise['signature'] => $aChange,
            ! in_array($enregistrement->cle_id, $this->clesEpinglees, true) => "{$situation}, dernier vérifié par la vérification précédente, est signé avec une clé qui n'est pas épinglée ({$enregistrement->cle_id}).",
            ! $this->signatureValide($enregistrement) => $aChange,
            default => null,
        };

        if ($defaut !== null) {
            $this->rapport->signaler(AnomalieFiscale::defaut('reprise', $defaut, $reference));
        }

        $contenu = self::decoder($enregistrement->contenu);

        return [
            ...$etat,
            'horodatage' => self::horodatageEnBase($enregistrement->getRawOriginal('horodatage')),
            'gt' => $chaine === ChaineFiscale::Vente && $contenu !== null ? self::grandsTotauxSignes($contenu) : null,
            'signature_valide' => $defaut === null,
        ];
    }

    /**
     * Vérifie un enregistrement à la suite du précédent de sa chaîne, et
     * rend ce que l'enregistrement suivant doit prolonger.
     *
     * @param  array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null, signature_valide: bool}|null  $precedent
     * @return array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null, signature_valide: bool}
     */
    private function verifierLEnregistrement(ChaineFiscale $chaine, EnregistrementFiscal $enregistrement, ?array $precedent): array
    {
        $reference = ['chaine' => $chaine->value, 'sequence' => $enregistrement->sequence];
        $situation = sprintf('Enregistrement n° %d %s', $enregistrement->sequence, self::nomDeLaChaine($chaine));
        $suiteDirecte = $this->verifierLaSequence($chaine, $enregistrement, $precedent, $reference);
        $horodatage = self::horodatageEnBase($enregistrement->getRawOriginal('horodatage'));

        if ($suiteDirecte && $enregistrement->signature_precedente !== ($precedent['signature'] ?? null)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('chainage', $precedent === null
                ? "{$situation} : premier de sa chaîne, il porte pourtant une signature précédente."
                : "{$situation} : sa signature précédente n'est pas la signature de l'enregistrement n° {$precedent['sequence']}.", $reference));
        }

        if ($horodatage !== null && ($precedent['horodatage'] ?? null) !== null && $horodatage < $precedent['horodatage']) {
            $this->rapport->signaler(AnomalieFiscale::defaut('horodatage', sprintf(
                '%s : horodaté le %s, avant l\'enregistrement précédent (%s).',
                $situation,
                self::aLHeureDeParis($horodatage),
                self::aLHeureDeParis($precedent['horodatage']),
            ), $reference));
        }

        $suivant = [
            'sequence' => $enregistrement->sequence,
            'signature' => $enregistrement->signature,
            'horodatage' => $horodatage,
            'gt' => null,
            'signature_valide' => $this->verifierLaSignature($enregistrement, $situation, $reference),
        ];
        $contenu = self::decoder($enregistrement->contenu);

        if ($contenu === null) {
            $this->rapport->signaler(AnomalieFiscale::defaut('contenu', "{$situation} : son contenu signé n'est pas un JSON lisible.", $reference));

            return $suivant;
        }

        $ecarts = $this->ecartsDEnTete($chaine, $enregistrement, $contenu);

        if ($ecarts !== []) {
            $this->rapport->signaler(AnomalieFiscale::defaut('en_tete', "{$situation} : l'en-tête signé diffère de ses colonnes (".implode(', ', $ecarts).').', $reference));
        }

        if ($chaine === ChaineFiscale::Vente) {
            $grandsTotauxPrecedents = match (true) {
                $suiteDirecte && $precedent === null => ['reel' => 0, 'absolu' => 0],
                $suiteDirecte => $precedent['gt'],
                default => null,
            };
            $suivant['gt'] = $this->verifierLesGrandsTotaux($contenu, $grandsTotauxPrecedents, $situation, $reference);
        }

        if ($chaine !== ChaineFiscale::Evenement) {
            $this->verifierLeDocument($chaine, $enregistrement, $contenu);
        }

        return $suivant;
    }

    /**
     * La séquence suit-elle directement celle du précédent (1 pour le
     * premier) ? Sinon, la rupture est signalée, comme une séquence
     * inférieure à 1, qu'aucune chaîne n'attribue.
     *
     * @param  array{sequence: int}|null  $precedent
     * @param  array<string, int|string>  $reference
     */
    private function verifierLaSequence(ChaineFiscale $chaine, EnregistrementFiscal $enregistrement, ?array $precedent, array $reference): bool
    {
        $attendue = ($precedent['sequence'] ?? 0) + 1;

        if ($enregistrement->sequence === $attendue) {
            return true;
        }

        $this->rapport->signaler(AnomalieFiscale::rupture('sequence', sprintf('Rupture de séquence %s : %s.', self::nomDeLaChaine($chaine), match (true) {
            $enregistrement->sequence < 1 => "l'enregistrement n° {$enregistrement->sequence} est hors de la séquence de la chaîne, qui commence au n° 1",
            $enregistrement->sequence === $attendue + 1 => "l'enregistrement n° {$attendue} manque avant l'enregistrement n° {$enregistrement->sequence}",
            $enregistrement->sequence > $attendue => sprintf('les enregistrements n° %d à %d manquent avant l\'enregistrement n° %d', $attendue, $enregistrement->sequence - 1, $enregistrement->sequence),
            default => sprintf('l\'enregistrement n° %d suit l\'enregistrement n° %d', $enregistrement->sequence, $attendue - 1),
        }), $reference));

        return false;
    }

    /**
     * La signature se vérifie-t-elle avec la clé épinglée que désigne
     * l'enregistrement, et elle seule ? Sinon, le défaut est signalé : une
     * clé inconnue du dépôt ne prouve rien, quel que soit ce qu'elle
     * vérifie.
     *
     * @param  array<string, int|string>  $reference
     */
    private function verifierLaSignature(EnregistrementFiscal $enregistrement, string $situation, array $reference): bool
    {
        if (! in_array($enregistrement->cle_id, $this->clesEpinglees, true)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('cle', "{$situation} : signé avec une clé qui n'est pas épinglée ({$enregistrement->cle_id}).", $reference));

            return false;
        }

        if (! $this->signatureValide($enregistrement)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('signature', "{$situation} : sa signature ne correspond pas à son contenu signé.", $reference));

            return false;
        }

        return true;
    }

    /**
     * L'algorithme de la signature est-il celui du module, et la signature
     * se vérifie-t-elle avec la clé de l'enregistrement ? Une clé qui n'est
     * pas épinglée ne vérifie rien (voir SignataireFiscal::verifier()).
     */
    private function signatureValide(EnregistrementFiscal $enregistrement): bool
    {
        return $enregistrement->algorithme === SignataireFiscal::ALGORITHME
            && $this->signataire->verifier($enregistrement->contenu, $enregistrement->signature, $enregistrement->cle_id);
    }

    /**
     * Les éléments de l'en-tête signé (voir RegistreFiscal) qui ne sont pas
     * ceux des colonnes de l'enregistrement ou du magasin. Un élément
     * absent ou en trop se voit dans sa structure, les clés et leur ordre.
     *
     * @param  array<string, mixed>  $contenu
     * @return list<string>
     */
    private function ecartsDEnTete(ChaineFiscale $chaine, EnregistrementFiscal $enregistrement, array $contenu): array
    {
        $cles = ['format', 'magasin', 'caisse', 'chaine', 'sequence', 'type', 'horodatage', 'operateur', 'version_module', 'cle_id', 'signature_precedente', ...($chaine === ChaineFiscale::Vente ? ['gt'] : []), 'document', 'donnees'];
        $operateurId = $enregistrement->getRawOriginal('operateur_id');
        $documentType = $enregistrement->getRawOriginal('document_type');
        $documentId = $enregistrement->getRawOriginal('document_id');
        $operateur = $contenu['operateur'] ?? null;

        $ecarts = [
            'structure' => array_keys($contenu) !== $cles,
            'format' => ($contenu['format'] ?? null) !== RegistreFiscal::FORMAT,
            'magasin' => (is_array($contenu['magasin'] ?? null) ? ($contenu['magasin']['uuid'] ?? null) : null) !== $this->magasin->uuid,
            'caisse' => ($contenu['caisse'] ?? null) !== $this->magasin->uuid,
            'chaine' => ($contenu['chaine'] ?? null) !== $chaine->value,
            'sequence' => ($contenu['sequence'] ?? null) !== $enregistrement->sequence,
            'type' => ($contenu['type'] ?? null) !== $enregistrement->type,
            'horodatage' => self::horodatageSigneEnBase($contenu['horodatage'] ?? null) !== $enregistrement->getRawOriginal('horodatage'),
            'operateur' => (is_array($operateur) ? ($operateur['id'] ?? null) : $operateur) !== ($operateurId === null ? null : (int) $operateurId),
            'version_module' => ($contenu['version_module'] ?? null) !== $enregistrement->version_module,
            'cle_id' => ($contenu['cle_id'] ?? null) !== $enregistrement->cle_id,
            'signature_precedente' => ($contenu['signature_precedente'] ?? null) !== $enregistrement->signature_precedente,
            'document' => ($contenu['document'] ?? null) !== ($documentType === null && $documentId === null ? null : ['type' => $documentType, 'id' => $documentId === null ? null : (int) $documentId]),
        ];

        return array_keys(array_filter($ecarts));
    }

    /**
     * Les grands totaux perpétuels de l'enregistrement d'une vente : son
     * montant doit être le total TTC signé de son document, et ses grands
     * totaux ceux du précédent augmentés de ce montant (le réel) et de sa
     * valeur absolue (l'absolu). Sans précédent connu (après une rupture
     * de séquence), seul le montant se vérifie.
     *
     * @param  array<string, mixed>  $contenu
     * @param  array{reel: int, absolu: int}|null  $precedents
     * @param  array<string, int|string>  $reference
     * @return array{reel: int, absolu: int}|null
     */
    private function verifierLesGrandsTotaux(array $contenu, ?array $precedents, string $situation, array $reference): ?array
    {
        $grandsTotaux = self::grandsTotauxSignes($contenu);
        $montant = $contenu['gt']['montant'] ?? null;

        if ($grandsTotaux === null || ! is_int($montant)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('grands_totaux', "{$situation} : ses grands totaux signés sont illisibles.", $reference));

            return null;
        }

        $totalDuDocument = $contenu['donnees']['document']['totaux']['ttc'] ?? null;

        if ($montant !== $totalDuDocument) {
            $this->rapport->signaler(AnomalieFiscale::defaut('grands_totaux', sprintf(
                '%s : le montant qui fait avancer les grands totaux (%s) n\'est pas le total TTC signé de son document (%s).',
                $situation,
                self::enEuros($montant),
                is_int($totalDuDocument) ? self::enEuros($totalDuDocument) : 'illisible',
            ), $reference));
        }

        if ($precedents !== null && ($grandsTotaux['reel'] !== $precedents['reel'] + $montant || $grandsTotaux['absolu'] !== $precedents['absolu'] + abs($montant))) {
            $this->rapport->signaler(AnomalieFiscale::defaut('grands_totaux', sprintf(
                '%s : grands totaux perpétuels de %s (réel) et %s (absolu), au lieu de %s et %s.',
                $situation,
                self::enEuros($grandsTotaux['reel']),
                self::enEuros($grandsTotaux['absolu']),
                self::enEuros($precedents['reel'] + $montant),
                self::enEuros($precedents['absolu'] + abs($montant)),
            ), $reference));
        }

        return $grandsTotaux;
    }

    /**
     * La ligne de tête du registre (voir RegistreFiscal) doit noter, pour
     * chaque chaîne, la séquence et la signature de son dernier
     * enregistrement (et ses grands totaux pour celle des ventes), la
     * version du module et la clé du dernier enregistrement du magasin, et
     * un dernier verrou qui n'est antérieur à aucun enregistrement.
     */
    private function verifierLaTete(): void
    {
        $tete = TeteRegistreFiscal::query()->first();
        $derniers = [];

        foreach (ChaineFiscale::cases() as $chaine) {
            $derniers[$chaine->value] = $this->enregistrements()->where('chaine', $chaine->value)->orderByDesc('sequence')->first();
        }

        $derniers = array_filter($derniers);

        if ($tete === null) {
            if ($derniers !== []) {
                $this->rapport->signaler(AnomalieFiscale::defaut('tete', 'Le registre a des enregistrements, mais aucune ligne de tête ne note leur suite.'));
            }

            return;
        }

        $chaines = is_array($tete->chaines) ? $tete->chaines : [];

        foreach (array_diff(array_keys($chaines), array_keys($derniers)) as $chaineSansEnregistrement) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', "La ligne de tête note une chaîne « {$chaineSansEnregistrement} » qui n'a aucun enregistrement.", ['chaine' => (string) $chaineSansEnregistrement]));
        }

        foreach ($derniers as $valeur => $dernier) {
            $this->verifierLaTeteDeLaChaine(ChaineFiscale::from($valeur), $chaines[$valeur] ?? null, $dernier);
        }

        $dernierDuMagasin = $this->enregistrements()->orderByDesc('id')->first();

        if ($dernierDuMagasin !== null && ($tete->version_module !== $dernierDuMagasin->version_module || $tete->cle_id !== $dernierDuMagasin->cle_id)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', 'La ligne de tête ne note pas la version du module et la clé du dernier enregistrement du registre.'));
        }

        $dernierVerrou = self::horodatageEnBase($tete->getRawOriginal('dernier_horodatage'));
        $plusRecent = collect($derniers)->map(fn (EnregistrementFiscal $dernier): ?string => self::horodatageEnBase($dernier->getRawOriginal('horodatage')))->filter()->max();

        if ($plusRecent !== null && ($dernierVerrou === null || $dernierVerrou < $plusRecent)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', sprintf(
                'La ligne de tête date le dernier verrou du registre %s, avant son enregistrement le plus récent (%s).',
                $dernierVerrou === null ? 'd\'aucun instant' : 'du '.self::aLHeureDeParis($dernierVerrou),
                self::aLHeureDeParis($plusRecent),
            )));
        }
    }

    /**
     * @param  mixed  $teteDeLaChaine  ce que la ligne de tête note de la chaîne
     */
    private function verifierLaTeteDeLaChaine(ChaineFiscale $chaine, mixed $teteDeLaChaine, EnregistrementFiscal $dernier): void
    {
        $reference = ['chaine' => $chaine->value, 'sequence' => $dernier->sequence];
        $prefixe = sprintf('La ligne de tête %s', self::nomDeLaChaine($chaine));

        if (! is_array($teteDeLaChaine)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', "{$prefixe} ne note rien, alors que son dernier enregistrement est le n° {$dernier->sequence}.", $reference));

            return;
        }

        if (($teteDeLaChaine['sequence'] ?? null) !== $dernier->sequence || ($teteDeLaChaine['signature'] ?? null) !== $dernier->signature) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', sprintf(
                '%s note la séquence %s, alors que son dernier enregistrement est le n° %d%s.',
                $prefixe,
                is_int($teteDeLaChaine['sequence'] ?? null) ? $teteDeLaChaine['sequence'] : 'illisible',
                $dernier->sequence,
                ($teteDeLaChaine['sequence'] ?? null) === $dernier->sequence ? ', d\'une autre signature' : '',
            ), $reference));
        }

        if ($chaine !== ChaineFiscale::Vente) {
            return;
        }

        $contenu = self::decoder($dernier->contenu);
        $grandsTotaux = $contenu === null ? null : self::grandsTotauxSignes($contenu);

        if ($grandsTotaux !== null && (($teteDeLaChaine['gt_reel'] ?? null) !== $grandsTotaux['reel'] || ($teteDeLaChaine['gt_absolu'] ?? null) !== $grandsTotaux['absolu'])) {
            $this->rapport->signaler(AnomalieFiscale::defaut('tete', "{$prefixe} ne note pas les grands totaux perpétuels de son dernier enregistrement.", $reference));
        }
    }

    // ---------------------------------------------------------------
    // 2. Les documents
    // ---------------------------------------------------------------

    /**
     * Le document de l'enregistrement d'une vente ou d'un encaissement,
     * recalculé depuis la base, doit être celui qui a été signé, et chaque
     * imputation qu'il signe doit exister en base. Un enregistrement qui ne
     * désigne pas un document numéroté du bon type est signalé par
     * verifierLesEnregistrementsDesDocuments().
     *
     * @param  array<string, mixed>  $contenu
     */
    private function verifierLeDocument(ChaineFiscale $chaine, EnregistrementFiscal $enregistrement, array $contenu): void
    {
        $alias = self::DOCUMENTS_PAR_TYPE[$chaine->value][$enregistrement->type] ?? null;
        $documentId = (int) $enregistrement->getRawOriginal('document_id');

        if ($alias === null || $enregistrement->getRawOriginal('document_type') !== $alias) {
            return;
        }

        $document = match ($alias) {
            'facture' => Facture::query()->whereNotNull('numero')->find($documentId),
            'avoir' => Avoir::query()->whereNotNull('numero')->find($documentId),
            'paiement' => Paiement::query()->find($documentId),
        };

        if ($document === null) {
            return;
        }

        if (! $this->rapport->complet) {
            $this->documentsVerifies[$alias][] = $documentId;
        }

        $libelle = self::libelleDuDocument($alias, $documentId, $document instanceof Paiement ? null : $document->numero);
        $reference = ['chaine' => $chaine->value, 'sequence' => $enregistrement->sequence, 'document' => $alias, 'document_id' => $documentId];
        $donnees = is_array($contenu['donnees'] ?? null) ? $contenu['donnees'] : [];
        $this->noterLesImputationsIntrouvables(self::imputationsSignees($alias, $documentId, $donnees));

        try {
            $recalcule = $document instanceof Paiement
                ? $this->contenuFiscal->encaissement($document)
                : ['document' => $this->contenuFiscal->blocDocument($document)];
        } catch (ErrorException|InvalidArgumentException|MathException|ValueError|TypeError $illisible) {
            $this->rapport->signaler(AnomalieFiscale::defaut('document', "{$libelle} ne se relit plus en base : {$illisible->getMessage()}", $reference));

            return;
        }

        $ecarts = [];

        foreach ($document instanceof Paiement ? ['paiement', 'contre_passe', 'allocations', 'credits_imputes'] : ['document'] as $bloc) {
            $ecarts = [...$ecarts, ...self::ecarts($bloc, $recalcule[$bloc], $donnees[$bloc] ?? null)];
        }

        if ($ecarts !== []) {
            $this->rapport->signaler(AnomalieFiscale::defaut('document', sprintf(
                '%s ne correspond plus à ce qui a été signé %s (%s).',
                $libelle,
                $document instanceof Paiement ? 'à son enregistrement' : 'à son émission',
                implode(', ', array_unique($ecarts)),
            ), $reference));
        }

        if (! $document instanceof Paiement && $document->getRawOriginal('date_emission') !== $enregistrement->getRawOriginal('horodatage')) {
            $dateEmission = self::horodatageEnBase($document->getRawOriginal('date_emission'));
            $horodatage = self::horodatageEnBase($enregistrement->getRawOriginal('horodatage'));

            $this->rapport->signaler(AnomalieFiscale::defaut('date_emission', sprintf(
                '%s porte la date d\'émission %s, qui n\'est pas l\'instant de sa signature (%s).',
                $libelle,
                $dateEmission === null ? 'illisible' : 'du '.self::aLHeureDeParis($dateEmission),
                $horodatage === null ? 'illisible' : self::aLHeureDeParis($horodatage),
            ), $reference));
        }
    }

    /**
     * Note les imputations signées qui n'existent pas en base, signalées
     * par verifierLesImputations().
     *
     * @param  list<array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null, montant: int|null}>  $imputations
     */
    private function noterLesImputationsIntrouvables(array $imputations): void
    {
        foreach ($imputations as $imputation) {
            $cle = self::cleDImputation($imputation);

            $lisible = $imputation['origine_id'] !== null && $imputation['facture_id'] !== null && ($imputation['origine'] !== 'credit' || $imputation['avoir_id'] !== null);

            if (isset($this->imputationsIntrouvables[$cle]) || ($lisible && self::allocationsDeLImputation($imputation)->exists())) {
                continue;
            }

            $this->imputationsIntrouvables[$cle] = $imputation;
        }
    }

    /**
     * Chaque enregistrement d'une vente ou d'un encaissement désigne un
     * document numéroté (un paiement pour un encaissement) de la nature de
     * son type, un ticket dans la série des tickets, une facture dans celle
     * des factures ; chaque document numéroté et chaque paiement a
     * exactement un enregistrement. La base ne rend que ce qui est en
     * défaut.
     */
    private function verifierLesEnregistrementsDesDocuments(): void
    {
        $this->enregistrements()
            ->whereIn('chaine', array_keys(self::DOCUMENTS_PAR_TYPE))
            ->whereNot(static fn (Builder $enregistrements): Builder => self::designentUnDocumentDeLeurType($enregistrements))
            ->orderBy('id')
            ->toBase()
            ->get(['chaine', 'sequence', 'type'])
            ->each(function (stdClass $enregistrement): void {
                $chaine = ChaineFiscale::from($enregistrement->chaine);
                $situation = sprintf('Enregistrement n° %d %s', $enregistrement->sequence, self::nomDeLaChaine($chaine));

                $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', isset(self::DOCUMENTS_PAR_TYPE[$chaine->value][$enregistrement->type])
                    ? "{$situation} : il ne désigne pas le document qu'il enregistre."
                    : "{$situation} : son type « {$enregistrement->type} » n'est pas celui d'un document que sa chaîne enregistre.", ['chaine' => $chaine->value, 'sequence' => (int) $enregistrement->sequence]));
            });

        foreach (array_keys(self::MODELES) as $alias) {
            $this->verifierLEnregistrementUniqueDesDocuments($alias);
            $this->verifierLesDocumentsDesEnregistrements($alias);
        }
    }

    /**
     * Les enregistrements qui désignent un document de la nature de leur
     * type (voir DOCUMENTS_PAR_TYPE).
     *
     * @param  Builder<EnregistrementFiscal>  $enregistrements
     * @return Builder<EnregistrementFiscal>
     */
    private static function designentUnDocumentDeLeurType(Builder $enregistrements): Builder
    {
        return $enregistrements
            ->whereNotNull('enregistrements_fiscaux.document_type')
            ->whereNotNull('enregistrements_fiscaux.document_id')
            ->where(static function (Builder $natures): void {
                foreach (self::DOCUMENTS_PAR_TYPE as $chaine => $types) {
                    foreach ($types as $type => $alias) {
                        $natures->orWhere(static fn (Builder $nature): Builder => $nature
                            ->where('enregistrements_fiscaux.chaine', $chaine)
                            ->where('enregistrements_fiscaux.type', $type)
                            ->where('enregistrements_fiscaux.document_type', $alias));
                    }
                }
            });
    }

    /**
     * La chaîne et les types des enregistrements d'un document.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function enregistrementsDuDocument(string $alias): array
    {
        foreach (self::DOCUMENTS_PAR_TYPE as $chaine => $types) {
            $typesDuDocument = array_keys($types, $alias, true);

            if ($typesDuDocument !== []) {
                return [$chaine, $typesDuDocument];
            }
        }

        throw new LogicException("Aucune chaîne n'enregistre de document « {$alias} ».");
    }

    /**
     * Chaque document numéroté (chaque paiement) a exactement un
     * enregistrement, un ticket ou une facture sous le type de sa série, un
     * paiement sous « contre_passation » s'il en contre-passe un autre,
     * « paiement » sinon : la base compte les enregistrements de chaque
     * document et ne rend que ceux qui sont en défaut. Un document rendu
     * avec un seul enregistrement est donc enregistré sous un autre type
     * que le sien.
     */
    private function verifierLEnregistrementUniqueDesDocuments(string $alias): void
    {
        [$chaine, $types] = self::enregistrementsDuDocument($alias);
        $table = (new (self::MODELES[$alias]))->getTable();
        $numero = $alias === 'paiement' ? 'null' : "{$table}.numero";
        $contrePasse = $alias === 'paiement' ? "{$table}.contre_passe_paiement_id" : 'null';

        self::MODELES[$alias]::query()
            ->when($alias !== 'paiement', static fn (Builder $documents): Builder => $documents->whereNotNull("{$table}.numero"))
            ->leftJoin('enregistrements_fiscaux', static fn (JoinClause $enregistrements): JoinClause => $enregistrements
                ->on('enregistrements_fiscaux.document_id', '=', "{$table}.id")
                ->on('enregistrements_fiscaux.magasin_id', '=', "{$table}.magasin_id")
                ->where('enregistrements_fiscaux.chaine', $chaine)
                ->whereIn('enregistrements_fiscaux.type', $types)
                ->where('enregistrements_fiscaux.document_type', $alias))
            ->groupBy("{$table}.id", ...($alias === 'paiement' ? ["{$table}.contre_passe_paiement_id"] : ["{$table}.numero"]))
            ->havingRaw(...match ($alias) {
                'facture' => [
                    "count(enregistrements_fiscaux.id) <> 1 or min(enregistrements_fiscaux.type) <> case when {$table}.numero like ? then ? else ? end",
                    [TypeDocument::TicketCaisse->code().'-%', 'ticket', 'facture'],
                ],
                'paiement' => [
                    "count(enregistrements_fiscaux.id) <> 1 or min(enregistrements_fiscaux.type) <> case when {$table}.contre_passe_paiement_id is null then ? else ? end",
                    ['paiement', 'contre_passation'],
                ],
                default => ['count(enregistrements_fiscaux.id) <> 1', []],
            })
            ->orderBy("{$table}.id")
            ->toBase()
            ->selectRaw("{$table}.id as id, {$numero} as numero, {$contrePasse} as contre_passe_paiement_id, count(enregistrements_fiscaux.id) as nombre, min(enregistrements_fiscaux.type) as type_enregistre, min(enregistrements_fiscaux.sequence) as sequence_enregistree")
            ->get()
            ->each(function (stdClass $document) use ($alias, $chaine, $types): void {
                $documentId = (int) $document->id;
                $libelle = self::libelleDuDocument($alias, $documentId, $document->numero);
                $nombre = (int) $document->nombre;
                $reference = ['document' => $alias, 'document_id' => $documentId];
                $nomDeLaChaine = self::nomDeLaChaine(ChaineFiscale::from($chaine));

                if ($nombre !== 1) {
                    $sequences = $this->enregistrements()
                        ->where('chaine', $chaine)
                        ->whereIn('type', $types)
                        ->where('document_type', $alias)
                        ->where('document_id', $documentId)
                        ->orderBy('sequence')
                        ->limit(self::LISTE_MAXIMUM)
                        ->pluck('sequence')
                        ->all();

                    $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', $nombre === 0
                        ? "{$libelle} n'a aucun enregistrement {$nomDeLaChaine}."
                        : sprintf('%s a %d enregistrements %s (séquences %s) au lieu d\'un seul.', $libelle, $nombre, $nomDeLaChaine, self::liste($sequences, $nombre)), $reference));

                    return;
                }

                $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', match ($alias) {
                    'paiement' => $document->contre_passe_paiement_id === null
                        ? sprintf('%s est enregistré comme « %s » alors qu\'il ne contre-passe aucun paiement.', $libelle, $document->type_enregistre)
                        : sprintf('%s est enregistré comme « %s » alors qu\'il contre-passe le paiement (identifiant interne %d).', $libelle, $document->type_enregistre, $document->contre_passe_paiement_id),
                    default => sprintf(
                        '%s est enregistré comme « %s » alors que son numéro le place dans la série des %ss.',
                        $libelle,
                        $document->type_enregistre,
                        str_starts_with($document->numero, TypeDocument::TicketCaisse->code().'-') ? 'ticket' : 'facture',
                    ),
                }, [...$reference, 'chaine' => $chaine, 'sequence' => (int) $document->sequence_enregistree]));
            });
    }

    /**
     * Chaque enregistrement d'un document désigne un document numéroté du
     * magasin (un paiement du magasin) : la base ne rend que ceux qui n'en
     * désignent aucun.
     */
    private function verifierLesDocumentsDesEnregistrements(string $alias): void
    {
        [$chaine, $types] = self::enregistrementsDuDocument($alias);
        $table = (new (self::MODELES[$alias]))->getTable();
        $nature = match ($alias) {
            'facture' => 'une facture introuvable ou jamais numérotée',
            'avoir' => 'un avoir introuvable ou jamais numéroté',
            'paiement' => 'un paiement introuvable',
        };

        $this->enregistrements()
            ->where('enregistrements_fiscaux.chaine', $chaine)
            ->whereIn('enregistrements_fiscaux.type', $types)
            ->where('enregistrements_fiscaux.document_type', $alias)
            ->whereNotNull('enregistrements_fiscaux.document_id')
            ->leftJoin($table, static fn (JoinClause $documents): JoinClause => $documents
                ->on("{$table}.id", '=', 'enregistrements_fiscaux.document_id')
                ->on("{$table}.magasin_id", '=', 'enregistrements_fiscaux.magasin_id')
                ->when($alias !== 'paiement', static fn (JoinClause $numerotes): JoinClause => $numerotes->whereNotNull("{$table}.numero")))
            ->whereNull("{$table}.id")
            ->orderBy('enregistrements_fiscaux.sequence')
            ->toBase()
            ->get(['enregistrements_fiscaux.sequence', 'enregistrements_fiscaux.document_id'])
            ->each(fn (stdClass $enregistrement) => $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', sprintf(
                'Enregistrement n° %d %s : il désigne %s (identifiant interne %d).',
                $enregistrement->sequence,
                self::nomDeLaChaine(ChaineFiscale::from($chaine)),
                $nature,
                $enregistrement->document_id,
            ), ['chaine' => $chaine, 'sequence' => (int) $enregistrement->sequence])));
    }

    /**
     * Chaque imputation d'un paiement ou d'un avoir figure dans exactement
     * un contenu signé, pour son montant, et chaque imputation signée
     * existe en base. Les imputations en base se lisent en flux, et pour
     * chacune les seuls contenus qui peuvent la signer. Une vérification
     * incrémentale ne regarde que les imputations des documents qu'elle a
     * vérifiés : toutes naissent dans la transaction où l'un d'eux est
     * signé.
     */
    private function verifierLesImputations(): void
    {
        $complet = $this->rapport->complet;

        PaiementAllocation::query()
            ->when(! $complet, fn (Builder $allocations): Builder => $allocations->whereIntegerInRaw('paiement_id', $this->documentsVerifies['paiement']))
            ->select(['id', 'paiement_id', 'facture_id', 'montant_alloue'])
            ->lazyById(500)
            ->each(fn (PaiementAllocation $allocation) => $this->verifierLImputation([
                'origine' => 'paiement',
                'origine_id' => (int) $allocation->paiement_id,
                'facture_id' => (int) $allocation->facture_id,
                'montant' => $this->centimes($allocation),
            ]));

        AvoirAllocation::query()
            ->when(! $complet, fn (Builder $allocations): Builder => $allocations->where(fn (Builder $documents): Builder => $documents
                ->whereIntegerInRaw('facture_id', $this->documentsVerifies['facture'])
                ->orWhereIntegerInRaw('avoir_id', $this->documentsVerifies['avoir'])
                ->orWhereIntegerInRaw('paiement_id', $this->documentsVerifies['paiement'])))
            ->select(['id', 'avoir_id', 'facture_id', 'paiement_id', 'montant_alloue'])
            ->lazyById(500)
            ->each(fn (AvoirAllocation $allocation) => $this->verifierLImputation($allocation->paiement_id === null ? [
                'origine' => 'avoir',
                'origine_id' => (int) $allocation->avoir_id,
                'facture_id' => (int) $allocation->facture_id,
                'montant' => $this->centimes($allocation),
            ] : [
                'origine' => 'credit',
                'origine_id' => (int) $allocation->paiement_id,
                'avoir_id' => (int) $allocation->avoir_id,
                'facture_id' => (int) $allocation->facture_id,
                'montant' => $this->centimes($allocation),
            ]));

        foreach ($this->imputationsIntrouvables as $cle => $imputation) {
            $this->rapport->signaler(AnomalieFiscale::defaut('imputation', "{$this->libelleDeLImputation($imputation)}, signée, est introuvable en base.", ['imputation' => $cle]));
        }
    }

    /**
     * Une imputation en base figure dans exactement un des contenus qui
     * peuvent la signer, pour son montant : celui de son paiement, celui du
     * paiement qui a imputé ce crédit d'avoir, ou pour un avoir imputé à
     * une émission, celui de la facture qu'il a réglée à son émission ou
     * le sien.
     *
     * @param  array{origine: string, origine_id: int, avoir_id?: int, facture_id: int, montant: int|null}  $imputation
     */
    private function verifierLImputation(array $imputation): void
    {
        $cle = self::cleDImputation($imputation);
        $signataires = $imputation['origine'] === 'avoir'
            ? [['facture', $imputation['facture_id']], ['avoir', $imputation['origine_id']]]
            : [['paiement', $imputation['origine_id']]];
        $signees = [];

        foreach ($signataires as [$alias, $documentId]) {
            foreach ($this->imputationsSigneesDuDocument($alias, $documentId) as $signee) {
                if (self::cleDImputation($signee) === $cle) {
                    $signees[] = $signee['montant'];
                }
            }
        }

        $montant = $imputation['montant'];

        if ($montant !== null && $signees === [$montant]) {
            return;
        }

        $libelle = $this->libelleDeLImputation($imputation);

        $this->rapport->signaler(AnomalieFiscale::defaut('imputation', match (true) {
            $signees === [] => sprintf('%s (%s) ne figure dans aucun contenu signé.', $libelle, self::montant($montant)),
            count($signees) > 1 => sprintf('%s (%s) figure dans %d contenus signés au lieu d\'un seul.', $libelle, self::montant($montant), count($signees)),
            default => sprintf('%s : %s en base, %s dans son contenu signé.', $libelle, self::montant($montant), self::montant($signees[0])),
        }, ['imputation' => $cle]));
    }

    /**
     * Les imputations que signent les enregistrements du document, relues
     * une seule fois pour les imputations du même document qui se suivent.
     *
     * @return list<array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null, montant: int|null}>
     */
    private function imputationsSigneesDuDocument(string $alias, int $documentId): array
    {
        if (($this->dernieresImputationsLues[$alias]['document_id'] ?? null) === $documentId) {
            return $this->dernieresImputationsLues[$alias]['imputations'];
        }

        [$chaine, $types] = self::enregistrementsDuDocument($alias);
        $imputations = [];

        $this->enregistrements()
            ->where('chaine', $chaine)
            ->whereIn('type', $types)
            ->where('document_type', $alias)
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->pluck('contenu')
            ->each(function (string $contenu) use ($alias, $documentId, &$imputations): void {
                $donnees = self::decoder($contenu)['donnees'] ?? null;
                $imputations = [...$imputations, ...self::imputationsSignees($alias, $documentId, is_array($donnees) ? $donnees : [])];
            });

        $this->dernieresImputationsLues[$alias] = ['document_id' => $documentId, 'imputations' => $imputations];

        return $imputations;
    }

    /**
     * Les imputations que signent les données d'un document : celles d'un
     * paiement sur ses factures et le crédit d'avoir qu'il y impute, les
     * crédits d'avoir qui ont réglé une facture à son émission, les
     * imputations d'un avoir sur les factures de son client. Un identifiant
     * ou un montant illisible vaut null.
     *
     * @param  array<array-key, mixed>  $donnees
     * @return list<array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null, montant: int|null}>
     */
    private static function imputationsSignees(string $alias, int $documentId, array $donnees): array
    {
        $entier = static fn (mixed $valeur): ?int => is_int($valeur) ? $valeur : null;
        $listes = match ($alias) {
            'paiement' => [
                [$donnees['allocations'] ?? [], static fn (array $signee): array => ['origine' => 'paiement', 'origine_id' => $documentId, 'facture_id' => $entier($signee['facture_id'] ?? null)]],
                [$donnees['credits_imputes'] ?? [], static fn (array $signee): array => ['origine' => 'credit', 'origine_id' => $documentId, 'avoir_id' => $entier($signee['avoir_id'] ?? null), 'facture_id' => $entier($signee['facture_id'] ?? null)]],
            ],
            'facture' => [[$donnees['contexte']['credits_avoir'] ?? [], static fn (array $signee): array => ['origine' => 'avoir', 'origine_id' => $entier($signee['avoir_id'] ?? null), 'facture_id' => $documentId]]],
            'avoir' => [[$donnees['contexte']['imputations'] ?? [], static fn (array $signee): array => ['origine' => 'avoir', 'origine_id' => $documentId, 'facture_id' => $entier($signee['facture_id'] ?? null)]]],
        };
        $signees = [];

        foreach ($listes as [$imputations, $imputation]) {
            foreach (is_array($imputations) ? $imputations : [] as $signee) {
                if (is_array($signee)) {
                    $signees[] = [...$imputation($signee), 'montant' => $entier($signee['montant'] ?? null)];
                }
            }
        }

        return $signees;
    }

    /**
     * Les allocations en base d'une imputation : un crédit d'avoir imputé
     * par un paiement désigne ce paiement, un crédit imputé à une émission
     * n'en désigne aucun.
     *
     * @param  array{origine: string, origine_id: int, avoir_id?: int, facture_id: int}  $imputation
     * @return Builder<PaiementAllocation>|Builder<AvoirAllocation>
     */
    private static function allocationsDeLImputation(array $imputation): Builder
    {
        $allocations = match ($imputation['origine']) {
            'paiement' => PaiementAllocation::query()->where('paiement_id', $imputation['origine_id']),
            'credit' => AvoirAllocation::query()->where('paiement_id', $imputation['origine_id'])->where('avoir_id', $imputation['avoir_id']),
            default => AvoirAllocation::query()->where('avoir_id', $imputation['origine_id'])->whereNull('paiement_id'),
        };

        return $allocations->where('facture_id', $imputation['facture_id']);
    }

    /**
     * Ce qui est imputé sur chaque facture émise, paiements et crédits
     * d'avoir, contre-passations déduites, et ce que chaque avoir émis a
     * crédité de factures, restent entre zéro et le total TTC du document.
     * La base fait les sommes en centimes, sur les seules imputations du
     * magasin, et ne rend que les documents en défaut.
     */
    private function verifierLesMontantsImputes(): void
    {
        $this->verifierLesMontantsImputesSur('facture', [
            'paye' => [PaiementAllocation::query(), 'facture_id'],
            'credite' => [AvoirAllocation::query(), 'facture_id'],
        ]);
        $this->verifierLesMontantsImputesSur('avoir', [
            'impute' => [AvoirAllocation::query(), 'avoir_id'],
        ]);
    }

    /**
     * @param  array<string, array{0: Builder<PaiementAllocation>|Builder<AvoirAllocation>, 1: string}>  $sommes  par alias de jointure, les imputations et la colonne qui désigne le document
     */
    private function verifierLesMontantsImputesSur(string $alias, array $sommes): void
    {
        $table = (new (self::MODELES[$alias]))->getTable();
        $documents = self::MODELES[$alias]::query()->whereNotNull("{$table}.numero");
        $centimesImputes = [];

        foreach ($sommes as $jointure => [$imputations, $colonne]) {
            $documents->leftJoinSub(
                $imputations->toBase()->selectRaw("{$colonne} as document_id, round(sum(montant_alloue) * 100) as centimes")->groupBy($colonne),
                $jointure,
                "{$jointure}.document_id",
                '=',
                "{$table}.id",
            );
            $centimesImputes[] = "coalesce({$jointure}.centimes, 0)";
        }

        $impute = '('.implode(' + ', $centimesImputes).')';
        $total = "round({$table}.total_ttc * 100)";

        $documents
            ->whereRaw("({$impute} < 0 or {$impute} > {$total})")
            ->orderBy("{$table}.id")
            ->toBase()
            ->selectRaw("{$table}.id as id, {$table}.numero as numero, {$total} as total, {$impute} as impute")
            ->get()
            ->each(function (stdClass $document) use ($alias): void {
                // Une somme ou un total illisible (« NaN », que garde une
                // colonne numeric de PostgreSQL) est déjà signalé avec son
                // imputation (voir verifierLImputation()) ou son document
                // (voir verifierLeDocument()).
                if (! is_numeric($document->impute) || ! is_numeric($document->total)) {
                    return;
                }

                $libelle = self::libelleDuDocument($alias, (int) $document->id, $document->numero);
                $impute = (int) $document->impute;
                $total = (int) $document->total;
                $nature = $alias === 'avoir' ? 'crédités de factures' : 'imputés (paiements et crédits d\'avoir)';

                $this->rapport->signaler(AnomalieFiscale::defaut('montants_imputes', $impute < 0
                    ? sprintf('%s : %s %s, un montant négatif : on y a retiré plus qu\'on n\'y avait imputé.', $libelle, self::enEuros($impute), $nature)
                    : sprintf('%s : %s %s pour un total TTC de %s, soit %s de trop.', $libelle, self::enEuros($impute), $nature, self::enEuros($total), self::enEuros($impute - $total)), ['document' => $alias, 'document_id' => (int) $document->id]));
            });
    }

    // ---------------------------------------------------------------
    // 3. La numérotation
    // ---------------------------------------------------------------

    /**
     * Dans chaque série (ticket, facture, avoir) et chaque année, les
     * numéros vont de 1 au dernier numéro que la série a attribué (voir
     * DocumentNumberingService), chacun une seule fois, écrit comme la
     * série l'écrit, dans l'année de l'émission de son document. Un numéro
     * attribué puis disparu se voit donc aussi, même le dernier. Les
     * documents se lisent en flux, et de chaque série ne se retient que le
     * compte des numéros qu'elle a attribués : chaque numéro n'ayant qu'une
     * écriture, et la base refusant deux documents du même numéro, il en
     * manque autant que ce compte est loin du dernier attribué.
     */
    private function verifierLaNumerotation(): void
    {
        $series = SequenceDocument::query()
            ->where('magasin_id', $this->magasin->getKey())
            ->whereIn('type_document', [TypeDocument::TicketCaisse->value, TypeDocument::Facture->value, TypeDocument::Avoir->value])
            ->get()
            ->groupBy(static fn (SequenceDocument $serie): string => $serie->type_document === TypeDocument::Avoir ? 'avoir' : 'facture');

        foreach (['facture', 'avoir'] as $alias) {
            $seriesDuDocument = ($series[$alias] ?? collect())->keyBy('prefixe')->all();
            $attribuesPresents = array_fill_keys(array_keys($seriesDuDocument), 0);
            $horsDeLaSerie = array_fill_keys(array_keys($seriesDuDocument), []);

            self::MODELES[$alias]::query()
                ->whereNotNull('numero')
                ->toBase()
                ->select(['id', 'numero', 'date_emission'])
                ->lazyById(1000, 'numero')
                ->each(function (stdClass $document) use ($alias, $seriesDuDocument, &$attribuesPresents, &$horsDeLaSerie): void {
                    $serieEtRang = $this->serieDuNumero($document->numero, $seriesDuDocument, $alias, (int) $document->id);

                    if ($serieEtRang === null) {
                        return;
                    }

                    [$serie, $rang] = $serieEtRang;
                    $this->verifierLAnneeDuNumero($serie, $document, $alias);

                    if ($rang >= 1 && $rang <= self::dernierAttribue($serie)) {
                        $attribuesPresents[$serie->prefixe]++;
                    } else {
                        $horsDeLaSerie[$serie->prefixe][] = $rang;
                    }
                });

            foreach ($seriesDuDocument as $prefixe => $serie) {
                $this->verifierLaContinuiteDeLaSerie($alias, $serie, $attribuesPresents[$prefixe], $horsDeLaSerie[$prefixe]);
            }
        }
    }

    /**
     * La série dont le document porte le numéro, écrit comme elle l'écrit,
     * et le rang du numéro dans la série, ou null après avoir signalé qu'il
     * n'appartient à aucune.
     *
     * @param  array<string, SequenceDocument>  $series  par préfixe
     * @return array{0: SequenceDocument, 1: int}|null
     */
    private function serieDuNumero(string $numero, array $series, string $alias, int $documentId): ?array
    {
        $reference = ['document' => $alias, 'document_id' => $documentId, 'numero' => $numero];
        $prefixeEtRang = self::prefixeEtRang($numero);

        if ($prefixeEtRang === null || ! isset($series[$prefixeEtRang[0]])) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', "Le numéro {$numero} n'appartient à aucune série de numérotation du magasin.", $reference));

            return null;
        }

        $serie = $series[$prefixeEtRang[0]];
        $ecritureDeLaSerie = self::numeroDeLaSerie($serie, $prefixeEtRang[1]);

        if ($numero !== $ecritureDeLaSerie) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', "Le numéro {$numero} n'est pas écrit comme sa série écrit ses numéros ({$ecritureDeLaSerie}).", $reference));

            return null;
        }

        return [$serie, $prefixeEtRang[1]];
    }

    private function verifierLAnneeDuNumero(SequenceDocument $serie, stdClass $document, string $alias): void
    {
        $emission = self::horodatageEnBase($document->date_emission);

        if ($serie->period_key === '' || $emission === null) {
            return;
        }

        $anneeDEmission = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $emission, 'UTC')->setTimezone(config('app.timezone'))->format('Y');

        if ($anneeDEmission !== $serie->period_key) {
            $this->rapport->signaler(AnomalieFiscale::defaut('numerotation', sprintf(
                '%s est numéroté dans l\'année %s mais émis le %s.',
                self::libelleDuDocument($alias, (int) $document->id, $document->numero),
                $serie->period_key,
                self::aLHeureDeParis($emission),
            ), ['document' => $alias, 'document_id' => (int) $document->id, 'numero' => $document->numero]));
        }
    }

    /**
     * @param  int  $attribuesPresents  le nombre de documents de la série dont le numéro est l'un de ceux qu'elle a attribués
     * @param  list<int>  $horsDeLaSerie  les rangs des documents de la série hors de ceux qu'elle a attribués
     */
    private function verifierLaContinuiteDeLaSerie(string $alias, SequenceDocument $serie, int $attribuesPresents, array $horsDeLaSerie): void
    {
        $dernierAttribue = self::dernierAttribue($serie);
        $reference = ['serie' => $serie->prefixe];
        $manquants = max(0, $dernierAttribue) - $attribuesPresents;
        $ecrire = fn (int $numero): string => self::numeroDeLaSerie($serie, $numero);

        if ($manquants > 0) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', sprintf(
                'Série %s : %d numéro(s) attribué(s) sans document : %s.',
                $serie->prefixe,
                $manquants,
                self::liste(array_map($ecrire, $this->premiersNumerosManquants($alias, $serie)), $manquants),
            ), $reference));
        }

        if ($horsDeLaSerie !== []) {
            sort($horsDeLaSerie);

            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', sprintf(
                'Série %s : %s hors des numéros qu\'elle a attribués (%s).',
                $serie->prefixe,
                self::liste(array_map($ecrire, $horsDeLaSerie)),
                $dernierAttribue < 1 ? 'aucun' : "de {$ecrire(1)} à {$ecrire($dernierAttribue)}",
            ), $reference));
        }
    }

    /**
     * Les premiers numéros attribués par la série sans document qui les
     * porte, autant qu'un message en énumère : ses documents se lisent dans
     * l'ordre de leurs numéros (une écriture plus longue est un numéro plus
     * grand, sans zéro de tête), jusqu'à les avoir trouvés.
     *
     * @return list<int>
     */
    private function premiersNumerosManquants(string $alias, SequenceDocument $serie): array
    {
        $dernierAttribue = self::dernierAttribue($serie);
        $manquants = [];
        $attendu = 1;

        $numeros = self::MODELES[$alias]::query()
            ->whereRaw('substr(numero, 1, ?) = ?', [strlen($serie->prefixe), $serie->prefixe])
            ->orderByRaw('length(numero)')
            ->orderBy('numero')
            ->toBase()
            ->select(['numero'])
            ->lazy(1000);

        foreach ($numeros as $document) {
            $prefixeEtRang = self::prefixeEtRang($document->numero);

            if ($prefixeEtRang === null || $prefixeEtRang[0] !== $serie->prefixe || self::numeroDeLaSerie($serie, $prefixeEtRang[1]) !== $document->numero || $prefixeEtRang[1] < $attendu) {
                continue;
            }

            for (; $attendu < min($prefixeEtRang[1], $dernierAttribue + 1) && count($manquants) < self::LISTE_MAXIMUM; $attendu++) {
                $manquants[] = $attendu;
            }

            if ($prefixeEtRang[1] > $dernierAttribue || count($manquants) === self::LISTE_MAXIMUM) {
                return $manquants;
            }

            $attendu = $prefixeEtRang[1] + 1;
        }

        for (; $attendu <= $dernierAttribue && count($manquants) < self::LISTE_MAXIMUM; $attendu++) {
            $manquants[] = $attendu;
        }

        return $manquants;
    }

    private static function dernierAttribue(SequenceDocument $serie): int
    {
        return (int) $serie->prochain_numero - 1;
    }

    /**
     * Le préfixe et le rang d'un numéro (« TK-LYON1-2026- » et 12 pour
     * TK-LYON1-2026-000012), ou null s'il ne finit pas par un rang.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function prefixeEtRang(string $numero): ?array
    {
        return preg_match('/^(.*-)(\d+)$/D', $numero, $morceaux) === 1 ? [$morceaux[1], (int) $morceaux[2]] : null;
    }

    // ---------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------

    /**
     * La façon dont le message désigne un document : « Le ticket
     * TK-LYON1-2026-000001 », « La facture FAC-LYON1-2026-000001 »,
     * « L'avoir AV-LYON1-2026-000001 », « Le paiement (identifiant interne
     * 12) », ou par son identifiant interne un document sans numéro.
     */
    private static function libelleDuDocument(string $alias, ?int $documentId, ?string $numero): string
    {
        $identifiant = $documentId ?? '?';

        return match (true) {
            $alias === 'paiement' => "Le paiement (identifiant interne {$identifiant})",
            $numero === null => ($alias === 'avoir' ? 'L\'avoir' : 'La facture')." (identifiant interne {$identifiant})",
            $alias === 'avoir' => "L'avoir {$numero}",
            str_starts_with($numero, TypeDocument::TicketCaisse->code().'-') => "Le ticket {$numero}",
            default => "La facture {$numero}",
        };
    }

    /**
     * Le numéro d'un document à désigner dans un message.
     */
    private static function numeroDuDocument(string $alias, ?int $documentId): ?string
    {
        return $documentId === null ? null : self::MODELES[$alias]::query()->whereKey($documentId)->value('numero');
    }

    /**
     * @param  array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null}  $imputation
     */
    private function libelleDeLImputation(array $imputation): string
    {
        $facture = lcfirst(self::libelleDuDocument('facture', $imputation['facture_id'], self::numeroDuDocument('facture', $imputation['facture_id'])));
        $avoir = static fn (?int $avoirId): string => lcfirst(self::libelleDuDocument('avoir', $avoirId, self::numeroDuDocument('avoir', $avoirId)));

        return match ($imputation['origine']) {
            'paiement' => "L'imputation du paiement (identifiant interne ".($imputation['origine_id'] ?? '?').") sur {$facture}",
            'credit' => 'Le crédit de '.$avoir($imputation['avoir_id'] ?? null).' imputé par le paiement (identifiant interne '.($imputation['origine_id'] ?? '?').") sur {$facture}",
            default => 'L\'imputation de '.$avoir($imputation['origine_id'])." sur {$facture}",
        };
    }

    /**
     * @param  array{origine: string, origine_id: int|null, avoir_id?: int|null, facture_id: int|null}  $imputation
     */
    private static function cleDImputation(array $imputation): string
    {
        $cle = sprintf('%s:%s:%s', $imputation['origine'], $imputation['origine_id'] ?? '?', $imputation['facture_id'] ?? '?');

        return $imputation['origine'] === 'credit' ? $cle.':'.($imputation['avoir_id'] ?? '?') : $cle;
    }

    /**
     * @param  array{sequence: int, signature: string}  $etat
     * @return array{sequence: int, signature: string}
     */
    private static function pointDeReprise(array $etat): array
    {
        return ['sequence' => $etat['sequence'], 'signature' => $etat['signature']];
    }

    private static function numeroDeLaSerie(SequenceDocument $serie, int $numero): string
    {
        return $serie->prefixe.str_pad((string) $numero, (int) $serie->rembourrage, '0', STR_PAD_LEFT);
    }

    private static function nomDeLaChaine(ChaineFiscale $chaine): string
    {
        return match ($chaine) {
            ChaineFiscale::Vente => 'de la chaîne des ventes',
            ChaineFiscale::Encaissement => 'de la chaîne des encaissements',
            ChaineFiscale::Evenement => 'du journal des événements',
        };
    }

    /**
     * Ce qui diffère entre le bloc recalculé et le bloc signé : ses
     * éléments (clés de premier niveau) quand il en a, sinon le bloc
     * lui-même.
     *
     * @return list<string>
     */
    private static function ecarts(string $bloc, mixed $recalcule, mixed $signe): array
    {
        if (! is_array($recalcule) || ! is_array($signe) || array_is_list($recalcule) || array_is_list($signe)) {
            return $recalcule === $signe ? [] : [$bloc];
        }

        return array_values(array_filter(
            array_unique([...array_keys($recalcule), ...array_keys($signe)]),
            static fn (int|string $cle): bool => ! array_key_exists($cle, $recalcule) || ! array_key_exists($cle, $signe) || $recalcule[$cle] !== $signe[$cle],
        ));
    }

    /**
     * Le contenu signé décodé, ou null s'il n'est pas un objet JSON.
     *
     * @return array<string, mixed>|null
     */
    private static function decoder(string $contenu): ?array
    {
        try {
            $decode = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decode) && ($decode === [] || ! array_is_list($decode)) ? $decode : null;
    }

    /**
     * @param  array<string, mixed>  $contenu
     * @return array{reel: int, absolu: int}|null
     */
    private static function grandsTotauxSignes(array $contenu): ?array
    {
        $reel = $contenu['gt']['perpetuel_reel'] ?? null;
        $absolu = $contenu['gt']['perpetuel_absolu'] ?? null;

        return is_int($reel) && is_int($absolu) ? ['reel' => $reel, 'absolu' => $absolu] : null;
    }

    /**
     * Un horodatage tel qu'il s'écrit en base (voir HorodatageUtc), ou null
     * s'il n'en a pas la forme : deux horodatages de cette forme se
     * comparent comme des chaînes.
     */
    private static function horodatageEnBase(mixed $brut): ?string
    {
        return is_string($brut) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $brut) === 1 ? $brut : null;
    }

    /**
     * L'horodatage signé (AAAAMMJJHHMMSSZ, voir InstantFiscal) tel qu'il
     * s'écrit en base, ou null s'il n'en a pas la forme.
     */
    private static function horodatageSigneEnBase(mixed $signe): ?string
    {
        return is_string($signe) && preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/D', $signe, $morceaux) === 1
            ? "{$morceaux[1]}-{$morceaux[2]}-{$morceaux[3]} {$morceaux[4]}:{$morceaux[5]}:{$morceaux[6]}"
            : null;
    }

    private static function aLHeureDeParis(string $horodatageEnBase): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $horodatageEnBase, 'UTC')
            ->setTimezone(config('app.timezone'))
            ->format('d/m/Y à H:i:s');
    }

    /**
     * Le montant en centimes d'une imputation en base, ou null s'il n'est
     * pas un montant décimal lisible : une imputation insérée directement
     * en base peut porter n'importe quoi.
     */
    private function centimes(PaiementAllocation|AvoirAllocation $allocation): ?int
    {
        try {
            return $this->calculateur->enCentiemes($allocation->montant_alloue);
        } catch (InvalidArgumentException|MathException) {
            return null;
        }
    }

    private static function montant(?int $centimes): string
    {
        return $centimes === null ? 'montant illisible' : self::enEuros($centimes);
    }

    private static function enEuros(int $centimes): string
    {
        $absolu = abs($centimes);

        return ($centimes < 0 ? '-' : '').number_format(intdiv($absolu, 100), 0, ',', ' ').','.str_pad((string) ($absolu % 100), 2, '0', STR_PAD_LEFT).' €';
    }

    /**
     * Les valeurs, jusqu'à autant qu'un message en énumère, suivies de
     * points de suspension quand il y en a davantage.
     *
     * @param  list<int|string>  $valeurs
     * @param  int|null  $nombre  combien il y en a en tout, quand $valeurs n'en donne que les premières
     */
    private static function liste(array $valeurs, ?int $nombre = null): string
    {
        $enumerees = array_slice($valeurs, 0, self::LISTE_MAXIMUM);

        return implode(', ', $enumerees).(($nombre ?? count($valeurs)) > count($enumerees) ? '…' : '');
    }
}
