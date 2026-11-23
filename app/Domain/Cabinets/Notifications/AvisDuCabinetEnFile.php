<?php

namespace App\Domain\Cabinets\Notifications;

use App\Domain\Tenancy\Models\Societe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un avis de l'espace des cabinets (voir AvisDuCabinet), mêmes textes et
 * mêmes données, qui part en file d'attente après le commit : Laravel met
 * en file une tâche par destinataire et par canal (cloche, e-mail), rejouée
 * seule, si bien qu'une messagerie en panne ne double jamais l'avis de la
 * cloche. Sert au rattachement de la déclaration d'un expert-comptable de
 * la société (voir RattacherUneDeclarationAction), que le système fait
 * souvent hors de toute requête ; tout autre avis du cabinet reste
 * synchrone. La tâche ne garde que la société du destinataire, relue à
 * l'envoi, et les textes de l'avis : un AvisDuCabinet, aux propriétés en
 * lecture seule, ne se reconstruit pas d'une tâche en file.
 */
class AvisDuCabinetEnFile extends Notification implements ShouldQueue
{
    use Queueable;

    public const INFORMATION = AvisDuCabinet::INFORMATION;

    public const SUCCES = AvisDuCabinet::SUCCES;

    public int $tries = 5;

    private int $societeId = 0;

    private string $titre = '';

    private string $texte = '';

    private ?string $url = null;

    private string $libelleDuLien = 'Voir';

    private string $nature = self::INFORMATION;

    private bool $parCourriel = false;

    private ?int $clientId = null;

    public function __construct(
        Societe $societe,
        string $titre,
        string $texte,
        ?string $url = null,
        string $libelleDuLien = 'Voir',
        string $nature = self::INFORMATION,
        bool $parCourriel = false,
        ?int $clientId = null,
    ) {
        $this->societeId = $societe->id;
        $this->titre = $titre;
        $this->texte = $texte;
        $this->url = $url;
        $this->libelleDuLien = $libelleDuLien;
        $this->nature = $nature;
        $this->parCourriel = $parCourriel;
        $this->clientId = $clientId;
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->parCourriel ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->avis()->toMail($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->avis()->toDatabase($notifiable);
    }

    private function avis(): AvisDuCabinet
    {
        return new AvisDuCabinet(Societe::query()->findOrFail($this->societeId), $this->titre, $this->texte, $this->url, $this->libelleDuLien, $this->nature, $this->parCourriel, $this->clientId);
    }
}
