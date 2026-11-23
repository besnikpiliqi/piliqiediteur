<?php

namespace App\Domain\Demonstration\Courriels;

use App\Domain\Tenancy\Support\ValeurSimple;

/**
 * L'adresse du site dans le HTML et le texte d'un e-mail capturé par la
 * démonstration (voir TransportDeLaDemonstration), pour que ses liens
 * ouvrent la bonne page sur le site qui sert la copie (demande de
 * l'éditeur du 20/11/2026) : un e-mail fabrique ses liens avec l'adresse du
 * site au moment de l'envoi (APP_URL pour un travail de la file, et pour le
 * semis qui a construit la base modèle), qui n'est ni le port d'un poste ni
 * le domaine d'une copie. À la capture, cette adresse est remplacée par un
 * repère ; à l'affichage, par l'adresse de la requête en cours. Un lien ne
 * peut pas être relatif : le cadre isolé (srcdoc) n'a pas d'adresse de base.
 * Les e-mails du site réel, eux, gardent une adresse absolue.
 *
 * Un e-mail écrit l'adresse du site de deux façons, qui diffèrent quand il
 * part d'une requête sur un autre hôte ou un autre port que celui d'APP_URL
 * (le semis d'un modèle, une copie servie sur son domaine) : les liens des
 * pages et des boutons, construits par url() depuis la requête, et le lien du
 * titre du gabarit des e-mails, lu dans APP_URL. Les deux se remplacent par le
 * repère (relecture du lot écrans, « Devis accepté en ligne » d'une base
 * modèle, dont le titre gardait l'adresse du site de construction).
 */
final class AdresseDuSiteDansLesCourriels
{
    /**
     * Ce qui tient la place de l'adresse du site dans un e-mail rangé.
     */
    public const REPERE = '{{SITE}}';

    /**
     * Ce contenu sans l'adresse de ce site, remplacée par le repère : seule
     * l'adresse entière compte, jamais le début d'une autre
     * (« http://localhost » dans « http://localhost:8001 »).
     */
    public static function capturer(?string $contenu): ?string
    {
        if ($contenu === null) {
            return null;
        }

        foreach (self::racinesEcrites() as $racine) {
            $contenu = (string) preg_replace('~'.preg_quote($racine, '~').'(?=[/?#"\'\s<>)\]]|$)~', self::REPERE, $contenu);
        }

        return $contenu;
    }

    /**
     * Ce contenu avec l'adresse du site qui le sert à la place du repère.
     */
    public static function afficher(?string $contenu): ?string
    {
        return $contenu === null ? null : str_replace(self::REPERE, self::racine(), $contenu);
    }

    /**
     * L'adresse de ce site : celle de la requête en cours, ou APP_URL hors
     * d'une requête, sans « / » final.
     */
    private static function racine(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * Les adresses que le logiciel écrit pour ce site dans un e-mail : celle
     * de la requête en cours (voir racine()) et celle d'APP_URL, sans « / »
     * final, chacune une fois, la plus longue d'abord.
     *
     * @return list<string>
     */
    private static function racinesEcrites(): array
    {
        $adresses = array_values(array_unique(array_filter([self::racine(), rtrim(ValeurSimple::enTexte(config('app.url')), '/')], static fn (string $adresse): bool => $adresse !== '')));
        usort($adresses, static fn (string $premiere, string $seconde): int => strlen($seconde) <=> strlen($premiere));

        return $adresses;
    }
}
