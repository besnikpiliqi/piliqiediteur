<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Concerns\CompteursAtomiques;
use Illuminate\Contracts\Cache\Factory;
use SensitiveParameter;

/**
 * La limitation des essais sur les liens qui mènent à un mot de passe ou à
 * un compte (décision de Claude D76, T36.1 à T36.4), sur les mêmes
 * compteurs atomiques et en HMAC que celle de la connexion (voir
 * LimitationDesConnexions et CompteursAtomiques), jamais un blocage pour de
 * bon : chaque attente finit avec sa fenêtre.
 * - « Mot de passe oublié » (voir demande()) : au plus 3 demandes par heure
 *   et 10 par jour pour une même saisie (adresse ou identifiant), 10 par
 *   heure par adresse IP ; chaque demande compte, que la saisie désigne un
 *   propriétaire ou non : même réponse, même compteur, même délai, aucune
 *   énumération.
 * - La page d'un lien (choix du mot de passe « Mot de passe oublié »,
 *   premier mot de passe d'un expert-comptable de la société, accès redonné
 *   par un nouveau lien, confirmation d'une adresse, transmission de la
 *   propriété, acceptation d'une invitation d'un cabinet) : au plus 10
 *   essais par heure par adresse IP pour un jeton inconnu ou expiré (voir
 *   jetonInvalide()), et 5 mots de passe refusés sur un même lien avant 15
 *   minutes d'attente (voir motDePasseRefuse()) : le lien reste valable
 *   jusqu'à son échéance. Un jeton n'est jamais gardé en clair : la clé du
 *   compteur est son HMAC.
 */
final class LimitationDesLiens
{
    use CompteursAtomiques;

    public const DEMANDES_PAR_HEURE_ET_PAR_SAISIE = 3;

    public const DEMANDES_PAR_JOUR_ET_PAR_SAISIE = 10;

    public const DEMANDES_PAR_HEURE_ET_PAR_ADRESSE = 10;

    public const JETONS_INVALIDES_PAR_HEURE = 10;

    public const MOTS_DE_PASSE_REFUSES_PAR_LIEN = 5;

    public const ATTENTE_D_UN_LIEN = 900;

    public const UNE_HEURE = 3600;

    public const UN_JOUR = 86400;

    public function __construct(private readonly Factory $caches) {}

    protected function prefixeDesCles(): string
    {
        return 'lien-';
    }

    /**
     * Le message d'une attente sur un lien, en minutes entières.
     */
    public static function message(int $secondes): string
    {
        $minutes = max(1, (int) ceil($secondes / 60));

        return 'Trop de tentatives. Réessayez dans '.$minutes.' minute'.($minutes > 1 ? 's' : '').'.';
    }

    /**
     * Compte une demande de « Mot de passe oublié » pour cette saisie
     * (adresse en minuscules, ou identifiant normalisé) depuis cette adresse
     * IP, et rend les secondes à attendre avant la suivante ; 0 quand elle
     * est permise. Chaque demande compte, aussi celle qui est refusée et
     * celle d'une saisie qui ne désigne personne.
     */
    public function demande(#[SensitiveParameter] string $saisie, string $adresse): int
    {
        $parHeure = $this->compter($this->cle('demande-heure', $saisie), self::UNE_HEURE);
        $parJour = $this->compter($this->cle('demande-jour', $saisie), self::UN_JOUR);
        $parAdresse = $this->compter($this->cle('demande-adresse', $adresse), self::UNE_HEURE);

        return max(
            $parHeure > self::DEMANDES_PAR_HEURE_ET_PAR_SAISIE ? $this->secondesRestantes($this->cle('demande-heure', $saisie)) : 0,
            $parJour > self::DEMANDES_PAR_JOUR_ET_PAR_SAISIE ? $this->secondesRestantes($this->cle('demande-jour', $saisie)) : 0,
            $parAdresse > self::DEMANDES_PAR_HEURE_ET_PAR_ADRESSE ? $this->secondesRestantes($this->cle('demande-adresse', $adresse)) : 0,
        );
    }

    /**
     * Les secondes que cette adresse IP doit encore attendre avant d'essayer
     * un autre lien, après trop de jetons inconnus ou expirés ; 0 sans
     * attente.
     */
    public function attenteDeLAdresse(string $adresse): int
    {
        return $this->secondesRestantes($this->cleDAttente('jeton', $adresse));
    }

    /**
     * Compte un jeton inconnu ou expiré essayé depuis cette adresse IP : au
     * dixième dans l'heure, l'adresse attend jusqu'à la fin de cette heure.
     */
    public function jetonInvalide(string $adresse): void
    {
        if ($this->compter($this->cle('jeton', $adresse), self::UNE_HEURE) >= self::JETONS_INVALIDES_PAR_HEURE) {
            $this->attendre($this->cleDAttente('jeton', $adresse), max(1, $this->secondesRestantes($this->cle('jeton', $adresse))));
        }
    }

    /**
     * Les secondes que ce lien doit encore attendre avant d'accepter un
     * autre mot de passe, après trop de mots de passe refusés ; 0 sans
     * attente. Le lien reste valable jusqu'à son échéance.
     */
    public function attenteDuLien(#[SensitiveParameter] string $jeton): int
    {
        return $this->secondesRestantes($this->cleDAttente('lien', $jeton));
    }

    /**
     * Compte un mot de passe refusé sur ce lien : au cinquième, le lien
     * attend 15 minutes, puis recommence à compter.
     */
    public function motDePasseRefuse(#[SensitiveParameter] string $jeton): void
    {
        if ($this->compter($this->cle('refus', $jeton), self::ATTENTE_D_UN_LIEN) >= self::MOTS_DE_PASSE_REFUSES_PAR_LIEN) {
            $this->attendre($this->cleDAttente('lien', $jeton), self::ATTENTE_D_UN_LIEN);
            $this->effacer($this->cle('refus', $jeton));
        }
    }

    /**
     * Le mot de passe d'un lien est accepté : ses refus ne comptent plus.
     */
    public function motDePasseAccepte(#[SensitiveParameter] string $jeton): void
    {
        $this->effacer($this->cle('refus', $jeton));
        $this->effacer($this->cleDAttente('lien', $jeton));
    }
}
