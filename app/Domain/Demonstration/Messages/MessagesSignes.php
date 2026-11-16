<?php

namespace App\Domain\Demonstration\Messages;

use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Exceptions\MessageRefuse;
use App\Domain\Demonstration\Exceptions\MessagesNonSignables;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\Reglage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Les messages signés entre les deux sites de PILIQI (lot DEMO-COPIE) : du
 * site réel vers le site de démonstration ne passent que ces messages
 * (créer, renouveler le lien, recommencer, supprimer, état) et le billet
 * d'entrée du bouton « Ouvrir ma démonstration ».
 *
 * Un message est un JSON canonique (clés triées, aucun nombre à virgule,
 * voir canonique()), signé octet par octet par la clé privée Ed25519 des
 * messages du site réel (DEMONSTRATION_MESSAGES_CLE_PRIVEE, et son
 * identifiant DEMONSTRATION_MESSAGES_CLE_ID), signature détachée en
 * base64url. Ses champs forment une liste fermée (voir CHAMPS) : rien
 * d'autre de la société ne passe, ni son nom, ni son SIREN, ni un compte.
 * L'audience distingue les messages (« messages ») du billet d'entrée
 * (« entree ») : un billet envoyé comme message est refusé, et l'inverse.
 * Chaque envoi tire un nonce neuf (128 bits) et son instant d'émission, en
 * UTC : un message rejoué ou trop vieux est refusé par le site de
 * démonstration.
 *
 * La clé privée n'existe que sur le site réel, les clés publiques que sur
 * le site de démonstration (voir SiteDePiliqiServiceProvider) ; elles sont
 * distinctes de celles de la caisse et du journal d'audit, et se tirent
 * par php artisan demonstration:generer-cle.
 */
final class MessagesSignes
{
    /**
     * La version courante du protocole des messages ; le site de
     * démonstration accepte aussi la précédente (choix 20).
     */
    public const VERSION = 1;

    public const AUDIENCE_DES_MESSAGES = 'messages';

    public const AUDIENCE_DE_L_ENTREE = 'entree';

    public const BILLET_D_ENTREE = 'billet_d_entree';

    /**
     * La durée de validité d'un billet d'entrée, en secondes.
     */
    public const VALIDITE_DU_BILLET = 60;

    /**
     * La liste fermée des champs d'un message signé.
     */
    public const CHAMPS = ['v', 'kid', 'aud', 'quoi', 'reference', 'repere', 'type', 'validee', 'empreinte_du_lien', 'generation', 'fin', 'emis_le', 'nonce', 'destinataire'];

    public const MESSAGE_CLE_ABSENTE = 'La clé des messages de la démonstration n\'est pas réglée sur ce serveur : tirez-la par php artisan demonstration:generer-cle, puis écrivez DEMONSTRATION_MESSAGES_CLE_PRIVEE et DEMONSTRATION_MESSAGES_CLE_ID dans son .env (voir le README).';

    /**
     * Le texte canonique de ces champs : clés triées à chaque niveau, sans
     * espace, barres obliques et lettres accentuées écrites telles
     * quelles ; un nombre à virgule est refusé (sa forme écrite dépendrait
     * de la machine).
     *
     * @param  array<string, mixed>  $champs
     *
     * @throws InvalidArgumentException
     */
    public static function canonique(array $champs): string
    {
        return json_encode(self::trier($champs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Un instant, tel que les messages l'écrivent : en UTC, à la seconde.
     */
    public static function instant(CarbonImmutable $instant): string
    {
        return $instant->utc()->startOfSecond()->toIso8601ZuluString();
    }

    /**
     * Signe un message de la boîte d'envoi au moment de l'envoi : la
     * version, la clé, l'audience « messages », l'instant d'émission, un
     * nonce neuf et le destinataire s'ajoutent à son contenu.
     *
     * @param  array<string, bool|int|string>  $contenu  l'action, la référence et les champs de cette action
     * @return array{message: string, signature: string}
     *
     * @throws MessagesNonSignables
     */
    public function signerUnMessage(array $contenu): array
    {
        return $this->signer([...$contenu, 'aud' => self::AUDIENCE_DES_MESSAGES]);
    }

    /**
     * Le billet d'entrée du bouton « Ouvrir ma démonstration » : la
     * référence de la copie, l'audience « entree », valable 60 secondes,
     * pour une seule entrée (nonce).
     *
     * @return array{message: string, signature: string}
     *
     * @throws MessagesNonSignables
     */
    public function signerUnBillet(string $reference): array
    {
        return $this->signer(['quoi' => self::BILLET_D_ENTREE, 'reference' => $reference, 'aud' => self::AUDIENCE_DE_L_ENTREE]);
    }

    /**
     * Vérifie la signature d'un message reçu par l'une des clés publiques
     * connues, par son identifiant, et rend ses champs ; les bornes du
     * message (audience, âge, nonce, destinataire…) se contrôlent ensuite
     * par qui le reçoit.
     *
     * @param  array<string, string>  $clesPubliques  les clés publiques Ed25519 en base64, par identifiant
     * @return array<string, mixed>
     *
     * @throws MessageRefuse
     */
    public static function verifier(string $message, string $signature, array $clesPubliques): array
    {
        try {
            $champs = json_decode($message, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MessageRefuse('Le message n\'est pas un JSON lisible.');
        }

        if (! is_array($champs) || array_is_list($champs)) {
            throw new MessageRefuse('Le message n\'est pas un objet JSON.');
        }

        $cle = is_string($champs['kid'] ?? null) ? ($clesPubliques[$champs['kid']] ?? null) : null;
        $publique = is_string($cle) ? base64_decode($cle, true) : false;
        $signatureLue = self::depuisLaBase64Url($signature);

        if ($publique === false || strlen($publique) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new MessageRefuse('La clé du message est inconnue.');
        }

        if ($signatureLue === null || strlen($signatureLue) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signatureLue, $message, $publique)) {
            throw new MessageRefuse('La signature du message est fausse.');
        }

        return $champs;
    }

    public static function enBase64Url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    public static function depuisLaBase64Url(string $texte): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $texte) !== 1) {
            return null;
        }

        $octets = base64_decode(strtr($texte, '-_', '+/'), true);

        return $octets === false ? null : $octets;
    }

    /**
     * Une nouvelle paire de clés Ed25519 des messages, en base64.
     *
     * @return array{cle_id: string, cle_privee: string, cle_publique: string}
     */
    public static function genererUnePaire(): array
    {
        $paire = sodium_crypto_sign_keypair();
        $publique = sodium_crypto_sign_publickey($paire);

        return [
            'cle_id' => substr(hash('sha256', $publique), 0, 16),
            'cle_privee' => base64_encode(sodium_crypto_sign_secretkey($paire)),
            'cle_publique' => base64_encode($publique),
        ];
    }

    /**
     * @param  array<string, bool|int|string>  $champs
     * @return array{message: string, signature: string}
     *
     * @throws MessagesNonSignables
     */
    private function signer(array $champs): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);
        $cle = self::clePrivee(config('demonstration.messages.cle_privee'));
        $identifiant = config('demonstration.messages.cle_id');

        if ($cle === null || ! is_string($identifiant) || $identifiant === '') {
            throw new MessagesNonSignables(self::MESSAGE_CLE_ABSENTE);
        }

        $complet = [
            ...$champs,
            'v' => self::VERSION,
            'kid' => $identifiant,
            'emis_le' => self::instant(CarbonImmutable::now()),
            'nonce' => bin2hex(random_bytes(16)),
            'destinataire' => Reglage::texte('demonstration.nom_du_site'),
        ];

        if (array_diff(array_keys($complet), self::CHAMPS) !== []) {
            throw new InvalidArgumentException('Un message signé ne porte que les champs de sa liste fermée.');
        }

        if (isset($complet['quoi']) && $complet['quoi'] !== self::BILLET_D_ENTREE && QuoiDuMessage::tryFrom((string) $complet['quoi']) === null) {
            throw new InvalidArgumentException('Action inconnue des messages de la démonstration.');
        }

        $message = self::canonique($complet);

        return ['message' => $message, 'signature' => self::enBase64Url(sodium_crypto_sign_detached($message, $cle))];
    }

    private static function clePrivee(#[SensitiveParameter] mixed $valeur): ?string
    {
        $octets = is_string($valeur) && $valeur !== '' ? base64_decode($valeur, true) : false;

        return $octets !== false && strlen($octets) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $octets : null;
    }

    /**
     * @param  array<array-key, mixed>  $valeur
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function trier(array $valeur): array
    {
        if (! array_is_list($valeur)) {
            ksort($valeur, SORT_STRING);
        }

        foreach ($valeur as $cle => $element) {
            if (is_float($element)) {
                throw new InvalidArgumentException('Un message signé ne porte aucun nombre à virgule.');
            }

            if (is_array($element)) {
                $valeur[$cle] = self::trier($element);
            }
        }

        return $valeur;
    }
}
