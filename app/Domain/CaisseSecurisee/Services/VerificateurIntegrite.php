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
use ErrorException;
use Illuminate\Database\Eloquent\Builder;
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
 *    enregistrement : séquences continues depuis 1, sans trou ; signature
 *    précédente égale à celle de l'enregistrement précédent, absente du
 *    seul premier ; signature valide pour une clé ÉPINGLÉE, seule à faire
 *    foi (voir SignataireFiscal) ; horodatages qui ne reculent pas ;
 *    en-tête signé identique aux colonnes ; grands totaux perpétuels qui
 *    avancent du montant de chaque vente. Puis la ligne de tête du
 *    registre, qui doit noter le dernier enregistrement de chaque chaîne.
 * 2. Les documents : le bloc « document » d'un ticket, d'une facture ou
 *    d'un avoir, et le paiement et les imputations d'un encaissement,
 *    recalculés depuis la base (voir ContenuFiscal), doivent être ceux qui
 *    ont été signés, et la date d'émission l'instant de la signature :
 *    une modification directe en base se voit ici. Chaque document
 *    numéroté, et chaque paiement, a exactement un enregistrement, et
 *    chaque enregistrement désigne un document numéroté du bon type.
 *    Chaque imputation d'un paiement ou d'un avoir figure dans exactement
 *    un contenu signé : celui de son paiement, de la facture qu'un crédit
 *    d'avoir a réglée à son émission, ou de l'avoir qui l'a imputée sur
 *    ses factures. Le règlement que reprend aussi le contexte d'un ticket
 *    au comptoir n'en est qu'une copie : seul le paiement, dans la chaîne
 *    des encaissements, la signe pour son compte.
 * 3. La numérotation des tickets, des factures et des avoirs : continue
 *    dans chaque série et chaque année, de 1 au dernier numéro que la
 *    série a attribué, chaque numéro dans l'année de l'émission de son
 *    document.
 *
 * Avec des points de reprise, ceux de la vérification précédente (voir
 * VerifierIntegriteAction), elle ne reprend chaque chaîne qu'après son
 * point de reprise, dont l'enregistrement doit être resté celui qu'elle
 * avait vérifié, et ne recalcule que les documents et les imputations de
 * ces nouveaux enregistrements. La ligne de tête, la correspondance entre
 * documents et enregistrements et la numérotation, peu coûteuses, se
 * vérifient toujours entièrement.
 *
 * Rien n'est verrouillé, pour ne jamais arrêter les ventes : tout se lit
 * dans une même transaction, qui donne sous MySQL et MariaDB (REPEATABLE
 * READ) une photo cohérente de la base, sans qu'une vente validée en cours
 * de route n'y paraisse à moitié. Ce qui s'inscrit pendant la vérification
 * sera vérifié par la suivante. Les clôtures de caisse et les archives ne
 * sont pas encore vérifiées : leur forme définitive attend les décisions
 * de l'éditeur.
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
        'encaissement' => ['paiement' => 'paiement'],
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
     * Les tickets et factures numérotés du magasin, par identifiant :
     * numéro et date d'émission telle qu'enregistrée (UTC).
     *
     * @var array<int, stdClass>
     */
    private array $factures = [];

    /**
     * Les avoirs numérotés du magasin, comme $factures.
     *
     * @var array<int, stdClass>
     */
    private array $avoirs = [];

    /**
     * Les documents dont l'enregistrement a été vérifié, par alias.
     *
     * @var array{facture: list<int>, avoir: list<int>, paiement: list<int>}
     */
    private array $documentsVerifies = ['facture' => [], 'avoir' => [], 'paiement' => []];

    /**
     * Les imputations que signent les enregistrements vérifiés, par clé
     * (voir cleDImputation()) : le montant signé, en centimes (null s'il
     * est illisible), à chaque fois qu'elles y figurent.
     *
     * @var array<string, list<int|null>>
     */
    private array $imputationsSignees = [];

    public function __construct(
        private readonly SignataireFiscal $signataire,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Vérifie le registre du magasin actif, entièrement, ou après les
     * points de reprise de la vérification précédente.
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

        $this->rapport = new RapportIntegrite($pointsDeReprise === null);
        $this->magasin = $magasin;
        $this->clesEpinglees = array_keys($this->signataire->clesEpinglees());
        $this->documentsVerifies = ['facture' => [], 'avoir' => [], 'paiement' => []];
        $this->imputationsSignees = [];

        DB::transaction(function () use ($pointsDeReprise): void {
            $this->factures = Facture::query()->whereNotNull('numero')->toBase()->get(['id', 'numero', 'date_emission'])->keyBy('id')->all();
            $this->avoirs = Avoir::query()->whereNotNull('numero')->toBase()->get(['id', 'numero', 'date_emission'])->keyBy('id')->all();

            $this->verifierLesChainesInconnues();

            foreach (ChaineFiscale::cases() as $chaine) {
                $this->verifierLaChaine($chaine, $pointsDeReprise[$chaine->value] ?? null);
            }

            $this->verifierLaTete();
            $this->verifierLesEnregistrementsDesDocuments();
            $this->verifierLesImputations();
            $this->verifierLaNumerotation();
        });

        return $this->rapport;
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
     * vérifié.
     *
     * @param  array{sequence: int, signature: string}|null  $pointDeReprise
     */
    private function verifierLaChaine(ChaineFiscale $chaine, ?array $pointDeReprise): void
    {
        $precedent = $pointDeReprise === null ? null : $this->repartirDu($chaine, $pointDeReprise);
        $verifies = 0;

        $this->enregistrements()
            ->where('chaine', $chaine->value)
            ->where('sequence', '>', $pointDeReprise['sequence'] ?? 0)
            ->lazyById(500, 'sequence')
            ->each(function (EnregistrementFiscal $enregistrement) use ($chaine, &$precedent, &$verifies): void {
                $precedent = $this->verifierLEnregistrement($chaine, $enregistrement, $precedent);
                $verifies++;
            });

        $this->rapport->noterLaChaine(
            $chaine,
            $verifies,
            $precedent === null ? null : ['sequence' => $precedent['sequence'], 'signature' => $precedent['signature']],
        );
    }

    /**
     * L'enregistrement du point de reprise, d'où la chaîne repart : il doit
     * être resté celui que la vérification précédente a vérifié, même
     * signature et contenu qui se vérifie avec elle. L'enregistrement qui
     * le suit doit signer la signature du point de reprise, même s'il a
     * disparu ou changé depuis.
     *
     * @param  array{sequence: int, signature: string}  $pointDeReprise
     * @return array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null}
     */
    private function repartirDu(ChaineFiscale $chaine, array $pointDeReprise): array
    {
        $reference = ['chaine' => $chaine->value, 'sequence' => $pointDeReprise['sequence']];
        $enregistrement = $this->enregistrements()->where('chaine', $chaine->value)->where('sequence', $pointDeReprise['sequence'])->first();
        $etat = ['sequence' => $pointDeReprise['sequence'], 'signature' => $pointDeReprise['signature'], 'horodatage' => null, 'gt' => null];

        if ($enregistrement === null) {
            $this->rapport->signaler(AnomalieFiscale::rupture('reprise', sprintf(
                'L\'enregistrement n° %d %s, dernier vérifié par la vérification précédente, a disparu.',
                $pointDeReprise['sequence'],
                self::nomDeLaChaine($chaine),
            ), $reference));

            return $etat;
        }

        if ($enregistrement->signature !== $pointDeReprise['signature'] || ! $this->signataire->verifier($enregistrement->contenu, $enregistrement->signature, $enregistrement->cle_id)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('reprise', sprintf(
                'L\'enregistrement n° %d %s a changé depuis la vérification précédente : il n\'est plus celui qu\'elle a vérifié.',
                $pointDeReprise['sequence'],
                self::nomDeLaChaine($chaine),
            ), $reference));
        }

        $contenu = self::decoder($enregistrement->contenu);

        return [
            ...$etat,
            'horodatage' => self::horodatageEnBase($enregistrement->getRawOriginal('horodatage')),
            'gt' => $chaine === ChaineFiscale::Vente && $contenu !== null ? self::grandsTotauxSignes($contenu) : null,
        ];
    }

    /**
     * Vérifie un enregistrement à la suite du précédent de sa chaîne, et
     * rend ce que l'enregistrement suivant doit prolonger.
     *
     * @param  array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null}|null  $precedent
     * @return array{sequence: int, signature: string, horodatage: string|null, gt: array{reel: int, absolu: int}|null}
     */
    private function verifierLEnregistrement(ChaineFiscale $chaine, EnregistrementFiscal $enregistrement, ?array $precedent): array
    {
        $reference = ['chaine' => $chaine->value, 'sequence' => $enregistrement->sequence];
        $situation = sprintf('Enregistrement n° %d %s', $enregistrement->sequence, self::nomDeLaChaine($chaine));
        $suiteDirecte = $this->verifierLaSequence($chaine, $enregistrement, $precedent, $reference);
        $horodatage = self::horodatageEnBase($enregistrement->getRawOriginal('horodatage'));
        $suivant = ['sequence' => $enregistrement->sequence, 'signature' => $enregistrement->signature, 'horodatage' => $horodatage, 'gt' => null];

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

        $this->verifierLaSignature($enregistrement, $situation, $reference);
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
     * premier) ? Sinon, la rupture est signalée.
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

        $this->rapport->signaler(AnomalieFiscale::rupture('sequence', $enregistrement->sequence > $attendue
            ? sprintf(
                'Rupture de séquence %s : %s avant l\'enregistrement n° %d.',
                self::nomDeLaChaine($chaine),
                $enregistrement->sequence === $attendue + 1
                    ? "l'enregistrement n° {$attendue} manque"
                    : sprintf('les enregistrements n° %d à %d manquent', $attendue, $enregistrement->sequence - 1),
                $enregistrement->sequence,
            )
            : sprintf('Rupture de séquence %s : l\'enregistrement n° %d suit l\'enregistrement n° %d.', self::nomDeLaChaine($chaine), $enregistrement->sequence, $attendue - 1), $reference));

        return false;
    }

    /**
     * La signature se vérifie avec la clé épinglée que désigne
     * l'enregistrement, et elle seule : une clé inconnue du dépôt ne
     * prouve rien, quel que soit ce qu'elle vérifie.
     *
     * @param  array<string, int|string>  $reference
     */
    private function verifierLaSignature(EnregistrementFiscal $enregistrement, string $situation, array $reference): void
    {
        if (! in_array($enregistrement->cle_id, $this->clesEpinglees, true)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('cle', "{$situation} : signé avec une clé qui n'est pas épinglée ({$enregistrement->cle_id}).", $reference));

            return;
        }

        if ($enregistrement->algorithme !== SignataireFiscal::ALGORITHME || ! $this->signataire->verifier($enregistrement->contenu, $enregistrement->signature, $enregistrement->cle_id)) {
            $this->rapport->signaler(AnomalieFiscale::defaut('signature', "{$situation} : sa signature ne correspond pas à son contenu signé.", $reference));
        }
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
     * recalculé depuis la base, doit être celui qui a été signé. Un
     * enregistrement qui ne désigne pas un document numéroté du bon type
     * est signalé par verifierLesEnregistrementsDesDocuments().
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
            'facture' => isset($this->factures[$documentId]) ? Facture::query()->find($documentId) : null,
            'avoir' => isset($this->avoirs[$documentId]) ? Avoir::query()->find($documentId) : null,
            'paiement' => Paiement::query()->find($documentId),
        };

        if ($document === null) {
            return;
        }

        $this->documentsVerifies[$alias][] = $documentId;
        $libelle = $this->libelleDuDocument($alias, $documentId);
        $reference = ['chaine' => $chaine->value, 'sequence' => $enregistrement->sequence, 'document' => $alias, 'document_id' => $documentId];
        $donnees = is_array($contenu['donnees'] ?? null) ? $contenu['donnees'] : [];
        $this->noterLesImputationsSignees($alias, $documentId, $donnees);

        try {
            $recalcule = $document instanceof Paiement
                ? $this->contenuFiscal->encaissement($document)
                : ['document' => $this->contenuFiscal->blocDocument($document)];
        } catch (ErrorException|InvalidArgumentException|MathException|ValueError|TypeError $illisible) {
            $this->rapport->signaler(AnomalieFiscale::defaut('document', "{$libelle} ne se relit plus en base : {$illisible->getMessage()}", $reference));

            return;
        }

        $ecarts = [];

        foreach ($document instanceof Paiement ? ['paiement', 'allocations'] : ['document'] as $bloc) {
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
     * Note les imputations que signe le document : celles d'un paiement
     * sur ses factures, les crédits d'avoir qui ont réglé une facture à son
     * émission, les imputations d'un avoir sur les factures qu'il corrige.
     *
     * @param  array<array-key, mixed>  $donnees
     */
    private function noterLesImputationsSignees(string $alias, int $documentId, array $donnees): void
    {
        [$imputations, $cle] = match ($alias) {
            'paiement' => [$donnees['allocations'] ?? [], static fn (array $imputation): string => self::cleDImputation('paiement', $documentId, $imputation['facture_id'] ?? null)],
            'facture' => [$donnees['contexte']['credits_avoir'] ?? [], static fn (array $imputation): string => self::cleDImputation('avoir', $imputation['avoir_id'] ?? null, $documentId)],
            'avoir' => [$donnees['contexte']['imputations'] ?? [], static fn (array $imputation): string => self::cleDImputation('avoir', $documentId, $imputation['facture_id'] ?? null)],
        };

        foreach (is_array($imputations) ? $imputations : [] as $imputation) {
            if (is_array($imputation)) {
                $this->imputationsSignees[$cle($imputation)][] = is_int($imputation['montant'] ?? null) ? $imputation['montant'] : null;
            }
        }
    }

    /**
     * Chaque enregistrement d'une vente ou d'un encaissement désigne un
     * document numéroté (un paiement pour un encaissement) de la nature de
     * son type, un ticket dans la série des tickets, une facture dans celle
     * des factures ; chaque document numéroté et chaque paiement a
     * exactement un enregistrement.
     */
    private function verifierLesEnregistrementsDesDocuments(): void
    {
        $enregistrementsParDocument = ['facture' => [], 'avoir' => [], 'paiement' => []];

        $this->enregistrements()
            ->whereIn('chaine', array_keys(self::DOCUMENTS_PAR_TYPE))
            ->select(['id', 'chaine', 'sequence', 'type', 'document_type', 'document_id'])
            ->lazyById(1000)
            ->each(function (EnregistrementFiscal $enregistrement) use (&$enregistrementsParDocument): void {
                $chaine = (string) $enregistrement->getRawOriginal('chaine');
                $alias = self::DOCUMENTS_PAR_TYPE[$chaine][$enregistrement->type] ?? null;
                $situation = sprintf('Enregistrement n° %d %s', $enregistrement->sequence, self::nomDeLaChaine(ChaineFiscale::from($chaine)));
                $reference = ['chaine' => $chaine, 'sequence' => $enregistrement->sequence];

                if ($alias === null) {
                    $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', "{$situation} : son type « {$enregistrement->type} » n'est pas celui d'un document que sa chaîne enregistre.", $reference));

                    return;
                }

                if ($enregistrement->getRawOriginal('document_type') !== $alias || $enregistrement->getRawOriginal('document_id') === null) {
                    $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', "{$situation} : il ne désigne pas le document qu'il enregistre.", $reference));

                    return;
                }

                $enregistrementsParDocument[$alias][(int) $enregistrement->getRawOriginal('document_id')][] = ['sequence' => $enregistrement->sequence, 'type' => $enregistrement->type, 'reference' => $reference, 'situation' => $situation];
            });

        $documents = [
            'facture' => array_keys($this->factures),
            'avoir' => array_keys($this->avoirs),
            'paiement' => Paiement::query()->orderBy('id')->pluck('id')->map(static fn (int|string $id): int => (int) $id)->all(),
        ];

        foreach ($documents as $alias => $identifiants) {
            foreach ($identifiants as $documentId) {
                $this->verifierLEnregistrementUniqueDuDocument($alias, $documentId, $enregistrementsParDocument[$alias][$documentId] ?? []);
            }

            foreach (array_diff_key($enregistrementsParDocument[$alias], array_flip($identifiants)) as $documentId => $enregistrements) {
                foreach ($enregistrements as $enregistrement) {
                    $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', sprintf(
                        '%s : il désigne %s introuvable%s (identifiant interne %d).',
                        $enregistrement['situation'],
                        $alias === 'paiement' ? 'un paiement' : ($alias === 'avoir' ? 'un avoir' : 'une facture'),
                        $alias === 'paiement' ? '' : ' ou jamais numéroté'.($alias === 'avoir' ? '' : 'e'),
                        $documentId,
                    ), $enregistrement['reference']));
                }
            }
        }
    }

    /**
     * @param  list<array{sequence: int, type: string, reference: array<string, int|string>, situation: string}>  $enregistrements
     */
    private function verifierLEnregistrementUniqueDuDocument(string $alias, int $documentId, array $enregistrements): void
    {
        $libelle = $this->libelleDuDocument($alias, $documentId);
        $chaine = $alias === 'paiement' ? ChaineFiscale::Encaissement : ChaineFiscale::Vente;
        $reference = ['document' => $alias, 'document_id' => $documentId];

        if (count($enregistrements) !== 1) {
            $sequences = array_column($enregistrements, 'sequence');

            $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', $enregistrements === []
                ? sprintf('%s n\'a aucun enregistrement %s.', $libelle, self::nomDeLaChaine($chaine))
                : sprintf('%s a %d enregistrements %s (séquences %s) au lieu d\'un seul.', $libelle, count($enregistrements), self::nomDeLaChaine($chaine), self::liste($sequences)), $reference));

            return;
        }

        if ($alias !== 'facture') {
            return;
        }

        $typeAttendu = str_starts_with($this->factures[$documentId]->numero, TypeDocument::TicketCaisse->code().'-') ? 'ticket' : 'facture';

        if ($enregistrements[0]['type'] !== $typeAttendu) {
            $this->rapport->signaler(AnomalieFiscale::defaut('enregistrement_du_document', sprintf(
                '%s est enregistré comme « %s » alors que son numéro le place dans la série des %ss.',
                $libelle,
                $enregistrements[0]['type'],
                $typeAttendu,
            ), [...$reference, ...$enregistrements[0]['reference']]));
        }
    }

    /**
     * Chaque imputation d'un paiement ou d'un avoir figure dans exactement
     * un contenu signé, pour son montant, et chaque imputation signée
     * existe en base. Une vérification incrémentale ne regarde que les
     * imputations des documents qu'elle a vérifiés : toutes naissent dans
     * la transaction où l'un d'eux est signé.
     */
    private function verifierLesImputations(): void
    {
        $complet = $this->rapport->complet;
        $imputations = [];

        $paiements = PaiementAllocation::query()
            ->when(! $complet, fn (Builder $allocations): Builder => $allocations->whereIn('paiement_id', $this->documentsVerifies['paiement']))
            ->orderBy('id')
            ->get(['paiement_id', 'facture_id', 'montant_alloue']);

        foreach ($paiements as $allocation) {
            $imputations[self::cleDImputation('paiement', (int) $allocation->paiement_id, (int) $allocation->facture_id)] = $this->centimes($allocation);
        }

        $avoirs = AvoirAllocation::query()
            ->when(! $complet, fn (Builder $allocations): Builder => $allocations->where(fn (Builder $documents): Builder => $documents
                ->whereIn('facture_id', $this->documentsVerifies['facture'])
                ->orWhereIn('avoir_id', $this->documentsVerifies['avoir'])))
            ->orderBy('id')
            ->get(['avoir_id', 'facture_id', 'montant_alloue']);

        foreach ($avoirs as $allocation) {
            $imputations[self::cleDImputation('avoir', (int) $allocation->avoir_id, (int) $allocation->facture_id)] = $this->centimes($allocation);
        }

        foreach ($imputations as $cle => $montant) {
            $signees = $this->imputationsSignees[$cle] ?? [];
            $libelle = $this->libelleDeLImputation($cle);
            $reference = ['imputation' => $cle];

            $message = match (true) {
                $signees === [] => sprintf('%s (%s) ne figure dans aucun contenu signé.', $libelle, self::montant($montant)),
                count($signees) > 1 => sprintf('%s (%s) figure dans %d contenus signés au lieu d\'un seul.', $libelle, self::montant($montant), count($signees)),
                $montant === null || $signees[0] !== $montant => sprintf('%s : %s en base, %s dans son contenu signé.', $libelle, self::montant($montant), self::montant($signees[0])),
                default => null,
            };

            if ($message !== null) {
                $this->rapport->signaler(AnomalieFiscale::defaut('imputation', $message, $reference));
            }
        }

        foreach (array_diff_key($this->imputationsSignees, $imputations) as $cle => $signees) {
            $this->rapport->signaler(AnomalieFiscale::defaut('imputation', "{$this->libelleDeLImputation($cle)}, signée, est introuvable en base.", ['imputation' => $cle]));
        }
    }

    // ---------------------------------------------------------------
    // 3. La numérotation
    // ---------------------------------------------------------------

    /**
     * Dans chaque série (ticket, facture, avoir) et chaque année, les
     * numéros vont de 1 au dernier numéro que la série a attribué (voir
     * DocumentNumberingService), chacun une seule fois, écrit comme la
     * série l'écrit, dans l'année de l'émission de son document. Un numéro
     * attribué puis disparu se voit donc aussi, même le dernier.
     */
    private function verifierLaNumerotation(): void
    {
        $series = SequenceDocument::query()
            ->where('magasin_id', $this->magasin->getKey())
            ->whereIn('type_document', [TypeDocument::TicketCaisse->value, TypeDocument::Facture->value, TypeDocument::Avoir->value])
            ->get()
            ->groupBy(static fn (SequenceDocument $serie): string => $serie->type_document === TypeDocument::Avoir ? 'avoir' : 'facture');

        foreach (['facture' => $this->factures, 'avoir' => $this->avoirs] as $alias => $documents) {
            $seriesDuDocument = ($series[$alias] ?? collect())->keyBy('prefixe');
            $numerosParSerie = [];

            foreach ($documents as $documentId => $document) {
                $serie = $this->serieDuNumero($document->numero, $seriesDuDocument->all(), $alias, (int) $documentId);

                if ($serie === null) {
                    continue;
                }

                $numerosParSerie[$serie->prefixe][] = (int) substr($document->numero, strlen($serie->prefixe));
                $this->verifierLAnneeDuNumero($serie, $document, $alias, (int) $documentId);
            }

            foreach ($seriesDuDocument as $prefixe => $serie) {
                $this->verifierLaContinuiteDeLaSerie($serie, $numerosParSerie[$prefixe] ?? []);
            }
        }
    }

    /**
     * La série dont le document porte le numéro, écrit comme elle l'écrit,
     * ou null après avoir signalé qu'il n'appartient à aucune.
     *
     * @param  array<string, SequenceDocument>  $series  par préfixe
     */
    private function serieDuNumero(string $numero, array $series, string $alias, int $documentId): ?SequenceDocument
    {
        $reference = ['document' => $alias, 'document_id' => $documentId, 'numero' => $numero];

        if (preg_match('/^(.*-)(\d+)$/D', $numero, $morceaux) !== 1 || ! isset($series[$morceaux[1]])) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', "Le numéro {$numero} n'appartient à aucune série de numérotation du magasin.", $reference));

            return null;
        }

        $serie = $series[$morceaux[1]];
        $ecritureDeLaSerie = self::numeroDeLaSerie($serie, (int) $morceaux[2]);

        if ($numero !== $ecritureDeLaSerie) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', "Le numéro {$numero} n'est pas écrit comme sa série écrit ses numéros ({$ecritureDeLaSerie}).", $reference));

            return null;
        }

        return $serie;
    }

    private function verifierLAnneeDuNumero(SequenceDocument $serie, stdClass $document, string $alias, int $documentId): void
    {
        $emission = self::horodatageEnBase($document->date_emission);

        if ($serie->period_key === '' || $emission === null) {
            return;
        }

        $anneeDEmission = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $emission, 'UTC')->setTimezone(config('app.timezone'))->format('Y');

        if ($anneeDEmission !== $serie->period_key) {
            $this->rapport->signaler(AnomalieFiscale::defaut('numerotation', sprintf(
                '%s est numéroté dans l\'année %s mais émis le %s.',
                $this->libelleDuDocument($alias, $documentId),
                $serie->period_key,
                self::aLHeureDeParis($emission),
            ), ['document' => $alias, 'document_id' => $documentId, 'numero' => $document->numero]));
        }
    }

    /**
     * @param  list<int>  $numeros  ceux que portent les documents de la série
     */
    private function verifierLaContinuiteDeLaSerie(SequenceDocument $serie, array $numeros): void
    {
        $dernierAttribue = (int) $serie->prochain_numero - 1;
        $reference = ['serie' => $serie->prefixe];
        $manquants = $dernierAttribue < 1 ? [] : array_values(array_diff(range(1, $dernierAttribue), $numeros));
        $auDela = array_values(array_filter($numeros, static fn (int $numero): bool => $numero < 1 || $numero > $dernierAttribue));
        sort($auDela);
        $ecrire = fn (int $numero): string => self::numeroDeLaSerie($serie, $numero);

        if ($manquants !== []) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', sprintf(
                'Série %s : %d numéro(s) attribué(s) sans document : %s.',
                $serie->prefixe,
                count($manquants),
                self::liste(array_map($ecrire, $manquants)),
            ), $reference));
        }

        if ($auDela !== []) {
            $this->rapport->signaler(AnomalieFiscale::rupture('numerotation', sprintf(
                'Série %s : %s hors des numéros qu\'elle a attribués (%s).',
                $serie->prefixe,
                self::liste(array_map($ecrire, $auDela)),
                $dernierAttribue < 1 ? 'aucun' : "de {$ecrire(1)} à {$ecrire($dernierAttribue)}",
            ), $reference));
        }
    }

    // ---------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------

    /**
     * La façon dont le message désigne un document : « Le ticket
     * TK-2026-000001 », « La facture FAC-2026-000001 », « L'avoir
     * AV-2026-000001 », « Le paiement (identifiant interne 12) ».
     */
    private function libelleDuDocument(string $alias, int $documentId): string
    {
        $numero = match ($alias) {
            'facture' => $this->factures[$documentId]->numero ?? null,
            'avoir' => $this->avoirs[$documentId]->numero ?? null,
            default => null,
        };

        return match (true) {
            $alias === 'paiement' => "Le paiement (identifiant interne {$documentId})",
            $numero === null => ($alias === 'avoir' ? 'L\'avoir' : 'La facture')." (identifiant interne {$documentId})",
            $alias === 'avoir' => "L'avoir {$numero}",
            str_starts_with($numero, TypeDocument::TicketCaisse->code().'-') => "Le ticket {$numero}",
            default => "La facture {$numero}",
        };
    }

    private function libelleDeLImputation(string $cle): string
    {
        [$origine, $origineId, $factureId] = explode(':', $cle) + ['', '', ''];
        $facture = lcfirst($this->libelleDuDocument('facture', (int) $factureId));

        return $origine === 'paiement'
            ? "L'imputation du paiement (identifiant interne {$origineId}) sur {$facture}"
            : 'L\'imputation de '.lcfirst($this->libelleDuDocument('avoir', (int) $origineId))." sur {$facture}";
    }

    private static function cleDImputation(string $origine, mixed $origineId, mixed $factureId): string
    {
        return sprintf('%s:%s:%s', $origine, is_int($origineId) ? $origineId : '?', is_int($factureId) ? $factureId : '?');
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
     * @param  list<int|string>  $valeurs
     */
    private static function liste(array $valeurs): string
    {
        $liste = implode(', ', array_slice($valeurs, 0, self::LISTE_MAXIMUM));

        return count($valeurs) > self::LISTE_MAXIMUM ? "{$liste}…" : $liste;
    }
}
