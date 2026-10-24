<?php

namespace App\Integrations\EnvoiRecommande;

use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Le prestataire d'envoi factice du développement et des tests, sans
 * aucune requête (voir VerificationServiceProvider) : chaque courrier est
 * noté, déposé, avec son PDF (voir envois()), et reste déposé tant qu'un
 * test ne le remet pas ou ne le retourne pas (voir remettre(),
 * retourner()).
 *
 * Les courriers se gardent dans le cache de l'application, en tableaux
 * simples (le cache ne désérialise aucun objet), comme un prestataire les
 * garderait chez lui : le suivi, qui arrive dans d'autres requêtes
 * (d'autres processus en développement), les retrouve. En développement
 * (environnement local seulement), chaque courrier s'écrit aussi en PDF
 * sur le disque privé, dans DOSSIER_DES_COURRIERS, pour qu'on puisse le
 * lire et saisir son code ; le journal de l'application n'en note que le
 * chemin : ce prestataire ne sert jamais en production (voir
 * VerificationServiceProvider::boot()). Le cache des tests ne vit que le
 * temps d'un test.
 */
class FauxServiceEnvoiRecommande implements ServiceEnvoiRecommande
{
    /**
     * La clé du cache où se gardent les courriers.
     */
    public const CLE_DU_CACHE = 'faux_service_envoi_recommande.envois';

    /**
     * Le dossier du disque privé où s'écrivent les courriers en
     * développement.
     */
    public const DOSSIER_DES_COURRIERS = 'courriers-factices';

    private bool $injoignable = false;

    public function envoyerCourrier(DestinataireDuCourrier $destinataire, string $pdf, bool $remiseEnPersonne): string
    {
        if ($this->injoignable) {
            throw new EnvoiRecommandeIndisponibleException;
        }

        $reference = 'faux-'.Str::lower(Str::random(16));
        Cache::forever(self::CLE_DU_CACHE, [...Cache::get(self::CLE_DU_CACHE, []), $reference => [
            'destinataire' => $destinataire->enTableau(),
            'pdf' => base64_encode($pdf),
            'remise_en_personne' => $remiseEnPersonne,
            'suivi' => ['statut' => SuiviDEnvoi::DEPOSE],
        ]]);

        if (app()->isLocal()) {
            $chemin = self::DOSSIER_DES_COURRIERS."/{$reference}.pdf";
            Storage::disk('local')->put($chemin, $pdf);
            Log::info("Courrier recommandé factice {$reference} : ".Storage::disk('local')->path($chemin), ['destinataire' => $destinataire->lignes()]);
        }

        return $reference;
    }

    public function suivre(string $reference): SuiviDEnvoi
    {
        if ($this->injoignable) {
            throw new EnvoiRecommandeIndisponibleException;
        }

        return SuiviDEnvoi::depuisLeRappel(Cache::get(self::CLE_DU_CACHE, [])[$reference]['suivi'] ?? []);
    }

    /**
     * Les courriers envoyés, par référence, dans l'ordre.
     *
     * @return array<string, array{destinataire: DestinataireDuCourrier, pdf: string, remise_en_personne: bool, suivi: SuiviDEnvoi}>
     */
    public function envois(): array
    {
        return array_map(fn (array $envoi): array => [
            'destinataire' => DestinataireDuCourrier::depuisTableau($envoi['destinataire']),
            'pdf' => (string) base64_decode($envoi['pdf'], true),
            'remise_en_personne' => $envoi['remise_en_personne'],
            'suivi' => SuiviDEnvoi::depuisLeRappel($envoi['suivi']),
        ], Cache::get(self::CLE_DU_CACHE, []));
    }

    /**
     * Le courrier est remis contre signature.
     */
    public function remettre(string $reference): void
    {
        $this->suivreLePli($reference, SuiviDEnvoi::REMIS);
    }

    /**
     * Le courrier revient à l'expéditeur, faute d'avoir été retiré.
     */
    public function retourner(string $reference): void
    {
        $this->suivreLePli($reference, SuiviDEnvoi::RETOURNE);
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    private function suivreLePli(string $reference, string $statut): void
    {
        $envois = Cache::get(self::CLE_DU_CACHE, []);
        $envois[$reference]['suivi'] = ['statut' => $statut];
        Cache::forever(self::CLE_DU_CACHE, $envois);
    }
}
