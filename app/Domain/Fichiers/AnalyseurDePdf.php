<?php

namespace App\Domain\Fichiers;

use App\Domain\Fichiers\Enums\ContenuActif;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Tenancy\Support\Reglage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use XMLReader;

/**
 * L'analyse complète d'un PDF reçu (décision de l'éditeur du 07/11/2026,
 * Q-FI1 = a : un PDF qui contient du code actif est refusé, jamais
 * nettoyé), par qpdf (licence Apache 2.0), un programme reconnu du
 * système (voir config/piliqi.php), qui lit chaque objet, décompresse les
 * flux et les flux d'objets, déchiffre un PDF protégé sans mot de passe
 * d'ouverture et rend chaque nom décodé (« /J#61vaScript » se lit
 * « /JavaScript »).
 *
 * Dans cet ordre :
 * - après son dernier « %%EOF », un PDF ne contient que des blancs : un
 *   autre contenu (un programme, une archive) n'est pas du PDF ;
 * - un PDF qui demande un mot de passe pour s'ouvrir est refusé ;
 * - qpdf --check lit tout le fichier et décode chaque flux, sans erreur ni
 *   avertissement : un PDF abîmé, incomplet, ou dont qpdf doit
 *   reconstruire la table des objets (qu'une visionneuse lirait peut-être
 *   autrement) est refusé ;
 * - chaque mise à jour incrémentale se lit pour elle-même (le fichier
 *   jusqu'à chacun de ses « startxref … %%EOF »), puis le fichier entier :
 *   du code ajouté par une mise à jour, ou laissé dans une version
 *   précédente, se voit ; au plus REVISIONS_AU_PLUS mises à jour ;
 * - dans chaque objet, sont refusés : JavaScript (/JavaScript, /JS),
 *   lancement de programme (/Launch), envoi de formulaire ou import de
 *   données (/SubmitForm, /ImportData), contenus multimédias (/RichMedia,
 *   actions /Rendition, /Movie, /Sound, annotations de film, de son ou
 *   d'écran) et formulaires dynamiques (/XFA), liens vers d'autres fichiers
 *   (/GoToR, /GoToE), action automatique (/OpenAction, /AA) autre qu'une
 *   simple destination d'affichage (une destination, ou une action /GoTo
 *   suivie seulement d'autres /GoTo), fichier joint (/EmbeddedFile,
 *   /FileAttachment, /RF) autre qu'un seul XML de Factur-X ;
 * - le XML d'une Factur-X : un seul fichier joint, nommé factur-x.xml,
 *   zugferd-invoice.xml, ZUGFeRD-invoice.xml ou xrechnung.xml, de type
 *   text/xml ou application/xml, de XML_MAXIMAL octets au plus une fois
 *   décompressé, bien formé, sans DOCTYPE ni entité, lu par un analyseur
 *   sans accès au réseau ni substitution d'entités ; il n'est pas lu
 *   au-delà (son import viendra avec SUPER PDP, étape 2).
 *
 * Un PDF que qpdf ne lit pas en DELAI_EN_SECONDES secondes, ou dont la
 * description dépasse SORTIE_MAXIMALE octets, est refusé comme illisible ;
 * un qpdf absent ou en échec refuse le fichier sans le juger (« réessayez
 * dans quelques minutes »), et l'erreur reste au journal de
 * l'application : un PDF n'est jamais accepté sans analyse.
 */
final class AnalyseurDePdf
{
    public const DELAI_EN_SECONDES = 20;

    public const REVISIONS_AU_PLUS = 32;

    public const SORTIE_MAXIMALE = 64 * 1024 * 1024;

    public const XML_MAXIMAL = 5 * 1024 * 1024;

    public const NOMS_FACTUR_X = ['factur-x.xml', 'zugferd-invoice.xml', 'ZUGFeRD-invoice.xml', 'xrechnung.xml'];

    private const TYPES_XML = ['/text/xml', '/application/xml'];

    private const ACTIONS_REFUSEES = [
        '/JavaScript' => ContenuActif::JavaScript,
        '/Launch' => ContenuActif::LancementDeProgramme,
        '/SubmitForm' => ContenuActif::EnvoiDeFormulaire,
        '/ImportData' => ContenuActif::EnvoiDeFormulaire,
        '/GoToR' => ContenuActif::LienVersUnAutreFichier,
        '/GoToE' => ContenuActif::LienVersUnAutreFichier,
        '/RichMediaExecute' => ContenuActif::ContenuMultimedia,
        '/Rendition' => ContenuActif::ContenuMultimedia,
        '/Movie' => ContenuActif::ContenuMultimedia,
        '/Sound' => ContenuActif::ContenuMultimedia,
    ];

    private const ANNOTATIONS_REFUSEES = [
        '/FileAttachment' => ContenuActif::FichierJoint,
        '/RichMedia' => ContenuActif::ContenuMultimedia,
        '/Movie' => ContenuActif::ContenuMultimedia,
        '/Sound' => ContenuActif::ContenuMultimedia,
        '/Screen' => ContenuActif::ContenuMultimedia,
    ];

    private const CLES_REFUSEES = [
        '/JS' => ContenuActif::JavaScript,
        '/JavaScript' => ContenuActif::JavaScript,
        '/XFA' => ContenuActif::FormulaireDynamique,
        '/RichMediaContent' => ContenuActif::ContenuMultimedia,
        '/RichMediaSettings' => ContenuActif::ContenuMultimedia,
        '/RF' => ContenuActif::FichierJoint,
    ];

    /**
     * @throws FichierRefuseException quand le PDF est refusé
     */
    public function analyser(PieceDeposee $piece): void
    {
        if (! self::finitApresSonDernierEof($piece->contenu)) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        $fins = self::finsDesRevisions($piece->contenu);

        if (count($fins) > self::REVISIONS_AU_PLUS) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        $this->avecUnFichier($piece->contenu, function (string $fichier) use ($piece, $fins): void {
            if ($this->qpdf($piece, ['--requires-password', $fichier])[0] === 0) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfProtege);
            }

            if ($this->qpdf($piece, ['--check', $fichier])[0] !== 0) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
            }

            $contenus = $this->contenusActifs($piece, $fichier);

            foreach (array_slice($fins, 0, -1) as $fin) {
                $contenus = [...$contenus, ...$this->avecUnFichier(substr($piece->contenu, 0, $fin), fn (string $revision): array => $this->contenusActifs($piece, $revision))];
            }

            $contenus = array_values(array_filter(ContenuActif::cases(), fn (ContenuActif $contenu): bool => in_array($contenu, $contenus, true)));

            if ($contenus !== []) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfActif, $contenus);
            }
        });
    }

    /**
     * Après son dernier « %%EOF », un PDF ne contient que des blancs.
     */
    private static function finitApresSonDernierEof(string $contenu): bool
    {
        $fin = strrpos($contenu, '%%EOF');

        return $fin !== false && trim(substr($contenu, $fin + 5), " \t\r\n\f\0") === '';
    }

    /**
     * La fin de chaque version du fichier, juste après le « %%EOF » qui
     * suit son « startxref » : la première partie d'un PDF linéarisé, qui
     * annonce « startxref 0 », n'en est pas une.
     *
     * @return list<int>
     */
    private static function finsDesRevisions(string $contenu): array
    {
        preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $contenu, $revisions, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        return array_values(array_map(
            fn (array $revision): int => $revision[0][1] + strlen($revision[0][0]),
            array_filter($revisions, fn (array $revision): bool => (int) $revision[1][0] > 0),
        ));
    }

    /**
     * Le code actif que contient cette version du fichier : chaque objet,
     * et le dictionnaire de fin du fichier, parcourus en entier.
     *
     * @return list<ContenuActif>
     *
     * @throws FichierRefuseException
     */
    private function contenusActifs(PieceDeposee $piece, string $fichier): array
    {
        [$code, $sortie] = $this->qpdf($piece, ['--json=2', '--json-key=qpdf', '--json-stream-data=none', $fichier, '-']);

        if ($code !== 0 && $code !== 3) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        try {
            $description = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        $objets = [];
        $fluxJoints = [];

        foreach (is_array($description) && is_array($description['qpdf'][1] ?? null) ? $description['qpdf'][1] : [] as $cle => $objet) {
            $reference = $cle === 'trailer' ? 'trailer' : substr((string) $cle, 4);
            $flux = is_array($objet) && is_array($objet['stream']['dict'] ?? null);
            $objets[$reference] = is_array($objet) ? ($flux ? $objet['stream']['dict'] : ($objet['value'] ?? null)) : null;

            if ($flux && ($objet['stream']['dict']['/Type'] ?? null) === '/EmbeddedFile') {
                $fluxJoints[$reference] = true;
            }
        }

        $trouves = [];
        $specifications = [];

        foreach ($objets as $valeur) {
            $this->parcourir($valeur, $objets, $trouves, $specifications);
        }

        if (! isset($trouves[ContenuActif::FichierJoint->value]) && ! $this->joignentAuPlusUneFacturX($piece, $fichier, $objets, $fluxJoints, $specifications)) {
            $trouves[ContenuActif::FichierJoint->value] = true;
        }

        return array_map(fn (string $contenu): ContenuActif => ContenuActif::from($contenu), array_keys($trouves));
    }

    /**
     * Parcourt une valeur, et note le code actif qu'elle contient et les
     * spécifications de fichier qui portent un fichier joint (/EF).
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $trouves
     * @param  list<array<array-key, mixed>>  $specifications
     */
    private function parcourir(mixed $valeur, array $objets, array &$trouves, array &$specifications): void
    {
        if (! is_array($valeur)) {
            return;
        }

        if (! array_is_list($valeur)) {
            foreach (self::CLES_REFUSEES as $cle => $contenu) {
                if (array_key_exists($cle, $valeur)) {
                    $trouves[$contenu->value] = true;
                }
            }

            $action = self::ACTIONS_REFUSEES[$valeur['/S'] ?? ''] ?? null;
            $annotation = self::ANNOTATIONS_REFUSEES[$valeur['/Subtype'] ?? ''] ?? null;

            foreach (array_filter([$action, $annotation]) as $contenu) {
                $trouves[$contenu->value] = true;
            }

            if (array_key_exists('/OpenAction', $valeur) && ! self::estUneSimpleDestination($valeur['/OpenAction'], $objets)) {
                $trouves[ContenuActif::ActionAutomatique->value] = true;
            }

            if (array_key_exists('/AA', $valeur) && ! self::sontDesSimplesDestinations($valeur['/AA'], $objets)) {
                $trouves[ContenuActif::ActionAutomatique->value] = true;
            }

            if (array_key_exists('/EF', $valeur)) {
                $specifications[] = $valeur;
            }
        }

        foreach ($valeur as $element) {
            $this->parcourir($element, $objets, $trouves, $specifications);
        }
    }

    /**
     * L'action d'ouverture du document (/OpenAction) est-elle une simple
     * destination d'affichage ? Une destination (une page, un nom), ou une
     * action /GoTo dont chaque suite (/Next) est aussi une /GoTo.
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $vus
     */
    private static function estUneSimpleDestination(mixed $valeur, array $objets, array $vus = []): bool
    {
        [$valeur, $vus] = self::resoudre($valeur, $objets, $vus);

        return match (true) {
            $valeur === null, is_string($valeur), is_array($valeur) && array_is_list($valeur) => true,
            is_array($valeur) => self::estUnAller($valeur, $objets, $vus),
            default => false,
        };
    }

    /**
     * Les actions additionnelles (/AA) ne sont-elles que des /GoTo ?
     *
     * @param  array<string, mixed>  $objets
     */
    private static function sontDesSimplesDestinations(mixed $valeur, array $objets): bool
    {
        [$valeur, $vus] = self::resoudre($valeur, $objets, []);

        if (! is_array($valeur) || array_is_list($valeur)) {
            return $valeur === [];
        }

        foreach ($valeur as $action) {
            [$action, $vusDeLAction] = self::resoudre($action, $objets, $vus);

            if (! is_array($action) || array_is_list($action) || ! self::estUnAller($action, $objets, $vusDeLAction)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cette action est-elle une /GoTo, suivie seulement d'autres /GoTo ?
     *
     * @param  array<array-key, mixed>  $action
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $vus
     */
    private static function estUnAller(array $action, array $objets, array $vus): bool
    {
        if (($action['/S'] ?? null) !== '/GoTo') {
            return false;
        }

        [$suites, $vus] = self::resoudre($action['/Next'] ?? [], $objets, $vus);
        $suites = is_array($suites) && ! array_is_list($suites) ? [$suites] : $suites;

        if (! is_array($suites)) {
            return false;
        }

        foreach ($suites as $suite) {
            [$suite, $vusDeLaSuite] = self::resoudre($suite, $objets, $vus);

            if (! is_array($suite) || array_is_list($suite) || ! self::estUnAller($suite, $objets, $vusDeLaSuite)) {
                return false;
            }
        }

        return true;
    }

    /**
     * La valeur que désigne une référence indirecte (« 12 0 R »), suivie
     * de proche en proche ; une référence déjà suivie n'en désigne plus
     * aucune (une boucle).
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $vus
     * @return array{0: mixed, 1: array<string, true>}
     */
    private static function resoudre(mixed $valeur, array $objets, array $vus): array
    {
        while (is_string($valeur) && preg_match('/^\d+ \d+ R$/', $valeur) === 1) {
            if (isset($vus[$valeur])) {
                return [false, $vus];
            }

            $vus[$valeur] = true;
            $valeur = $objets[$valeur] ?? null;
        }

        return [$valeur, $vus];
    }

    /**
     * Les fichiers joints de cette version se réduisent-ils à rien, ou à un
     * seul XML de Factur-X bien nommé, bien typé et bien formé ?
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $fluxJoints
     * @param  list<array<array-key, mixed>>  $specifications
     *
     * @throws FichierRefuseException
     */
    private function joignentAuPlusUneFacturX(PieceDeposee $piece, string $fichier, array $objets, array $fluxJoints, array $specifications): bool
    {
        foreach ($specifications as $specification) {
            if (! is_array($specification['/EF']) || array_is_list($specification['/EF'])) {
                return false;
            }

            foreach ($specification['/EF'] as $flux) {
                if (! is_string($flux) || ! array_key_exists($flux, $objets)) {
                    return false;
                }

                $fluxJoints[$flux] = true;
            }

            $noms = array_map(self::texte(...), array_values(array_filter([$specification['/UF'] ?? null, $specification['/F'] ?? null], fn (mixed $nom): bool => $nom !== null)));

            if ($noms === [] || array_diff($noms, self::NOMS_FACTUR_X) !== []) {
                return false;
            }
        }

        if ($fluxJoints === []) {
            return true;
        }

        $reference = (string) array_key_first($fluxJoints);
        $dictionnaire = $objets[$reference] ?? null;

        if (count($fluxJoints) !== 1 || $specifications === [] || ! is_array($dictionnaire) || ! in_array($dictionnaire['/Subtype'] ?? null, self::TYPES_XML, true)) {
            return false;
        }

        [$code, $xml] = $this->qpdf($piece, ['--show-object='.str_replace(' ', ',', substr($reference, 0, -2)), '--filtered-stream-data', $fichier], self::XML_MAXIMAL);

        return ($code === 0 || $code === 3) && self::estUnXmlSur($xml);
    }

    /**
     * Le texte d'une chaîne de la description de qpdf : « u:… » en UTF-8,
     * « b:… » en hexadécimal.
     */
    private static function texte(mixed $chaine): string
    {
        if (! is_string($chaine)) {
            return '';
        }

        return match (substr($chaine, 0, 2)) {
            'u:' => substr($chaine, 2),
            'b:' => (string) hex2bin(substr($chaine, 2)),
            default => '',
        };
    }

    /**
     * Le XML est-il bien formé, sans DOCTYPE ni entité ? Lu par XMLReader,
     * sans accès au réseau (LIBXML_NONET), sans substitution d'entités ni
     * chargement de DTD, qui s'arrête à la première déclaration de type de
     * document, avant toute entité.
     */
    private static function estUnXmlSur(string $xml): bool
    {
        if ($xml === '' || strlen($xml) > self::XML_MAXIMAL || preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            return false;
        }

        $erreursInternes = libxml_use_internal_errors(true);

        try {
            $lecteur = new XMLReader;

            if (! $lecteur->XML($xml, null, LIBXML_NONET)) {
                return false;
            }

            $racine = false;

            while ($lecteur->read()) {
                if ($lecteur->nodeType === XMLReader::DOC_TYPE) {
                    return false;
                }

                $racine = $racine || $lecteur->nodeType === XMLReader::ELEMENT;
            }

            return $racine && libxml_get_errors() === [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($erreursInternes);
        }
    }

    /**
     * Écrit ce contenu dans un fichier temporaire, lisible par PILIQI
     * seul, le temps de cette opération.
     *
     * @template TResultat
     *
     * @param  callable(string): TResultat  $operation
     * @return TResultat
     */
    private function avecUnFichier(string $contenu, callable $operation): mixed
    {
        $fichier = tempnam(sys_get_temp_dir(), 'piliqi-pdf-');

        if ($fichier === false) {
            throw new RuntimeException('Le fichier temporaire de l\'analyse d\'un PDF ne se crée pas.');
        }

        try {
            chmod($fichier, 0600);
            file_put_contents($fichier, $contenu);

            return $operation($fichier);
        } finally {
            @unlink($fichier);
        }
    }

    /**
     * Lance qpdf avec ces arguments, et rend son code de sortie et sa
     * sortie standard, d'au plus cette longueur.
     *
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string}
     *
     * @throws FichierRefuseException
     */
    private function qpdf(PieceDeposee $piece, array $arguments, int $longueurMaximale = self::SORTIE_MAXIMALE): array
    {
        $processus = new Process([Reglage::texte('piliqi.qpdf'), ...$arguments]);
        $processus->setTimeout(self::DELAI_EN_SECONDES);
        $sortie = '';

        try {
            $processus->start();

            foreach ($processus->getIterator(Process::ITER_SKIP_ERR) as $morceau) {
                $sortie .= $morceau;

                if (strlen($sortie) > $longueurMaximale) {
                    $processus->stop(0);

                    throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
                }
            }

            $code = $processus->wait();
        } catch (ProcessTimedOutException) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        } catch (ExceptionInterface $erreur) {
            report($erreur);

            throw FichierRefuseException::pour($piece, RaisonDuRefus::AnalyseIndisponible);
        }

        if (! in_array($code, [0, 2, 3], true)) {
            report(new RuntimeException("qpdf s'est arrêté avec le code {$code} : ".trim($processus->getErrorOutput())));

            throw FichierRefuseException::pour($piece, RaisonDuRefus::AnalyseIndisponible);
        }

        return [$code, $sortie];
    }
}
