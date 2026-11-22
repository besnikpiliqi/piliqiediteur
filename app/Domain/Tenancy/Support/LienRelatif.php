<?php

namespace App\Domain\Tenancy\Support;

/**
 * Un lien vers une page de PILIQI, rangé sans adresse du site : seulement
 * son chemin (« /app/…/factures/12 »), pour qu'il suive le site qui sert la
 * page où on le lit. Une adresse absolue rangée avec la notification
 * garderait pour toujours l'adresse et le port du site qui l'a fabriquée
 * (APP_URL au moment de la construction, un autre port sur un poste, le nom
 * de domaine d'avant, l'adresse de la base modèle d'une copie de
 * démonstration) et mènerait à une page « La connexion a échoué »
 * (demande de l'éditeur du 20/11/2026). Un e-mail, qui sort du site, garde
 * au contraire une adresse absolue.
 *
 * Seule l'adresse du site lui-même s'enlève (voir racine()) : le lien vers
 * un autre site reste entier.
 */
final class LienRelatif
{
    /**
     * Ce lien sans l'adresse de ce site, ou tel quel s'il mène ailleurs.
     */
    public static function de(string $lien): string
    {
        $racine = self::racine();

        if ($racine === '' || ! str_starts_with($lien, $racine)) {
            return $lien;
        }

        $reste = substr($lien, strlen($racine));

        return match (true) {
            $reste === '' => '/',
            $reste[0] === '/' => $reste,
            in_array($reste[0], ['?', '#'], true) => '/'.$reste,
            default => $lien,
        };
    }

    /**
     * Ces données, chaque texte qui est un lien vers ce site rendu relatif,
     * où qu'il soit (« url » d'un bouton, lien d'un avis…) : les nombres, les
     * booléens et les textes qui ne sont pas un lien de ce site restent tels
     * quels.
     *
     * @param  array<array-key, mixed>  $donnees
     * @return array<array-key, mixed>
     */
    public static function dans(array $donnees): array
    {
        return array_map(fn (mixed $valeur): mixed => match (true) {
            is_string($valeur) => self::de($valeur),
            is_array($valeur) => self::dans($valeur),
            default => $valeur,
        }, $donnees);
    }

    /**
     * L'adresse de ce site : celle de la requête en cours, ou APP_URL hors
     * d'une requête (le semis, une tâche), sans « / » final.
     */
    private static function racine(): string
    {
        return rtrim(url('/'), '/');
    }
}
