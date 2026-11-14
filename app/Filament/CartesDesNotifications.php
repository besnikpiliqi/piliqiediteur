<?php

namespace App\Filament;

use App\Domain\Tenancy\Services\CartesAMontrer;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * La notification qui s'affiche toute seule (modèle A choisi par l'éditeur
 * le 14/11/2026) : dans les trois panneaux (magasin, espace de la société,
 * espace du cabinet), pour tout compte qui a la cloche, chaque
 * notification de la cloche arrivée pendant qu'une page de PILIQI est
 * ouverte glisse en carte depuis le bord droit, en haut à droite, sous la
 * cloche, qui bouge un instant. La carte porte l'icône, le titre, le texte
 * et les boutons de la notification, une croix et une fine barre du temps
 * qui reste ; elle reste 8 secondes, ou tant que la souris ou le clavier
 * est dessus (le temps repart quand ils la quittent), puis s'en va. Trois
 * cartes au plus s'empilent, la plus récente en haut.
 *
 * Les cartes ne montrent que ce que la cloche de ce panneau montre à cette
 * personne, par la même requête (voir DatabaseNotifications et
 * CartesAMontrer), regardée au même rythme qu'elle, sans service
 * extérieur (PILIQI_INTERVALLE_DES_NOTIFICATIONS, 30 secondes par défaut) :
 * au chargement de chaque page, puis à chaque intervalle, sans requête de
 * plus au chargement.
 *
 * La cloche et ses notifications ne changent en rien (précision de
 * l'éditeur du 14/11/2026) : la carte ne fait qu'avertir. Elle ne marque
 * rien comme lu, n'efface rien et n'écrit rien sur les notifications ;
 * fermer la carte, par sa croix ou à la fin de son temps, laisse la
 * notification non lue dans la cloche (la carte porte son propre
 * identifiant, jamais celui de la notification, que la croix d'une
 * notification de la cloche effacerait). Seul un bouton de la carte
 * (« Voir le devis »...) fait ce que fait le même bouton dans la cloche :
 * il marque la notification comme lue, par la cloche (voir
 * marquerCommeLue()), puis ouvre son adresse.
 */
final class CartesDesNotifications extends Component
{
    /**
     * Le nom du composant, enregistré par AppServiceProvider.
     */
    public const NOM = 'cartes-des-notifications';

    /**
     * Présente dans la session une fois ses premières cartes regardées :
     * avant, c'est l'ouverture de la session, qui ne montre au plus qu'une
     * carte.
     */
    public const SESSION_OUVERTE = 'piliqi.cartes_des_notifications.session_ouverte';

    /**
     * L'événement du navigateur qui porte les cartes d'un passage.
     */
    public const EVENEMENT = 'piliqi-cartes-des-notifications';

    /**
     * Combien de temps une carte reste, en millisecondes.
     */
    public const DUREE_EN_MS = 8000;

    /**
     * Les cartes du chargement de la page, en HTML, rendues avec elle.
     *
     * @var list<string>
     */
    protected array $cartesDuChargement = [];

    public function mount(): void
    {
        $this->cartesDuChargement = $this->cartes();
    }

    /**
     * Le passage de chaque intervalle : envoie au navigateur les cartes des
     * notifications arrivées depuis.
     */
    public function regarder(): void
    {
        $cartes = $this->cartes();

        if ($cartes !== []) {
            $this->dispatch(self::EVENEMENT, cartes: $cartes);
        }
    }

    /**
     * Le bouton d'une carte qui marque sa notification comme lue : ce que
     * fait le même bouton dans la cloche, par la cloche, qui ne touche
     * qu'une notification de ce compte.
     */
    public function marquerCommeLue(string $notification): void
    {
        if (Str::isUuid($notification)) {
            (new DatabaseNotifications)->markNotificationAsRead($notification);
        }
    }

    /**
     * Le rythme auquel la cloche et les cartes regardent s'il y a du
     * nouveau, au format de wire:poll (« 30s »), une seconde au moins.
     */
    public static function intervalle(): string
    {
        return max(1, Reglage::entier('piliqi.intervalle_des_notifications_en_secondes')).'s';
    }

    /**
     * Les cartes du chargement, la plus récente en haut de la pile.
     */
    public function render(): View
    {
        return view('filament.cartes-des-notifications', ['cartes' => array_reverse($this->cartesDuChargement)]);
    }

    /**
     * Les cartes de ce passage, en HTML, de la plus ancienne à la plus
     * récente, dans l'ordre où elles s'empilent.
     *
     * @return list<string>
     */
    private function cartes(): array
    {
        $ouvertureDeSession = ! session()->has(self::SESSION_OUVERTE);
        $notifications = app(CartesAMontrer::class)->prendre(
            CompteConnecte::exiger(),
            (new DatabaseNotifications)->getUnreadNotificationsQuery(),
            $ouvertureDeSession,
            CarbonImmutable::now(),
        );
        session()->put(self::SESSION_OUVERTE, true);

        return array_values($notifications->reverse()->map(fn (DatabaseNotification $notification): string => view('filament.carte-de-notification', [
            'carte' => (string) Str::uuid(),
            'notification' => ValeursLues::texte($notification->getKey()),
            'contenu' => Notification::fromDatabase($notification),
            'duree' => self::DUREE_EN_MS,
        ])->render())->all());
    }
}
