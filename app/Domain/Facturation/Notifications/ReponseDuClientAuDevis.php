<?php

namespace App\Domain\Facturation\Notifications;

use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\NotificationDuMagasin;
use App\Domain\Tenancy\Support\LigneDeCourriel;
use App\Domain\Tenancy\Support\Reglage;
use App\Filament\App\Resources\DevisResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Le client a répondu à un devis par son lien secret (voir
 * RepondreAuDevisEnLigneAction) : la personne qui a demandé l'envoi et le
 * propriétaire de la société le lisent dans la cloche et par e-mail (à
 * l'adresse de leur compte, quand il en a une). L'avis d'une acceptation
 * dit combien de produits du devis n'ont plus assez de stock, et
 * lesquels : le devis ne réserve rien, l'émission de la facture contrôle
 * le stock. Il mène à la fiche du devis, où se fait la transformation en
 * facture.
 *
 * Le nom tapé et le motif viennent d'une page publique, sans compte : dans
 * la cloche, dont Filament interprète le corps en HTML, tout le texte est
 * échappé (voir contenu()) et s'y lit tel qu'il a été tapé, sans jamais
 * devenir un lien ni une mise en forme ; l'e-mail l'échappe de lui-même.
 * L'heure de la réponse est celle du magasin, nommée hors de Paris (voir
 * Calendrier::afficherHorsDuMagasin()) : la cloche et la boîte du
 * propriétaire réunissent les avis de tous ses magasins.
 */
final class ReponseDuClientAuDevis extends NotificationDuMagasin
{
    /**
     * @param  list<array{produit: string, demande: int, disponible: int}>  $stocksInsuffisants
     */
    public function __construct(
        Magasin $magasin,
        public readonly Devis $devis,
        public readonly ReponseDuDevis $reponse,
        public readonly array $stocksInsuffisants,
    ) {
        parent::__construct($magasin);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("{$this->titre()} · {$this->magasin->nom}")
            ->line(LigneDeCourriel::de($this->texte()));

        foreach ($this->lignesDuStock() as $ligne) {
            $message->line(LigneDeCourriel::de($ligne));
        }

        return $message
            ->action('Voir le devis', $this->adresseDuDevis())
            ->line('Ce message vous est envoyé pour le magasin '.$this->magasin->nom.' sur '.Reglage::texte('app.name').'.');
    }

    protected function contenu(): NotificationFilament
    {
        $notification = NotificationFilament::make()
            ->title($this->titre())
            ->body(e(implode(' ', [$this->texte(), ...$this->lignesDuStock()])))
            ->icon($this->reponse->reponse === ReponseAuDevis::Acceptation ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
            ->actions([
                Action::make('voir')
                    ->label('Voir le devis')
                    ->url($this->adresseDuDevis())
                    ->markAsRead(),
            ]);

        return match (true) {
            $this->reponse->reponse === ReponseAuDevis::Refus => $notification->danger(),
            $this->stocksInsuffisants !== [] => $notification->warning(),
            default => $notification->success(),
        };
    }

    private function titre(): string
    {
        return $this->reponse->reponse === ReponseAuDevis::Acceptation
            ? "Devis {$this->devis->numero} accepté en ligne"
            : "Devis {$this->devis->numero} refusé en ligne";
    }

    private function texte(): string
    {
        $quand = $this->magasin->calendrier()->afficherHorsDuMagasin($this->reponse->created_at, 'd/m/Y à H:i');

        if ($this->reponse->reponse === ReponseAuDevis::Acceptation) {
            return "Le client a accepté le devis {$this->devis->numero} le {$quand}, signé « {$this->reponse->signataire} » avec la mention « Bon pour accord ». Un compte autorisé à vendre peut maintenant le transformer en facture.";
        }

        return "Le client a refusé le devis {$this->devis->numero} le {$quand}".($this->reponse->motif === null ? '.' : ", avec ce motif : « {$this->reponse->motif} ».");
    }

    /**
     * @return list<string>
     */
    private function lignesDuStock(): array
    {
        if ($this->stocksInsuffisants === []) {
            return [];
        }

        $nombre = count($this->stocksInsuffisants);
        $lignes = [($nombre === 1 ? '1 produit n\'a' : "{$nombre} produits n'ont").' plus assez de stock :'];

        foreach ($this->stocksInsuffisants as $manque) {
            $lignes[] = "« {$manque['produit']} » : {$manque['demande']} demandé".($manque['demande'] > 1 ? 's' : '').", {$manque['disponible']} en stock.";
        }

        return $lignes;
    }

    private function adresseDuDevis(): string
    {
        return DevisResource::getUrl('view', ['record' => $this->devis], panel: 'app', tenant: $this->magasin);
    }
}
