<?php

namespace App\Domain\Demonstration\Etat;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Exceptions\SiteDeDemonstrationInjoignable;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Services\AlerteEditeur;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * La lecture, par le site réel, de l'état publié du site de démonstration
 * (lot DEMO-COPIE, C.19), chaque heure : sa signature se vérifie par la clé
 * publique de l'état (DEMONSTRATION_ETAT_CLE_PUBLIQUE), puis chaque
 * problème nouveau devient une alerte à l'éditeur (une par jour et par
 * problème). Un état illisible, mal signé ou vieux de plus de 2 heures est
 * lui-même une alerte. Le site réel ne lit rien d'autre du site de
 * démonstration.
 */
final class LectureDeLEtat
{
    public const AGE_MAXIMAL = 7200;

    public const OBJET = 'Le site de démonstration signale un problème';

    public function __construct(
        private readonly SiteDeDemonstration $site,
        private readonly AlerteEditeur $alerte,
    ) {}

    /**
     * Les problèmes lus, après les alertes ; une liste vide quand tout va
     * bien ou que le site de démonstration n'est pas lancé.
     *
     * @return list<string>
     */
    public function lire(): array
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);

        if (! $this->site->estLance()) {
            return [];
        }

        try {
            $publie = $this->site->lireLEtat();
        } catch (SiteDeDemonstrationInjoignable $erreur) {
            return $this->alerter(['L\'état du site de démonstration est illisible : '.$erreur->getMessage()]);
        }

        $etat = self::verifier($publie['etat'], $publie['signature']);

        if ($etat === null) {
            return $this->alerter(['L\'état du site de démonstration est mal signé ou illisible.']);
        }

        $emisLe = Calendrier::lireUnInstantUtc($etat['emis_le'] ?? null);

        if ($emisLe === null || $emisLe->lessThan(CarbonImmutable::now()->subSeconds(self::AGE_MAXIMAL))) {
            return $this->alerter(['L\'état du site de démonstration a plus de 2 heures : son planificateur ou ses pages ne tournent plus.']);
        }

        $problemes = array_values(array_filter((array) ($etat['problemes'] ?? []), is_string(...)));

        return $this->alerter($problemes);
    }

    /**
     * Les champs de l'état, si sa signature se vérifie par la clé publique
     * de l'état ; null sinon.
     *
     * @return array<string, mixed>|null
     */
    public static function verifier(string $etat, string $signature): ?array
    {
        $cle = config('demonstration.etat.cle_publique');
        $publique = is_string($cle) && $cle !== '' ? base64_decode($cle, true) : false;
        $signatureLue = MessagesSignes::depuisLaBase64Url($signature);

        if ($publique === false || strlen($publique) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signatureLue === null
            || strlen($signatureLue) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signatureLue, $etat, $publique)) {
            return null;
        }

        $champs = json_decode($etat, true);

        return is_array($champs) && ! array_is_list($champs) ? $champs : null;
    }

    /**
     * Une alerte par problème nouveau du jour (calendrier de Paris).
     *
     * @param  list<string>  $problemes
     * @return list<string>
     */
    private function alerter(array $problemes): array
    {
        $jour = Calendrier::deParis()->aujourdhui();
        $nouveaux = array_values(array_filter($problemes, static fn (string $probleme): bool => Cache::add('demonstration-etat:'.$jour.':'.sha1($probleme), true, 2 * 86400)));

        if ($nouveaux !== []) {
            $this->alerte->alerterDeLaPlateforme(self::OBJET, $nouveaux);
        }

        return $problemes;
    }
}
