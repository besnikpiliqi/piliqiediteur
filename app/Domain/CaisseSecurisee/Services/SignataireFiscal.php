<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
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
 *   publique au format DER SubjectPublicKeyInfo, qui est canonique,
 *   contrairement au PEM ;
 * - seules les clés publiques épinglées dans le dépôt font foi : une
 *   signature ne se vérifie qu'avec elles, et la clé privée ne signe que
 *   si sa clé publique en fait partie.
 *
 * Fermé par défaut : une clé absente, illisible ou non épinglée lève
 * ModuleFiscalNonConfigureException au lieu de signer.
 */
class SignataireFiscal
{
    public const ALGORITHME = 'ES256';

    public const COURBE = 'prime256v1';

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
     * publique au format PEM, et le cle_id de la clé publique.
     *
     * @return array{cle_id: string, cle_privee: string, cle_publique: string}
     */
    public static function genererUnePaire(): array
    {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => self::COURBE]);

        if ($cle === false || ! openssl_pkey_export($cle, $clePrivee)) {
            throw new RuntimeException("OpenSSL n'a pas pu générer de clé ECDSA P-256.");
        }

        return [
            'cle_id' => self::empreinte($cle),
            'cle_privee' => $clePrivee,
            'cle_publique' => openssl_pkey_get_details($cle)['key'],
        ];
    }

    /**
     * La signature ES256 du contenu, en base64url.
     *
     * @throws ModuleFiscalNonConfigureException
     */
    public function signer(string $contenu): string
    {
        if (! openssl_sign($contenu, $signature, $this->clePrivee(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('La signature OpenSSL du contenu fiscal a échoué.');
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
     * Les clés publiques épinglées au format PEM, par cle_id.
     *
     * @return array<string, string>
     *
     * @throws ModuleFiscalNonConfigureException une clé épinglée est invalide
     */
    public function clesEpinglees(): array
    {
        return array_map(
            static fn (OpenSSLAsymmetricKey $cle): string => openssl_pkey_get_details($cle)['key'],
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
        $phraseSecrete = (string) $this->config->get('caisse_securisee.phrase_secrete');
        $cle = $pem === false ? false : openssl_pkey_get_private($pem, $phraseSecrete === '' ? null : $phraseSecrete);

        if ($cle === false) {
            throw ModuleFiscalNonConfigureException::clePriveeIllisible();
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

            $cle = openssl_pkey_get_public((string) $pem);

            if ($cle === false || ! self::estUneCleP256($cle)) {
                throw ModuleFiscalNonConfigureException::cleEpingleeIllisible((string) $fichier);
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
     * Le cle_id d'une clé : SHA-256 hexadécimal du DER SubjectPublicKeyInfo
     * de sa clé publique (le corps base64 du PEM « PUBLIC KEY »).
     */
    private static function empreinte(OpenSSLAsymmetricKey $cle): string
    {
        $clePubliquePem = openssl_pkey_get_details($cle)['key'];

        return hash('sha256', base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $clePubliquePem), true));
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
