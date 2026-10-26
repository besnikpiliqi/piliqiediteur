<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\CaissesPersonnellesOuvertesApercu;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\VentesEnAttenteApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use App\Models\User;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * Le magasin clôture lui-même sa journée en cours, le soir après sa
 * dernière vente, par le bouton « Clôturer la journée du 09/10/2026 » ;
 * une journée oubliée avant elle est clôturée d'abord, dans l'ordre (voir
 * CloturerLesJourneesAction). Sans ce clic, la clôture automatique de
 * minuit clôture la journée (voir ClotureAutomatiqueCaisse).
 *
 * Jamais une date choisie : la journée visée est celle du jour où la page
 * s'est ouverte, gardée telle quelle par la page ($journee) ; une fenêtre
 * de confirmation ouverte avant minuit et validée après ne clôture donc
 * jamais la journée qui commence.
 *
 * Une caisse personnelle restée ouverte ne bloque jamais la clôture (R5) :
 * la fenêtre nomme les caisses qu'elle fermera d'office, et elles se
 * ferment après la clôture, avec la mention « Fermée automatiquement »
 * (voir FermerLesCaissesPersonnellesAction).
 *
 * La fenêtre porte le jeton de sa demande (R3, voir PorteUnJeton) : une
 * clôture renvoyée après une réponse perdue rend la clôture déjà faite.
 */
class ListClotureCaisses extends ListRecords
{
    use GereLesExceptionsMetier, PrendLeCalendrierDuMagasin;
    use PorteUnJeton;

    protected static string $resource = ClotureCaisseResource::class;

    /**
     * La journée en cours à l'ouverture de la page (AAAA-MM-JJ), que vise
     * le bouton de clôture.
     */
    #[Locked]
    public string $journee = '';

    /**
     * Les journées à clôturer et les vendeurs dont la caisse personnelle
     * sera fermée d'office, lus une fois par requête : une propriété privée
     * ne survit pas d'une requête Livewire à l'autre.
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $journeesEnAttente = null;

    /**
     * @var Collection<int, User>|null
     */
    private ?Collection $vendeursEnAttente = null;

    public function mount(): void
    {
        parent::mount();

        $this->journee = self::calendrierDuMagasin()->aujourdhui();
    }

    /**
     * Visible pour qui peut clôturer (le propriétaire, les responsables du
     * magasin, et qui en a reçu l'autorisation), une fois la première vente du
     * magasin faite, tant que la journée n'est pas clôturée. La fenêtre de
     * confirmation annonce chaque journée et ce qu'elle regroupe, nomme les
     * caisses personnelles encore ouvertes qu'elle fermera d'office, et
     * prévient qu'ensuite plus aucune vente n'est possible ce jour-là (voir
     * ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()).
     *
     * Jamais désactivé par une caisse personnelle : une fois les journées
     * clôturées au nom de la personne qui le presse, relue au moment de
     * chaque clôture, chaque caisse ouverte se ferme d'office jusqu'à
     * l'instant de la clôture, dans sa propre transaction ; un échec,
     * signalé au journal de l'application, laisse la clôture faite et la
     * caisse se ferme au plus tard à minuit (voir
     * CloturerLesJourneesAction::parUnePersonne() et
     * ClotureAutomatiqueCaisse).
     */
    protected function getHeaderActions(): array
    {
        $journeeVisee = $this->journee;
        $enClair = Calendrier::afficherJour($journeeVisee, 'd/m/Y');

        return [
            Actions\Action::make('cloturer')
                ->label("Clôturer la journée du {$enClair}")
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading("Clôturer la journée du {$enClair} ?")
                ->modalDescription(fn (): HtmlString => $this->descriptionDeLaConfirmation($this->journeesACloturer()))
                ->modalSubmitActionLabel('Oui, clôturer définitivement')
                ->form([self::champDuJeton()])
                ->visible(fn (): bool => auth()->user()->can('cloturer', ClotureCaisse::class)
                    && $this->journeesACloturer()->isNotEmpty())
                ->action(fn (array $data) => static::executerSansCasser(function () use ($journeeVisee, $enClair, $data): void {
                    ['clotures' => $clotures, 'caisses_fermees' => $caissesFermees] = app(CloturerLesJourneesAction::class)->parUnePersonne(auth()->user(), Filament::getTenant(), $journeeVisee, $data['jeton']);
                    $this->journeesEnAttente = $this->vendeursEnAttente = null;

                    if ($clotures->isEmpty()) {
                        Notification::make()->title("La journée du {$enClair} est déjà clôturée.")->warning()->send();

                        return;
                    }

                    Notification::make()
                        ->title($clotures->count() === 1
                            ? "Journée du {$enClair} clôturée"
                            : 'Journées du '.$clotures->map(fn (ClotureCaisse $cloture): string => Calendrier::afficherJour($cloture->date_cloture, 'd/m/Y'))->join(', ', ' et ').' clôturées')
                        ->body($caissesFermees->isEmpty()
                            ? null
                            : 'Caisses personnelles fermées automatiquement : '.$caissesFermees->map(fn (ClotureCaissePersonnelle $releve): string => $releve->vendeur_nom)->implode(', ').'.')
                        ->success()
                        ->send();
                })),
        ];
    }

    /**
     * Les journées que le bouton clôturera, dans l'ordre (voir
     * CloturerLesJourneesAction::journeesEnAttente()).
     *
     * @return Collection<int, string>
     */
    private function journeesACloturer(): Collection
    {
        return $this->journeesEnAttente ??= app(CloturerLesJourneesAction::class)->journeesEnAttente($this->journee);
    }

    /**
     * Les vendeurs dont la caisse personnelle est encore ouverte au soir de
     * la journée visée : ceux dont la clôture fermera la caisse d'office.
     *
     * @return Collection<int, User>
     */
    private function vendeursAvecCaisseOuverte(): Collection
    {
        return $this->vendeursEnAttente ??= app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte($this->journee);
    }

    /**
     * Le magasin voit avant de confirmer exactement ce que chaque journée
     * regroupera (mêmes critères que CloturerCaisseAction) et les ventes
     * en cours à la caisse qu'elle abandonnera, pas seulement un
     * avertissement générique.
     *
     * @param  Collection<int, string>  $journees
     */
    private function descriptionDeLaConfirmation(Collection $journees): HtmlString
    {
        $paragraphes = [];

        if ($journees->count() > 1) {
            $paragraphes[] = 'Journées clôturées, dans l\'ordre : '.ClotureCaisseResource::libelleDesJournees($journees).'.';
        }

        foreach ($journees as $journee) {
            $enAttente = ClotureCaisseResource::enAttenteDeCloture(collect([$journee]));
            $total = number_format($enAttente['total_ttc'], 2, ',', ' ');

            $paragraphes[] = Calendrier::afficherJour($journee, 'd/m/Y').' : '.match (true) {
                $enAttente['ventes'] === 0 && $enAttente['avoirs'] === 0 => 'aucune vente au comptoir, la clôture sera créée à zéro.',
                $enAttente['avoirs'] === 0 => "{$enAttente['ventes']} vente(s) au comptoir pour un total de {$total} €.",
                default => "{$enAttente['ventes']} vente(s) au comptoir et {$enAttente['avoirs']} avoir(s) déduit(s), pour un total net de {$total} €.",
            };
        }

        $ventesOuvertes = app(PanierDeCaisse::class)->nombreDeVentesOuvertesLe($this->journee);

        if ($ventesOuvertes > 0) {
            $paragraphes[] = "{$ventesOuvertes} vente(s) en cours à la caisse, jamais encaissée(s), sera(ont) abandonnée(s) et inscrite(s) au journal des événements.";
        }

        if ($this->vendeursAvecCaisseOuverte()->isNotEmpty()) {
            $paragraphes[] = 'Caisses personnelles encore ouvertes, qui seront fermées automatiquement : '.$this->vendeursAvecCaisseOuverte()->pluck('name')->implode(', ').'.';
        }

        $paragraphes[] = 'Après la clôture, vous ne pourrez plus faire de ventes aujourd\'hui.';
        $paragraphes[] = 'Cette action est IRRÉVERSIBLE : une journée clôturée ne pourra plus jamais être modifiée, ni clôturée à nouveau.';

        return new HtmlString(collect($paragraphes)->map(fn (string $paragraphe): string => e($paragraphe))->implode('<br>'));
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VentesEnAttenteApercu::class,
            CaissesPersonnellesOuvertesApercu::class,
        ];
    }
}
