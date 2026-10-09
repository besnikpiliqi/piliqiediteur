<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Signature ES256 des données fiscales : seules les clés publiques
 * épinglées font foi, et une clé absente, illisible ou non épinglée ne
 * signe rien (fermé par défaut). Les tests signent avec la paire
 * éphémère que Tests\TestCase épingle.
 */
class SignataireFiscalTest extends TestCase
{
    private const CONTENU = '{"format":1,"type":"vente","donnees":{"total_ttc":12000,"client":"Zoé Lefèvre"}}';

    private function signataire(): SignataireFiscal
    {
        return app(SignataireFiscal::class);
    }

    private function clePubliqueDeTest(): string
    {
        return Arr::first(config('caisse_securisee.cles_publiques'));
    }

    /**
     * Le cle_id d'une clé publique PEM écrite par OpenSSL (courbe nommée,
     * point non compressé), calculé sans le signataire : le SHA-256 du DER
     * SubjectPublicKeyInfo, corps base64 du PEM.
     */
    private function cleIdDe(string $clePubliquePem): string
    {
        return hash('sha256', base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $clePubliquePem)));
    }

    /**
     * Une paire de clés étrangère aux tests, en PEM.
     *
     * @return array{privee: string, publique: string}
     */
    private function autrePaire(string $courbe = 'prime256v1', ?string $phraseSecrete = null): array
    {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $courbe]);
        openssl_pkey_export($cle, $privee, $phraseSecrete);

        return ['privee' => $privee, 'publique' => openssl_pkey_get_details($cle)['key']];
    }

    private function signatureDer(string $signature): string
    {
        return base64_decode(strtr($signature, '-_', '+/'));
    }

    private function fixture(string $fichier): string
    {
        return file_get_contents(base_path("tests/Fixtures/CaisseSecurisee/{$fichier}"));
    }

    private function pem(string $etiquette, string $der): string
    {
        return "-----BEGIN {$etiquette}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$etiquette}-----\n";
    }

    /**
     * Le point compressé d'une clé P-256 : 02 ou 03 selon la parité de Y,
     * puis X sur 32 octets.
     *
     * @param  array{x: string, y: string}  $coordonnees
     */
    private function pointCompresse(array $coordonnees): string
    {
        return (ord(substr($coordonnees['y'], -1)) % 2 === 0 ? "\x02" : "\x03").str_pad($coordonnees['x'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * La clé publique des tests telle que l'écrit
     * `openssl ec -pubout -conv_form compressed`.
     */
    private function clePubliqueDeTestCompressee(): string
    {
        $coordonnees = openssl_pkey_get_details(openssl_pkey_get_public($this->clePubliqueDeTest()))['ec'];

        return $this->pem('PUBLIC KEY', hex2bin('3039301306072a8648ce3d020106082a8648ce3d030107032200').$this->pointCompresse($coordonnees));
    }

    /**
     * La clé privée des tests telle que l'écrit
     * `openssl ec -conv_form compressed` : SEC1, courbe nommée, clé
     * publique au point compressé.
     */
    private function clePriveeDeTestCompressee(): string
    {
        $coordonnees = openssl_pkey_get_details(openssl_pkey_get_private(base64_decode(config('caisse_securisee.cle_privee'))))['ec'];

        return $this->pem('EC PRIVATE KEY', hex2bin('30570201010420')
            .str_pad($coordonnees['d'], 32, "\0", STR_PAD_LEFT)
            .hex2bin('a00a06082a8648ce3d030107a124032200')
            .$this->pointCompresse($coordonnees));
    }

    public function test_une_signature_se_verifie_avec_la_cle_epinglee_qui_l_a_produite(): void
    {
        $signataire = $this->signataire();

        $signature = $signataire->signer(self::CONTENU);

        $this->assertSame($this->cleIdDe($this->clePubliqueDeTest()), $signataire->cleId());
        $this->assertTrue($signataire->verifier(self::CONTENU, $signature, $signataire->cleId()));
    }

    /**
     * Un tiers vérifie la signature sans le logiciel, avec la seule clé
     * publique : DER en base64url, sans remplissage, ECDSA SHA-256.
     */
    public function test_la_signature_se_verifie_avec_openssl_et_la_seule_cle_publique(): void
    {
        $signature = $this->signataire()->signer(self::CONTENU);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $signature);
        $this->assertSame(1, openssl_verify(self::CONTENU, $this->signatureDer($signature), $this->clePubliqueDeTest(), OPENSSL_ALGO_SHA256));
    }

    public function test_un_octet_modifie_dans_le_contenu_ne_se_verifie_plus(): void
    {
        $signataire = $this->signataire();
        $signature = $signataire->signer(self::CONTENU);

        $contenuModifie = str_replace('12000', '12001', self::CONTENU);

        $this->assertFalse($signataire->verifier($contenuModifie, $signature, $signataire->cleId()));
    }

    public function test_une_signature_faite_avec_une_autre_cle_ne_se_verifie_pas(): void
    {
        $signataire = $this->signataire();
        openssl_sign(self::CONTENU, $signatureEtrangere, $this->autrePaire()['privee'], OPENSSL_ALGO_SHA256);

        $verifiee = $signataire->verifier(self::CONTENU, rtrim(strtr(base64_encode($signatureEtrangere), '+/', '-_'), '='), $signataire->cleId());

        $this->assertFalse($verifiee);
    }

    public function test_un_cle_id_inconnu_ne_verifie_rien(): void
    {
        $signataire = $this->signataire();
        $signature = $signataire->signer(self::CONTENU);

        $this->assertFalse($signataire->verifier(self::CONTENU, $signature, str_repeat('0', 64)));
    }

    /**
     * Une même signature n'a qu'une écriture : base64 classique, remplissage
     * ou texte qui n'est pas du base64url ne vérifient rien. La signature
     * ECDSA change à chaque appel : on en garde une dont le base64 classique
     * s'écrit autrement que le base64url, car sans « + », « / » ni
     * remplissage les deux écritures sont identiques.
     */
    public function test_une_signature_mal_ecrite_ne_verifie_rien(): void
    {
        $signataire = $this->signataire();
        do {
            $signature = $signataire->signer(self::CONTENU);
            $base64Classique = base64_encode($this->signatureDer($signature));
        } while ($base64Classique === $signature);
        $cleId = $signataire->cleId();

        $this->assertFalse($signataire->verifier(self::CONTENU, $signature.'=', $cleId));
        $this->assertFalse($signataire->verifier(self::CONTENU, $base64Classique, $cleId));
        $this->assertFalse($signataire->verifier(self::CONTENU, '', $cleId));
        $this->assertFalse($signataire->verifier(self::CONTENU, 'pas une signature', $cleId));
    }

    /**
     * Après une rotation, la nouvelle clé signe et l'ancienne, toujours
     * épinglée, vérifie encore ce qu'elle a signé.
     */
    public function test_une_ancienne_cle_epinglee_verifie_encore_ce_qu_elle_a_signe(): void
    {
        $signatureAncienne = $this->signataire()->signer(self::CONTENU);
        $cleIdAncienne = $this->signataire()->cleId();
        $nouvelle = $this->autrePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($nouvelle['privee']),
            'caisse_securisee.cles_publiques' => ['ancienne.pem' => $this->clePubliqueDeTest(), 'nouvelle.pem' => $nouvelle['publique']],
        ]);

        $signataire = $this->signataire();

        $this->assertSame($this->cleIdDe($nouvelle['publique']), $signataire->cleId());
        $this->assertTrue($signataire->verifier(self::CONTENU, $signatureAncienne, $cleIdAncienne));
        $this->assertTrue($signataire->verifier(self::CONTENU, $signataire->signer(self::CONTENU), $signataire->cleId()));
    }

    /**
     * OpenSSL réécrit une clé telle qu'il l'a lue : la clé publique
     * épinglée au point compressé reste la même clé, sous le même cle_id.
     */
    public function test_une_cle_epinglee_au_point_compresse_signe_sous_son_cle_id_de_reference(): void
    {
        $cleIdDeReference = $this->cleIdDe($this->clePubliqueDeTest());
        config(['caisse_securisee.cles_publiques' => ['compressee.pem' => $this->clePubliqueDeTestCompressee()]]);
        $signataire = $this->signataire();

        $signature = $signataire->signer(self::CONTENU);

        $this->assertSame($cleIdDeReference, $signataire->cleId());
        $this->assertTrue($signataire->verifier(self::CONTENU, $signature, $signataire->cleId()));
    }

    public function test_une_cle_privee_au_point_compresse_signe_sous_son_cle_id_de_reference(): void
    {
        config(['caisse_securisee.cle_privee' => base64_encode($this->clePriveeDeTestCompressee())]);
        $signataire = $this->signataire();

        $signature = $signataire->signer(self::CONTENU);

        $this->assertSame($this->cleIdDe($this->clePubliqueDeTest()), $signataire->cleId());
        $this->assertTrue($signataire->verifier(self::CONTENU, $signature, $signataire->cleId()));
    }

    /**
     * Les autres écritures de la clé de cle-non-compressee.pem, produites
     * par `openssl ec -pubout` avec -conv_form compressed ou
     * -param_enc explicit.
     *
     * @return array<string, array{string}>
     */
    public static function autresEcrituresDUneCle(): array
    {
        return [
            'point compressé' => ['cle-compressee.pem'],
            'paramètres explicites de la courbe' => ['cle-parametres-explicites.pem'],
        ];
    }

    /**
     * Épinglée deux fois sous deux écritures, une clé reste une seule clé,
     * listée dans son écriture de référence.
     */
    #[DataProvider('autresEcrituresDUneCle')]
    public function test_une_cle_epinglee_sous_deux_ecritures_n_a_qu_un_cle_id(string $autreEcriture): void
    {
        $reference = $this->fixture('cle-non-compressee.pem');
        config(['caisse_securisee.cles_publiques' => ['reference.pem' => $reference, 'autre.pem' => $this->fixture($autreEcriture)]]);

        $clesEpinglees = $this->signataire()->clesEpinglees();

        $this->assertSame([$this->cleIdDe($reference) => $reference], $clesEpinglees);
    }

    /**
     * Les deux coordonnées de la clé de cle-non-compressee.pem commencent
     * par un octet nul, qu'OpenSSL omet en les rendant.
     */
    public function test_une_cle_aux_coordonnees_commencant_par_un_octet_nul_garde_son_cle_id(): void
    {
        $cle = $this->fixture('cle-non-compressee.pem');
        config(['caisse_securisee.cles_publiques' => ['cle.pem' => $cle]]);

        $clesEpinglees = $this->signataire()->clesEpinglees();

        $this->assertSame([$this->cleIdDe($cle) => $cle], $clesEpinglees);
    }

    public function test_une_cle_privee_non_epinglee_refuse_de_signer(): void
    {
        $nonEpinglee = $this->autrePaire();
        config(['caisse_securisee.cle_privee' => base64_encode($nonEpinglee['privee'])]);

        $this->assertThrows(
            fn () => $this->signataire()->signer(self::CONTENU),
            ModuleFiscalNonConfigureException::class,
            "la clé publique {$this->cleIdDe($nonEpinglee['publique'])} n'est pas épinglée",
        );
    }

    public function test_une_cle_privee_absente_refuse_de_signer(): void
    {
        config(['caisse_securisee.cle_privee' => null]);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente');

        config(['caisse_securisee.cle_privee' => '']);
        $this->assertThrows(fn () => $this->signataire()->cleId(), ModuleFiscalNonConfigureException::class, 'la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente');
    }

    /**
     * Le PEM brut, sans son encodage base64, ou une valeur quelconque ne
     * sont pas des clés.
     */
    public function test_une_cle_privee_illisible_refuse_de_signer(): void
    {
        config(['caisse_securisee.cle_privee' => $this->autrePaire()['privee']]);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'la clé privée de signature est illisible');

        config(['caisse_securisee.cle_privee' => base64_encode('pas une clé')]);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'la clé privée de signature est illisible');
    }

    public function test_une_cle_privee_chiffree_ne_signe_qu_avec_sa_phrase_secrete(): void
    {
        $chiffree = $this->autrePaire(phraseSecrete: 'phrase du coffre');
        config([
            'caisse_securisee.cle_privee' => base64_encode($chiffree['privee']),
            'caisse_securisee.cles_publiques' => ['chiffree.pem' => $chiffree['publique']],
        ]);

        config(['caisse_securisee.phrase_secrete' => 'mauvaise phrase']);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'la clé privée de signature est illisible');

        config(['caisse_securisee.phrase_secrete' => 'phrase du coffre']);
        $signature = $this->signataire()->signer(self::CONTENU);
        $this->assertSame(1, openssl_verify(self::CONTENU, $this->signatureDer($signature), $chiffree['publique'], OPENSSL_ALGO_SHA256));
    }

    public function test_une_cle_qui_n_est_pas_ecdsa_p256_refuse_de_signer(): void
    {
        $p384 = $this->autrePaire('secp384r1');
        config([
            'caisse_securisee.cle_privee' => base64_encode($p384['privee']),
            'caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest()],
        ]);

        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'doit être une clé ECDSA P-256');
    }

    /**
     * Un fichier épinglé abîmé, d'une autre courbe ou contenant une clé
     * privée est une erreur de déploiement : rien ne se signe ni ne se
     * vérifie tant qu'il reste dans le dossier.
     */
    public function test_une_cle_epinglee_invalide_bloque_la_signature_et_la_verification(): void
    {
        config(['caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest(), 'abimee.pem' => "-----BEGIN PUBLIC KEY-----\nabime\n-----END PUBLIC KEY-----\n"]]);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'la clé épinglée abimee.pem');

        config(['caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest(), 'p384.pem' => $this->autrePaire('secp384r1')['publique']]]);
        $this->assertThrows(fn () => $this->signataire()->verifier(self::CONTENU, 'AA', str_repeat('0', 64)), ModuleFiscalNonConfigureException::class, 'la clé épinglée p384.pem');

        config(['caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest(), 'fuite.pem' => $this->autrePaire()['privee']]]);
        $this->assertThrows(fn () => $this->signataire()->signer(self::CONTENU), ModuleFiscalNonConfigureException::class, 'le fichier épinglé fuite.pem contient une clé privée');
    }

    public function test_les_cles_epinglees_se_listent_par_cle_id(): void
    {
        $autre = $this->autrePaire();
        config(['caisse_securisee.cles_publiques' => ['tests.pem' => $this->clePubliqueDeTest(), 'autre.pem' => $autre['publique']]]);

        $clesEpinglees = $this->signataire()->clesEpinglees();

        $this->assertSame([
            $this->cleIdDe($this->clePubliqueDeTest()) => $this->clePubliqueDeTest(),
            $this->cleIdDe($autre['publique']) => $autre['publique'],
        ], $clesEpinglees);
    }
}
