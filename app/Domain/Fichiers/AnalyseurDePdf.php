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
 * nettoyé), par qpdf (licence Apache 2.0, version 11 au moins), un
 * programme reconnu du système (voir config/piliqi.php), qui lit chaque
 * objet, décompresse les flux et les flux d'objets, déchiffre un PDF
 * protégé sans mot de passe d'ouverture et rend chaque nom décodé
 * (« /J#61vaScript » se lit « /JavaScript »).
 *
 * Dans cet ordre :
 * - le fichier se découpe en versions, chacune close par son
 *   « startxref … %%EOF » (la première partie d'un PDF linéarisé, qui
 *   annonce « startxref 0 », n'en est pas une) ; au plus REVISIONS_AU_PLUS
 *   versions, et, après la dernière, que des blancs et des lignes de
 *   commentaire : un contenu ajouté après la fin (un programme, une
 *   archive) n'est pas du PDF ;
 * - un PDF qui demande un mot de passe pour s'ouvrir est refusé ;
 * - qpdf --check lit tout le fichier et décode chaque flux, sans erreur ni
 *   avertissement : un PDF abîmé, incomplet, ou dont qpdf doit
 *   reconstruire la table des objets (qu'une visionneuse lirait peut-être
 *   autrement) est refusé ;
 * - chaque version se lit pour elle-même (le fichier jusqu'à sa fin), sans
 *   erreur ni avertissement : du code ajouté par une mise à jour
 *   incrémentale, ou laissé dans une version précédente, se voit ;
 * - chaque mise à jour incrémentale n'est faite que de PDF : la table
 *   qu'annonce son « startxref » se trouve entre la fin de la version
 *   précédente et son « startxref », et tout ce qui les sépare se compose
 *   des objets que liste sa table, à leur position, chacun clos par
 *   « endobj » (la longueur d'un flux lue dans sa description), de sa
 *   table et de son dictionnaire de fin, séparés par des blancs et des
 *   lignes de commentaire en caractères imprimables ; un contenu glissé
 *   derrière un faux « startxref » (un programme, une archive) est
 *   refusé ;
 * - dans chaque objet, sont refusés : JavaScript (/JavaScript, /JS, et
 *   celui d'un contenu 3D, /OnInstantiate), lancement de programme
 *   (/Launch), envoi de formulaire ou import de données (/SubmitForm,
 *   /ImportData), contenus multimédias (/RichMedia, actions /Rendition,
 *   /Movie, /Sound, annotations de film, de son, d'écran ou 3D) et
 *   formulaires dynamiques (/XFA), liens vers d'autres fichiers (/GoToR,
 *   /GoToE), action automatique (/OpenAction, /AA) autre qu'une simple
 *   destination d'affichage (une destination, ou une action /GoTo suivie
 *   seulement d'autres /GoTo), fichier joint (/EmbeddedFile,
 *   /FileAttachment, /RF) autre qu'un seul XML de Factur-X ; le type d'une
 *   action (/S), d'une annotation (/Subtype) ou d'un flux (/Type) se lit
 *   comme une visionneuse le lit, au bout de ses références indirectes
 *   (« /S 6 0 R ») ;
 * - le XML d'une Factur-X : un seul fichier joint, nommé factur-x.xml,
 *   zugferd-invoice.xml, ZUGFeRD-invoice.xml ou xrechnung.xml, de type
 *   text/xml ou application/xml, de XML_MAXIMAL octets au plus une fois
 *   décompressé, bien formé, sans DOCTYPE ni entité, lu par un analyseur
 *   sans accès au réseau ni substitution d'entités ; il n'est pas lu
 *   au-delà (son import viendra avec SUPER PDP, étape 2).
 *
 * Un PDF que qpdf ne lit pas en DELAI_EN_SECONDES secondes, ou dont la
 * description dépasse SORTIE_MAXIMALE octets (des centaines de milliers
 * d'objets), est refusé comme illisible ; une description admise se
 * décode après avoir prévu la mémoire qu'elle prend (voir MemoireDePhp) :
 * jamais une erreur fatale faute de mémoire. Un qpdf absent ou en échec
 * refuse le fichier sans le juger (« réessayez dans quelques minutes »),
 * et l'erreur reste au journal de l'application : un PDF n'est jamais
 * accepté sans analyse.
 */
final class AnalyseurDePdf
{
    public const DELAI_EN_SECONDES = 20;

    public const REVISIONS_AU_PLUS = 32;

    public const SORTIE_MAXIMALE = 16 * 1024 * 1024;

    public const XML_MAXIMAL = 5 * 1024 * 1024;

    public const NOMS_FACTUR_X = ['factur-x.xml', 'zugferd-invoice.xml', 'ZUGFeRD-invoice.xml', 'xrechnung.xml'];

    /**
     * La première version de qpdf qui donne la description JSON version 2
     * (--json=2, --json-stream-data) que cette analyse lit ; Debian 11 et
     * Ubuntu 22.04 n'ont que qpdf 10.
     */
    public const VERSION_MINIMALE_DE_QPDF = 11;

    /**
     * La mémoire que prend une description décodée, par octet de sa
     * forme écrite (mesurée : un peu moins de cinq), sa forme écrite
     * comprise.
     */
    private const OCTETS_DECODES_PAR_OCTET = 6;

    /**
     * Les blancs d'un PDF.
     */
    private const BLANCS = "\x00\t\n\x0C\r ";

    /**
     * Les délimiteurs d'un PDF, qui terminent un nom ou un mot.
     */
    private const DELIMITEURS = '()<>[]{}/%';

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
        '/3D' => ContenuActif::ContenuMultimedia,
    ];

    private const CLES_REFUSEES = [
        '/JS' => ContenuActif::JavaScript,
        '/JavaScript' => ContenuActif::JavaScript,
        '/OnInstantiate' => ContenuActif::JavaScript,
        '/XFA' => ContenuActif::FormulaireDynamique,
        '/RichMediaContent' => ContenuActif::ContenuMultimedia,
        '/RichMediaSettings' => ContenuActif::ContenuMultimedia,
        '/3DD' => ContenuActif::ContenuMultimedia,
        '/RF' => ContenuActif::FichierJoint,
    ];

    /**
     * La version que dit la première ligne de « qpdf --version »
     * (« qpdf version 11.9.0 ») est-elle VERSION_MINIMALE_DE_QPDF ou plus ?
     */
    public static function versionAdmise(string $version): bool
    {
        return preg_match('/^qpdf version (\d+)\.\d+/', self::premiereLigne($version), $lue) === 1 && (int) $lue[1] >= self::VERSION_MINIMALE_DE_QPDF;
    }

    /**
     * La première ligne de ce qu'écrit un programme, sans espaces autour
     * ni fin de ligne, quelle qu'elle soit (« \n », ou « \r\n » sous
     * Windows, dont le retour chariot, imprimé dans une cellule du
     * diagnostic, en effacerait la ligne) : « qpdf version 12.2.0 ».
     */
    public static function premiereLigne(string $sortie): string
    {
        return trim(preg_split('/\R/', trim($sortie), 2)[0] ?? '');
    }

    /**
     * @throws FichierRefuseException quand le PDF est refusé
     */
    public function analyser(PieceDeposee $piece): void
    {
        $revisions = self::revisions($piece->contenu);

        if ($revisions === []
            || count($revisions) > self::REVISIONS_AU_PLUS
            || ! self::nEstQueDuBlanc(substr($piece->contenu, $revisions[array_key_last($revisions)]['fin']))) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        $this->avecUnFichier($piece->contenu, function (string $fichier) use ($piece, $revisions): void {
            if ($this->qpdf($piece, ['--requires-password', $fichier])[0] === 0) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfProtege);
            }

            if ($this->qpdf($piece, ['--check', $fichier])[0] !== 0) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
            }

            $contenus = [];
            $derniere = array_key_last($revisions);

            foreach ($revisions as $rang => $revision) {
                $debut = $rang === 0 ? null : $revisions[$rang - 1]['fin'];
                $lire = fn (string $version): array => $this->lireUneVersion($piece, $version, $debut, $revision);
                $contenus = [...$contenus, ...($rang === $derniere ? $lire($fichier) : $this->avecUnFichier(substr($piece->contenu, 0, $revision['fin']), $lire))];
            }

            $contenus = array_values(array_filter(ContenuActif::cases(), fn (ContenuActif $contenu): bool => in_array($contenu, $contenus, true)));

            if ($contenus !== []) {
                throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfActif, $contenus);
            }
        });
    }

    /**
     * Chaque version du fichier, dans l'ordre : la position de son
     * « startxref », celle de la table des objets qu'il annonce, et sa
     * fin, juste après le « %%EOF » qui le suit. La première partie d'un
     * PDF linéarisé, qui annonce « startxref 0 », n'en est pas une.
     *
     * @return list<array{startxref: int, table: int, fin: int}>
     */
    private static function revisions(string $contenu): array
    {
        preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $contenu, $trouvees, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        return array_values(array_map(
            fn (array $revision): array => ['startxref' => $revision[0][1], 'table' => (int) $revision[1][0], 'fin' => $revision[0][1] + strlen($revision[0][0])],
            array_filter($trouvees, fn (array $revision): bool => (int) $revision[1][0] > 0),
        ));
    }

    /**
     * Le code actif que contient cette version du fichier, une mise à jour
     * incrémentale (qui commence à $debut) contrôlée d'abord.
     *
     * @param  array{startxref: int, table: int, fin: int}  $revision
     * @return list<ContenuActif>
     *
     * @throws FichierRefuseException
     */
    private function lireUneVersion(PieceDeposee $piece, string $version, ?int $debut, array $revision): array
    {
        ['objets' => $objets, 'flux' => $flux] = $this->decrire($piece, $version);

        if ($debut !== null && ! $this->estUneMiseAJour($piece, $version, $objets, $flux, $debut, $revision)) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        return $this->contenusActifs($piece, $version, $objets, $flux);
    }

    /**
     * Chaque objet de cette version du fichier et le dictionnaire de fin
     * (« trailer »), tels que qpdf les décrit, et les objets qui sont des
     * flux (leur dictionnaire décrit) ; une version qui ne se lit pas sans
     * erreur ni avertissement est refusée.
     *
     * @return array{objets: array<string, mixed>, flux: array<string, true>}
     *
     * @throws FichierRefuseException
     */
    private function decrire(PieceDeposee $piece, string $fichier): array
    {
        [$code, $sortie] = $this->qpdf($piece, ['--json=2', '--json-key=qpdf', '--json-stream-data=none', $fichier, '-']);

        if ($code !== 0) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        MemoireDePhp::prevoir(strlen($sortie) * self::OCTETS_DECODES_PAR_OCTET);

        try {
            $description = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::PdfIllisible);
        }

        unset($sortie);
        $objets = [];
        $flux = [];

        $qpdf = is_array($description) ? ($description['qpdf'] ?? null) : null;

        foreach (is_array($qpdf) && is_array($qpdf[1] ?? null) ? $qpdf[1] : [] as $cle => $objet) {
            $reference = $cle === 'trailer' ? 'trailer' : substr((string) $cle, 4);
            $leFlux = is_array($objet) ? ($objet['stream'] ?? null) : null;
            $dictionnaireDuFlux = is_array($leFlux) ? ($leFlux['dict'] ?? null) : null;
            $estUnFlux = is_array($dictionnaireDuFlux);
            $objets[$reference] = is_array($objet) ? ($estUnFlux ? $dictionnaireDuFlux : ($objet['value'] ?? null)) : null;

            if ($estUnFlux) {
                $flux[$reference] = true;
            }
        }

        return ['objets' => $objets, 'flux' => $flux];
    }

    /**
     * Le code actif que contient cette version du fichier : chaque objet,
     * et le dictionnaire de fin du fichier, parcourus en entier.
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $flux
     * @return list<ContenuActif>
     *
     * @throws FichierRefuseException
     */
    private function contenusActifs(PieceDeposee $piece, string $fichier, array $objets, array $flux): array
    {
        $trouves = [];
        $specifications = [];

        foreach ($objets as $valeur) {
            $this->parcourir($valeur, $objets, $trouves, $specifications);
        }

        $fluxJoints = array_filter($flux, fn (bool $estUnFlux, string $reference): bool => is_array($objets[$reference]) && self::nom($objets[$reference]['/Type'] ?? null, $objets) === '/EmbeddedFile', ARRAY_FILTER_USE_BOTH);

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

            $action = self::ACTIONS_REFUSEES[self::nom($valeur['/S'] ?? null, $objets)] ?? null;
            $annotation = self::ANNOTATIONS_REFUSEES[self::nom($valeur['/Subtype'] ?? null, $objets)] ?? null;

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
     * Le nom que porte cette valeur, écrit directement ou au bout de ses
     * références indirectes (« /S 6 0 R », l'objet 6 étant « /Launch ») :
     * une visionneuse les suit ; '' pour une valeur qui n'est pas un nom.
     *
     * @param  array<string, mixed>  $objets
     */
    private static function nom(mixed $valeur, array $objets): string
    {
        [$valeur] = self::resoudre($valeur, $objets, []);

        return is_string($valeur) && str_starts_with($valeur, '/') ? $valeur : '';
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
        if (self::nom($action['/S'] ?? null, $objets) !== '/GoTo') {
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

        if (count($fluxJoints) !== 1 || $specifications === [] || ! is_array($dictionnaire) || ! in_array(self::nom($dictionnaire['/Subtype'] ?? null, $objets), self::TYPES_XML, true)) {
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
     * Cette mise à jour incrémentale, de $debut (la fin de la version
     * précédente) au « startxref » de sa version, n'est-elle faite que de
     * PDF ? Sa table se trouve entre ces deux positions ; de l'une à
     * l'autre ne se suivent, séparés par des blancs et des lignes de
     * commentaire, que les objets que liste sa table (selon qpdf
     * --show-xref de cette version), à leur position, et sa table des
     * objets avec son dictionnaire de fin, ou son flux de table des objets.
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $flux
     * @param  array{startxref: int, table: int, fin: int}  $revision
     *
     * @throws FichierRefuseException
     */
    private function estUneMiseAJour(PieceDeposee $piece, string $version, array $objets, array $flux, int $debut, array $revision): bool
    {
        ['startxref' => $startxref, 'table' => $table] = $revision;

        if ($table < $debut || $table >= $startxref) {
            return false;
        }

        [$code, $xref] = $this->qpdf($piece, ['--show-xref', $version]);

        if ($code !== 0) {
            return false;
        }

        $departs = [$table => true];
        $tableDeSecours = is_array($objets['trailer'] ?? null) ? ($objets['trailer']['/XRefStm'] ?? null) : null;

        if (is_int($tableDeSecours) && $tableDeSecours >= $debut && $tableDeSecours < $startxref) {
            $departs[$tableDeSecours] = true;
        }

        foreach (preg_split('/\R/', $xref) ?: [] as $ligne) {
            if (sscanf(trim($ligne), '%d/%d: uncompressed; offset = %d', $numero, $generation, $decalage) === 3 && is_int($decalage) && $decalage >= $debut && $decalage < $startxref) {
                $departs[$decalage] = true;
            }
        }

        ksort($departs);
        $position = $debut;

        foreach (array_keys($departs) as $depart) {
            if ($depart < $position || ! self::nEstQueDuBlanc(substr($piece->contenu, $position, $depart - $position))) {
                return false;
            }

            $position = $depart === $table && substr_compare($piece->contenu, 'xref', $depart, 4) === 0
                ? self::finDeLaTable($piece->contenu, $depart)
                : self::finDeLObjet($piece->contenu, $depart, $objets, $flux);

            if ($position === null || $position > $startxref) {
                return false;
            }
        }

        return self::nEstQueDuBlanc(substr($piece->contenu, $position, $startxref - $position));
    }

    /**
     * La fin de la table des objets classique qui commence à cette
     * position (« xref », ses sous-sections et leurs entrées de vingt
     * octets), suivie de son dictionnaire de fin (« trailer << … >> ») ;
     * null pour autre chose.
     */
    private static function finDeLaTable(string $contenu, int $position): ?int
    {
        $position += 4;

        while (true) {
            $position = self::apresLesBlancs($contenu, $position);

            if ($position === null) {
                return null;
            }

            if (substr_compare($contenu, 'trailer', $position, 7) === 0) {
                break;
            }

            if (preg_match('/\G(\d+)[ \t]+(\d+)/', $contenu, $sousSection, 0, $position) !== 1) {
                return null;
            }

            $position += strlen($sousSection[0]);

            for ($entree = (int) $sousSection[2]; $entree > 0; $entree--) {
                $position += strspn($contenu, self::BLANCS, $position);

                if (preg_match('/\G\d{10}[ \t]+\d{5}[ \t]+[nf]/', $contenu, $ligne, 0, $position) !== 1) {
                    return null;
                }

                $position += strlen($ligne[0]);
            }
        }

        $dictionnaire = self::lexeme($contenu, $position + 7);

        return $dictionnaire !== null && $dictionnaire[0] === '<<' ? self::finDuDictionnaire($contenu, $dictionnaire[1]) : null;
    }

    /**
     * La fin de l'objet qui commence à cette position (« 12 0 obj … endobj »,
     * un flux sauté de la longueur que donne sa description) ; null pour
     * autre chose.
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $flux
     */
    private static function finDeLObjet(string $contenu, int $position, array $objets, array $flux): ?int
    {
        if (preg_match('/\G(\d+)[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+obj/', $contenu, $tete, 0, $position) !== 1) {
            return null;
        }

        $reference = "{$tete[1]} {$tete[2]} R";
        $position += strlen($tete[0]);

        while (($lexeme = self::lexeme($contenu, $position)) !== null) {
            [$texte, $position] = $lexeme;

            if ($texte === 'endobj') {
                return $position;
            }

            if ($texte === 'stream') {
                return self::finDuFlux($contenu, $position, $reference, $objets, $flux);
            }
        }

        return null;
    }

    /**
     * La fin de l'objet dont le flux commence juste après ce mot
     * « stream » : sa fin de ligne, ses données de la longueur que donne sa
     * description (/Length), puis « endstream » et « endobj ».
     *
     * @param  array<string, mixed>  $objets
     * @param  array<string, true>  $flux
     */
    private static function finDuFlux(string $contenu, int $position, string $reference, array $objets, array $flux): ?int
    {
        $dictionnaire = $objets[$reference] ?? null;
        [$longueur] = is_array($dictionnaire) && isset($flux[$reference]) ? self::resoudre($dictionnaire['/Length'] ?? null, $objets, []) : [null];
        $finDeLigne = substr($contenu, $position, 2) === "\r\n" ? 2 : (substr($contenu, $position, 1) === "\n" ? 1 : 0);

        if (! is_int($longueur) || $longueur < 0 || $finDeLigne === 0) {
            return null;
        }

        $finDuFlux = self::lexeme($contenu, $position + $finDeLigne + $longueur);
        $finDeLObjet = $finDuFlux !== null && $finDuFlux[0] === 'endstream' ? self::lexeme($contenu, $finDuFlux[1]) : null;

        return $finDeLObjet !== null && $finDeLObjet[0] === 'endobj' ? $finDeLObjet[1] : null;
    }

    /**
     * La fin du dictionnaire ouvert juste avant cette position (« << »
     * déjà lu) : ses dictionnaires et tableaux imbriqués, ses chaînes et
     * ses commentaires sautés ; null s'il ne se ferme pas.
     */
    private static function finDuDictionnaire(string $contenu, int $position): ?int
    {
        $profondeur = 1;

        while ($profondeur > 0) {
            $lexeme = self::lexeme($contenu, $position);

            if ($lexeme === null) {
                return null;
            }

            [$texte, $position] = $lexeme;
            $profondeur += match ($texte) {
                '<<', '[' => 1,
                '>>', ']' => -1,
                default => 0,
            };
        }

        return $position;
    }

    /**
     * Le lexème qui suit, après les blancs et les commentaires, à partir de
     * cette position, et la position qui le suit : « << », « >> », « [ »,
     * « ] », une chaîne (« () »), une chaîne hexadécimale (« <> »), un nom,
     * un mot (nombre, « obj », « endobj », « stream »…) ; null à la fin du
     * contenu, devant un commentaire qui porte autre chose que des
     * caractères imprimables, ou devant une chaîne qui ne se ferme pas.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function lexeme(string $contenu, int $position): ?array
    {
        $position = self::apresLesBlancs($contenu, $position);

        if ($position === null || $position >= strlen($contenu)) {
            return null;
        }

        $deux = substr($contenu, $position, 2);
        $caractere = $contenu[$position];

        if ($deux === '<<' || $deux === '>>') {
            return [$deux, $position + 2];
        }

        if ($caractere === '(') {
            return self::apresUneChaine($contenu, $position);
        }

        if ($caractere === '<') {
            $fin = strpos($contenu, '>', $position);

            return $fin === false ? null : ['<>', $fin + 1];
        }

        if (str_contains(')>[]{}', $caractere)) {
            return [$caractere, $position + 1];
        }

        $debut = $caractere === '/' ? $position + 1 : $position;
        $fin = $debut + strcspn($contenu, self::BLANCS.self::DELIMITEURS, $debut);

        return [substr($contenu, $position, $fin - $position), $fin];
    }

    /**
     * La chaîne littérale qui commence à cette position (« ( »), ses
     * parenthèses imbriquées et ses caractères échappés compris.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function apresUneChaine(string $contenu, int $position): ?array
    {
        $longueur = strlen($contenu);
        $profondeur = 0;

        while ($position < $longueur) {
            $position += strcspn($contenu, '()\\', $position);

            if ($position >= $longueur) {
                return null;
            }

            if ($contenu[$position] === '\\') {
                $position += 2;

                continue;
            }

            $profondeur += $contenu[$position] === '(' ? 1 : -1;
            $position++;

            if ($profondeur === 0) {
                return ['()', $position];
            }
        }

        return null;
    }

    /**
     * La position qui suit les blancs et les commentaires à partir de
     * celle-ci ; null devant un commentaire qui porte autre chose que des
     * caractères imprimables (un programme ou une archive glissé derrière
     * « % »).
     */
    private static function apresLesBlancs(string $contenu, int $position): ?int
    {
        $longueur = strlen($contenu);

        while ($position < $longueur) {
            $position += strspn($contenu, self::BLANCS, $position);

            if ($position >= $longueur || $contenu[$position] !== '%') {
                return $position;
            }

            $finDuCommentaire = $position + 1 + strcspn($contenu, "\r\n", $position + 1);

            if (preg_match('/[^\t\x20-\xFF]/', substr($contenu, $position + 1, $finDuCommentaire - $position - 1)) === 1) {
                return null;
            }

            $position = $finDuCommentaire;
        }

        return $position;
    }

    /**
     * Ce morceau n'est-il fait que de blancs et de commentaires en
     * caractères imprimables ?
     */
    private static function nEstQueDuBlanc(string $morceau): bool
    {
        return self::apresLesBlancs($morceau, 0) === strlen($morceau);
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
