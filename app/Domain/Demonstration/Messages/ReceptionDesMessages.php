<?php

namespace App\Domain\Demonstration\Messages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Enums\QuoiDuMessage;
use App\Domain\Demonstration\Exceptions\MessageRefuse;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\Reglage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La réception, sur le site de démonstration, des messages signés du site
 * réel et du billet d'entrée du bouton du propriétaire (lot DEMO-COPIE,
 * C.5 et C.7). Une signature juste ne suffit pas : chaque borne est une
 * seconde barrière si le site réel avait un défaut ou si sa clé était
 * volée. Sont refusés, chacun avec sa cause : une signature fausse, une
 * clé inconnue, un champ hors de la liste fermée, une version du protocole
 * ni courante ni précédente, une autre audience, un autre destinataire, un
 * message émis il y a plus de 5 minutes (un billet, plus de 60 secondes)
 * ou plus de 30 secondes dans le futur, un nonce déjà vu, une action
 * inconnue, une référence hors de ^[0-9a-f]{32}$, et, pour « creer », un
 * type, un repère, une empreinte, une génération ou une fin hors de leurs
 * bornes (fin plus de 30 jours et 1 heure après la réception).
 */
final class ReceptionDesMessages
{
    public const AVANCE_PERMISE = 30;

    public const AGE_D_UN_MESSAGE = 300;

    public const DUREE_MAXIMALE_D_UNE_COPIE = 30 * 86400 + 3600;

    /**
     * Les champs d'un message lu, après chaque contrôle ; son nonce est
     * consommé.
     *
     * @return array<string, mixed>
     *
     * @throws MessageRefuse avec la cause du refus
     */
    public function lire(string $message, string $signature, string $audience): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $champs = MessagesSignes::verifier($message, $signature, (array) config('demonstration.messages.cles_publiques'));
        $maintenant = CarbonImmutable::now();

        if (array_diff(array_keys($champs), MessagesSignes::CHAMPS) !== []) {
            throw new MessageRefuse('Le message porte un champ hors de sa liste fermée.');
        }

        if (! is_int($champs['v'] ?? null) || ! in_array($champs['v'], self::versionsAcceptees(), true)) {
            throw new MessageRefuse('La version du protocole n\'est ni la courante ni la précédente.');
        }

        if (($champs['aud'] ?? null) !== $audience) {
            throw new MessageRefuse('L\'audience du message n\'est pas celle de cette adresse.');
        }

        if (($champs['destinataire'] ?? null) !== Reglage::texte('demonstration.nom_du_site')) {
            throw new MessageRefuse('Le message est destiné à un autre site.');
        }

        $emisLe = Calendrier::lireUnInstantUtc($champs['emis_le'] ?? null) ?? throw new MessageRefuse('L\'heure d\'émission du message est illisible.');
        $age = $audience === MessagesSignes::AUDIENCE_DE_L_ENTREE ? MessagesSignes::VALIDITE_DU_BILLET : self::AGE_D_UN_MESSAGE;

        if ($emisLe->lessThan($maintenant->subSeconds($age))) {
            throw new MessageRefuse('Le message est trop ancien.');
        }

        if ($emisLe->greaterThan($maintenant->addSeconds(self::AVANCE_PERMISE))) {
            throw new MessageRefuse('Le message est émis dans le futur.');
        }

        if (! is_string($champs['reference'] ?? null) || preg_match('/^[0-9a-f]{32}$/', $champs['reference']) !== 1) {
            throw new MessageRefuse('La référence de la copie est mal formée.');
        }

        $quoi = $champs['quoi'] ?? null;
        $attendu = $audience === MessagesSignes::AUDIENCE_DE_L_ENTREE
            ? $quoi === MessagesSignes::BILLET_D_ENTREE
            : is_string($quoi) && QuoiDuMessage::tryFrom($quoi) !== null;

        if (! $attendu) {
            throw new MessageRefuse('L\'action du message est inconnue.');
        }

        if ($quoi === QuoiDuMessage::Creer->value) {
            $this->controlerUneCreation($champs, $maintenant);
        }

        if ($quoi === QuoiDuMessage::RenouvelerLeLien->value) {
            self::controlerLeLien($champs);
        }

        $nonce = $champs['nonce'] ?? null;

        if (! is_string($nonce) || preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new MessageRefuse('Le nonce du message est mal formé.');
        }

        if (DB::connection('registre')->table('messages_recus')->insertOrIgnore(['nonce' => $nonce, 'audience' => $audience, 'recu_le' => $maintenant]) === 0) {
            throw new MessageRefuse('Ce message a déjà été reçu.');
        }

        return $champs;
    }

    /**
     * La version courante du protocole et la précédente (choix 20).
     *
     * @return list<int>
     */
    public static function versionsAcceptees(): array
    {
        return array_values(array_filter([MessagesSignes::VERSION - 1, MessagesSignes::VERSION], static fn (int $version): bool => $version >= 1));
    }

    /**
     * @param  array<string, mixed>  $champs
     */
    private function controlerUneCreation(array $champs, CarbonImmutable $maintenant): void
    {
        if (! is_string($champs['type'] ?? null) || TypeDActivite::tryFrom($champs['type']) === null) {
            throw new MessageRefuse('Le type de la copie est inconnu.');
        }

        if (! is_string($champs['repere'] ?? null) || preg_match('/^[ACDEFGHJKMNPQRTUVWXY234679]{6}$/', $champs['repere']) !== 1) {
            throw new MessageRefuse('Le repère de la copie est mal formé.');
        }

        if (! is_bool($champs['validee'] ?? null)) {
            throw new MessageRefuse('Le message ne dit pas si la société est validée.');
        }

        self::controlerLeLien($champs);
        $fin = Calendrier::lireUnInstantUtc($champs['fin'] ?? null);

        if ($fin === null || $fin->lessThanOrEqualTo($maintenant) || $fin->greaterThan($maintenant->addSeconds(self::DUREE_MAXIMALE_D_UNE_COPIE))) {
            throw new MessageRefuse('La fin de la copie est hors de ses bornes (au plus 30 jours et 1 heure).');
        }
    }

    /**
     * @param  array<string, mixed>  $champs
     */
    private static function controlerLeLien(array $champs): void
    {
        if (! is_string($champs['empreinte_du_lien'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $champs['empreinte_du_lien']) !== 1) {
            throw new MessageRefuse('L\'empreinte du lien est mal formée.');
        }

        if (! is_int($champs['generation'] ?? null) || $champs['generation'] < 1) {
            throw new MessageRefuse('La génération du lien est mal formée.');
        }
    }
}
