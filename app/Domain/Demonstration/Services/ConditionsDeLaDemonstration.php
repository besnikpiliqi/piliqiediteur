<?php

namespace App\Domain\Demonstration\Services;

use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Qui fait les gestes de la carte « Ma démonstration », et quand (lot
 * DEMO-COPIE, B.2, B.3 et choix 12) : le propriétaire seul, actif, de sa
 * société, sur le site réel ; jamais un administrateur (même de tous les
 * magasins et autorisé à tout), un responsable, un vendeur, un observateur,
 * un collaborateur, un compte d'un cabinet lié ni un expert-comptable
 * déclaré. Chaque refus se dit en français ; un refus de droit ne dit rien
 * de plus et n'écrit rien.
 *
 * Selon l'état de la société : une inscription close ou une société
 * révoquée n'a plus de démonstration ; une société suspendue ou résiliée
 * garde la sienne jusqu'à sa date, l'ouvre et en renouvelle le lien (son
 * propriétaire doit pouvoir couper un lien perdu), mais n'en crée pas de
 * nouvelle et ne la remet pas à zéro. Une société non validée, même sans
 * magasin, demande la sienne : elle s'y essaie avant sa validation. Le lien
 * ne part qu'à une adresse de connexion confirmée.
 */
final class ConditionsDeLaDemonstration
{
    public const REFUS = 'Seul le propriétaire gère la démonstration de sa société.';

    public const MESSAGE_ADRESSE_A_CONFIRMER = 'Confirmez d\'abord votre adresse de connexion : le lien de votre démonstration ne part qu\'à une adresse confirmée.';

    public const MESSAGE_SOCIETE_FERMEE = 'L\'inscription de votre société est close ou votre société est révoquée : elle n\'a plus de démonstration.';

    public const MESSAGE_SOCIETE_SUSPENDUE = 'Votre société est suspendue ou résiliée : vous pouvez ouvrir votre démonstration et en renouveler le lien jusqu\'à sa fin, mais ni en créer une nouvelle ni la remettre à zéro.';

    public const MESSAGE_CABINET_SUSPENDU = 'Votre cabinet est suspendu : vous ne pouvez pas créer de démonstration.';

    public const MESSAGE_SITE_NON_LANCE = 'Le site de démonstration n\'est pas lancé sur ce poste : voir le README.';

    public function __construct(private readonly SiteDeDemonstration $site) {}

    /**
     * La personne voit-elle la carte ? Le propriétaire actif de cette
     * société, sur le site réel, tant que la société a encore une
     * démonstration possible (ni close, ni révoquée).
     */
    public function voitLaCarte(?User $compte, Societe $societe): bool
    {
        return SiteDePiliqi::courant() === SiteDePiliqi::Reel
            && $this->estSonProprietaire($compte, $societe)
            && ! $this->estFermee($societe);
    }

    /**
     * @throws AuthorizationException quand la personne n'est pas le propriétaire actif de cette société
     */
    public function exigerLeProprietaire(User $compte, Societe $societe): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Reel);

        if (! $this->estSonProprietaire($compte, $societe)) {
            throw new AuthorizationException(self::REFUS);
        }
    }

    /**
     * Les conditions d'un geste : le propriétaire, une société qui a encore
     * une démonstration, et, pour un geste qui envoie le lien, une adresse
     * de connexion confirmée ; pour créer ou recommencer, une société ni
     * suspendue ni résiliée ; un site de démonstration réglé.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function exiger(User $compte, Societe $societe, bool $envoieLeLien, bool $creeOuRecommence): void
    {
        $this->exigerLeProprietaire($compte, $societe);

        if ($this->estFermee($societe)) {
            throw new LogicException(self::MESSAGE_SOCIETE_FERMEE);
        }

        if ($creeOuRecommence && in_array($societe->statut, [StatutSociete::Suspendue, StatutSociete::Resiliee], true)) {
            throw new LogicException(self::messageDeSuspension($societe));
        }

        if ($envoieLeLien && ! $compte->aUneAdresseDeConnexion()) {
            throw new LogicException(self::MESSAGE_ADRESSE_A_CONFIRMER);
        }

        if (! $this->site->estLance()) {
            throw new LogicException(self::MESSAGE_SITE_NON_LANCE);
        }
    }

    /**
     * Le refus d'une création ou d'une remise à zéro dans une société
     * suspendue ou résiliée, selon qu'elle est un cabinet ou non.
     */
    public static function messageDeSuspension(Societe $societe): string
    {
        return $societe->estUnCabinet() ? self::MESSAGE_CABINET_SUSPENDU : self::MESSAGE_SOCIETE_SUSPENDUE;
    }

    public function estFermee(Societe $societe): bool
    {
        return $societe->estClose() || $societe->statut === StatutSociete::Revoquee;
    }

    public function peutCreerOuRecommencer(Societe $societe): bool
    {
        return ! $this->estFermee($societe) && ! in_array($societe->statut, [StatutSociete::Suspendue, StatutSociete::Resiliee], true);
    }

    private function estSonProprietaire(?User $compte, Societe $societe): bool
    {
        return $compte !== null
            && $compte->estProprietaire()
            && $compte->estActif()
            && (int) $compte->societe_id === $societe->getKey();
    }
}
