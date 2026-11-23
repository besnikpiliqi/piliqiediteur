<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Integrations\Courriel\CauseDEchecDEnvoi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;

/**
 * Prévient l'éditeur de ce qu'il doit examiner : un e-mail à son adresse
 * d'alerte (PILIQI_EDITEUR_EMAIL, voir config/editeur.php), et toujours
 * une ligne au journal de l'application. Une alerte sur une société ne la
 * désigne que par son identifiant et son SIREN ; une alerte de la
 * plateforme (la file d'attente qui ne part plus, par exemple) ne parle
 * d'aucune société. L'e-mail part sans file d'attente, par le serveur
 * d'envoi de la plateforme : quand c'est lui qui est en panne, il ne part
 * pas, et le journal le dit au niveau critique, sans arrêter le geste qui
 * alerte.
 */
class AlerteEditeur
{
    /**
     * @param  list<string>  $lignes
     */
    public function alerter(Societe $societe, string $objet, array $lignes): void
    {
        Log::warning("Alerte de l'éditeur : {$objet}", ['societe_id' => $societe->getKey(), 'siren' => $societe->siren]);

        $this->envoyer($objet, ['Société n° '.CleDuModele::de($societe).", SIREN {$societe->siren}.", ...$lignes]);
    }

    /**
     * @param  list<string>  $lignes
     */
    public function alerterDeLaPlateforme(string $objet, array $lignes): void
    {
        Log::warning("Alerte de l'éditeur : {$objet}");

        $this->envoyer($objet, $lignes);
    }

    /**
     * @param  list<string>  $lignes
     */
    private function envoyer(string $objet, array $lignes): void
    {
        $adresse = config('editeur.email');

        if (! is_string($adresse) || $adresse === '') {
            return;
        }

        try {
            Mail::to($adresse)->send(new AlerteEditeurMail($objet, $lignes));
        } catch (TransportExceptionInterface|UnsupportedSchemeException $erreur) {
            Log::critical("L'alerte de l'éditeur « {$objet} » n'est pas partie par e-mail : ".CauseDEchecDEnvoi::de($erreur)->libelle().'.');
        }
    }
}
