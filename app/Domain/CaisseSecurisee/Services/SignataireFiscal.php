<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Support\ErreursOpenSsl;
use Illuminate\Contracts\Config\Repository;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Signature électronique des données fiscales : ECDSA P-256 avec SHA-256
 * (ES256), par ext-openssl. La signature, au format DER, s'écrit en
 * base64url ; elle porte sur les octets exacts du contenu, que la
 * vérification relit tels quels, sans jamais les re-sérialiser.
 *
 * Clés (voir config/caisse_securisee.php) :
 * - la clé privée de l'éditeur vient de l'environnement du serveur ;
 * - une clé se désigne par son cle_id, le SHA-256 hexadécimal de sa clé
 *   publique dans son écriture de référence (voir spki()) : ni le PEM ni
 *   le DER que réécrit OpenSSL ne sont canoniques, une même clé pouvant
 *   s'écrire avec un point compressé ou les paramètres explicites de sa
 *   courbe ;
 * - seules les clés publiques épinglées dans le dépôt font foi : une
 *   signature ne se vérifie qu'avec elles, et la clé privée ne signe que
 *   si sa clé publique en fait partie.
 *
 * Fermé par défaut : une clé absente, illisible ou non épinglée lève
 * ModuleFiscalNonConfigureException au lieu de signer.
 *
 * Une génération, une lecture ou un export de clé, ou une signature,
 * qu'OpenSSL refuse lève une exception qui dit ses erreurs et, sous
 * Windows, la marche à suivre (voir ErreursOpenSsl), jamais une clé.
 */
class SignataireFiscal
{
    public const ALGORITHME = 'ES256';

    public const COURBE = 'prime256v1';

    /**
     * Le début du DER SubjectPublicKeyInfo d'une clé P-256 à point non
     * compressé : SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 },
     * BIT STRING }, que suivent les 65 octets du point, 04 || X || Y.
     */
    private const ENTETE_SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Les clés publiques épinglées, par cle_id.
     *
     * @var array<string, OpenSSLAsymmetricKey>|null
     */
    private ?array $trousseau = null;

    private ?OpenSSLAsymmetricKey $clePrivee = null;

    private ?string $cleId = null;

    public function __construct(private readonly Repository $config) {}

    /**
     * Une nouvelle paire de clés ECDSA P-256 : la clé privée et la clé
     * publique (écriture de référence) au format PEM, et le cle_id de la
     * clé publique.
     *
     * @return array{cle_id: string, cle_privee: string, cle_publique: string}
     *
     * @throws RuntimeException OpenSSL ne génère ou n'exporte pas la clé
     */
    public static function genererUnePaire(): array
    {
        ErreursOpenSsl::oublier();
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => self::COURBE]);

        if ($cle === false) {
            throw new RuntimeException('OpenSSL n\'a pas pu générer de clé ECDSA P-256. '.ErreursOpenSsl::detail());
        }

        if (! openssl_pkey_export($cle, $clePrivee)) {
            throw new RuntimeException('OpenSSL n\'a pas pu exporter la clé ECDSA P-256 générée. '.ErreursOpenSsl::detail());
        }

        return [
            'cle_id' => self::empreinte($cle),
            'cle_privee' => $clePrivee,
            'cle_publique' => self::pem(self::spki($cle)),
        ];
    }

    /**
     * La signature ES256 du contenu, en base64url.
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws RuntimeException OpenSSL refuse de signer
     */
    public function signer(string $contenu): string
    {
        $clePrivee = $this->clePrivee();
        ErreursOpenSsl::oublier();

        if (! openssl_sign($contenu, $signature, $clePrivee, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('La signature OpenSSL du contenu fiscal a échoué. '.ErreursOpenSsl::detail());
        }

        return self::base64url($signature);
    }

    /**
     * Le cle_id de la clé qui signe.
     *
     * @throws ModuleFiscalNonConfigureException
     */
    public function cleId(): string
    {
        $this->clePrivee();

        return $this->cleId;
    }

    /**
     * Vrai seulement si la signature (base64url) du contenu est valide pour
     * la clé épinglée $cleId : une clé inconnue ou une signature mal formée
     * ne vérifient rien.
     *
     * @throws ModuleFiscalNonConfigureException une clé épinglée est invalide
     */
    public function verifier(string $contenu, string $signature, string $cleId): bool
    {
        $clePublique = $this->trousseau()[$cleId] ?? null;
        $signatureDer = self::decoderBase64url($signature);

        return $clePublique !== null
            && $signatureDer !== null
            && openssl_verify($contenu, $signatureDer, $clePublique, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Les clés publiques épinglées par cle_id, chacune au format PEM dans
     * son écriture de référence, quelle que soit celle du fichier épinglé :
     * le SHA-256 du DER de ce PEM est toujours son cle_id.
     *
     * @return array<string, string>
     *
     * @throws ModuleFiscalNonConfigureException une clé épinglée est invalide
     */
    public function clesEpinglees(): array
    {
        return array_map(
            static fn (OpenSSLAsymmetricKey $cle): string => self::pem(self::spki($cle)),
            $this->trousseau(),
        );
    }

    /**
     * La clé privée de l'environnement, une fois vérifiée : ECDSA P-256,
     * et dont la clé publique est épinglée.
     *
     * @throws ModuleFiscalNonConfigureException
     */
    private function clePrivee(): OpenSSLAsymmetricKey
    {
        if ($this->clePrivee !== null) {
            return $this->clePrivee;
        }

        $valeur = trim((string) $this->config->get('caisse_securisee.cle_privee'));

        if ($valeur === '') {
            throw ModuleFiscalNonConfigureException::clePriveeAbsente();
        }

        $pem = base64_decode($valeur, true);

        if ($pem === false) {
            throw ModuleFiscalNonConfigureException::clePriveeIllisible('Sa valeur n\'est pas écrite en base64.');
        }

        $phraseSecrete = (string) $this->config->get('caisse_securisee.phrase_secrete');
        ErreursOpenSsl::oublier();
        $cle = openssl_pkey_get_private($pem, $phraseSecrete === '' ? null : $phraseSecrete);

        if ($cle === false) {
            throw ModuleFiscalNonConfigureException::clePriveeIllisible(ErreursOpenSsl::detail());
        }

        if (! self::estUneCleP256($cle)) {
            throw ModuleFiscalNonConfigureException::clePriveeNonPriseEnCharge();
        }

        $cleId = self::empreinte($cle);

        if (! array_key_exists($cleId, $this->trousseau())) {
            throw ModuleFiscalNonConfigureException::cleNonEpinglee($cleId);
        }

        $this->cleId = $cleId;

        return $this->clePrivee = $cle;
    }

    /**
     * @return array<string, OpenSSLAsymmetricKey>
     *
     * @throws ModuleFiscalNonConfigureException
     */
    private function trousseau(): array
    {
        if ($this->trousseau !== null) {
            return $this->trousseau;
        }

        $trousseau = [];

        foreach ((array) $this->config->get('caisse_securisee.cles_publiques', []) as $fichier => $pem) {
            if (str_contains((string) $pem, 'PRIVATE KEY')) {
                throw ModuleFiscalNonConfigureException::clePriveeEpinglee((string) $fichier);
            }

            ErreursOpenSsl::oublier();
            $cle = openssl_pkey_get_public((string) $pem);

            if ($cle === false) {
                throw ModuleFiscalNonConfigureException::cleEpingleeIllisible((string) $fichier, ErreursOpenSsl::detail());
            }

            if (! self::estUneCleP256($cle)) {
                throw ModuleFiscalNonConfigureException::cleEpingleeIllisible((string) $fichier, 'OpenSSL la lit, mais sa courbe ou son algorithme n\'est pas celui d\'une clé ECDSA P-256.');
            }

            $trousseau[self::empreinte($cle)] = $cle;
        }

        return $this->trousseau = $trousseau;
    }

    private static function estUneCleP256(OpenSSLAsymmetricKey $cle): bool
    {
        $details = openssl_pkey_get_details($cle);

        return $details !== false
            && $details['type'] === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? null) === self::COURBE;
    }

    /**
     * Le cle_id d'une clé P-256 : SHA-256 hexadécimal de l'écriture de
     * référence de sa clé publique.
     */
    private static function empreinte(OpenSSLAsymmetricKey $cle): string
    {
        return hash('sha256', self::spki($cle));
    }

    /**
     * L'écriture de référence de la clé publique d'une clé P-256 : DER
     * SubjectPublicKeyInfo, courbe nommée et point non compressé
     * 04 || X || Y, chaque coordonnée sur 32 octets. Elle se reconstruit
     * depuis les coordonnées, car OpenSSL réécrit une clé telle qu'il l'a
     * lue : un point compressé ou les paramètres explicites de la courbe
     * donneraient d'autres octets, donc un autre cle_id, pour la même clé.
     * OpenSSL rend aussi chaque coordonnée sans ses octets nuls de tête,
     * d'où le complément à 32 octets.
     *
     * @throws RuntimeException OpenSSL ne donne pas le détail de la clé
     */
    private static function spki(OpenSSLAsymmetricKey $cle): string
    {
        ErreursOpenSsl::oublier();
        $details = openssl_pkey_get_details($cle);
        $coordonnees = ($details !== false ? $details : throw new RuntimeException('OpenSSL ne donne pas le détail de la clé ECDSA P-256. '.ErreursOpenSsl::detail()))['ec'];

        return hex2bin(self::ENTETE_SPKI_P256)."\x04"
            .str_pad($coordonnees['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($coordonnees['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function base64url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    /**
     * Seule l'écriture base64url canonique (sans remplissage) d'une suite
     * d'octets est acceptée : une même signature n'a qu'une seule écriture.
     */
    private static function decoderBase64url(string $texte): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $texte) !== 1) {
            return null;
        }

        $octets = base64_decode(strtr($texte, '-_', '+/'), true);

        return $octets !== false && self::base64url($octets) === $texte ? $octets : null;
    }
}
