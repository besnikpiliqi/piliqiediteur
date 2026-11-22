<?php

namespace App\Domain\Demonstration\Courriels;

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

        $racine = self::racine();

        return $racine === '' ? $contenu : (string) preg_replace('~'.preg_quote($racine, '~').'(?=[/?#"\'\s<>)\]]|$)~', self::REPERE, $contenu);
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
}
