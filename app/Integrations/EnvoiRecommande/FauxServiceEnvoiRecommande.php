<?php

namespace App\Integrations\EnvoiRecommande;

use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Illuminate\Support\Str;

/**
 * Le prestataire d'envoi factice du développement et des tests, sans
 * aucune requête (voir VerificationServiceProvider) : chaque pli est noté,
 * déposé, avec son corps (voir envois()), et reste déposé tant qu'un test
 * ne le remet pas ou ne le retourne pas (voir remettre(), retourner()).
 */
class FauxServiceEnvoiRecommande implements ServiceEnvoiRecommande
{
    public const COURRIER = 'courrier';

    public const LRE = 'lre';

    public const LETTRE_SIMPLE = 'lettre_simple';

    private bool $injoignable = false;

    /** @var array<string, array{genre: string, destinataire: AdressePostale|array{nom: string, email: string}, objet: string, corps: list<string>, suivi: SuiviDEnvoi}> */
    private array $envois = [];

    public function envoyerCourrier(AdressePostale $destinataire, string $objet, array $corps): string
    {
        return $this->noter(self::COURRIER, $destinataire, $objet, $corps);
    }

    public function envoyerLre(string $nomDuDestinataire, string $email, string $objet, array $corps): string
    {
        return $this->noter(self::LRE, ['nom' => $nomDuDestinataire, 'email' => $email], $objet, $corps);
    }

    public function envoyerLettreSimple(AdressePostale $destinataire, string $objet, array $corps): string
    {
        return $this->noter(self::LETTRE_SIMPLE, $destinataire, $objet, $corps);
    }

    public function suivre(string $reference): SuiviDEnvoi
    {
        $this->repondre();

        return $this->envois[$reference]['suivi'] ?? new SuiviDEnvoi(SuiviDEnvoi::DEPOSE);
    }

    /**
     * Les plis envoyés, par référence, dans l'ordre.
     *
     * @return array<string, array{genre: string, destinataire: AdressePostale|array{nom: string, email: string}, objet: string, corps: list<string>, suivi: SuiviDEnvoi}>
     */
    public function envois(): array
    {
        return $this->envois;
    }

    /**
     * Le code de confirmation que porte le dernier pli recommandé, tel que
     * son destinataire le lit (XXXX-XXXX-XXXX), null s'il n'en porte pas.
     */
    public function codeDuDernierPli(): ?string
    {
        $dernier = collect($this->envois)->last(fn (array $envoi): bool => $envoi['genre'] !== self::LETTRE_SIMPLE);

        return $dernier === null ? null : (preg_match('/\b([0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4})\b/', implode("\n", $dernier['corps']), $code) === 1 ? $code[1] : null);
    }

    /**
     * Le pli est remis contre signature ; une lettre électronique, acceptée
     * par la personne que le prestataire a identifiée.
     *
     * @param  array<mixed>|null  $identite  la réponse brute du prestataire sur l'identité du destinataire
     */
    public function remettre(string $reference, ?array $identite = null): void
    {
        $this->envois[$reference]['suivi'] = SuiviDEnvoi::depuisLeRappel(['statut' => SuiviDEnvoi::REMIS, 'identite' => $identite]);
    }

    public function retourner(string $reference): void
    {
        $this->envois[$reference]['suivi'] = new SuiviDEnvoi(SuiviDEnvoi::RETOURNE);
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @param  AdressePostale|array{nom: string, email: string}  $destinataire
     * @param  list<string>  $corps
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    private function noter(string $genre, AdressePostale|array $destinataire, string $objet, array $corps): string
    {
        $this->repondre();
        $reference = 'faux-'.Str::lower(Str::random(16));
        $this->envois[$reference] = ['genre' => $genre, 'destinataire' => $destinataire, 'objet' => $objet, 'corps' => $corps, 'suivi' => new SuiviDEnvoi(SuiviDEnvoi::DEPOSE)];

        return $reference;
    }

    /**
     * @throws EnvoiRecommandeIndisponibleException
     */
    private function repondre(): void
    {
        if ($this->injoignable) {
            throw new EnvoiRecommandeIndisponibleException;
        }
    }
}
