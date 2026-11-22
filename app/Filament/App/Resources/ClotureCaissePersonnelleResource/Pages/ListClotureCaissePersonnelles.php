<?php

namespace App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Widgets\CaissesOuvertesDeLEquipeApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * « Ma caisse » ou « Les caisses » selon la personne (voir
 * ClotureCaissePersonnelleResource::libelle()) ; en tête de « Les
 * caisses », les caisses encore ouvertes (voir
 * CaissesOuvertesDeLEquipeApercu).
 *
 * « Fermer ma caisse » : quiconque vend ou encaisse ferme la sienne, comme
 * qui y a encore sa caisse ouverte sans plus y être autorisé à vendre (voir
 * ClotureCaissePersonnellePolicy::fermer()), et voit son relevé avant de le
 * fermer (voir FermerSaCaisseAction::apercu()).
 * « Fermer la caisse d'un vendeur » : qui peut clôturer la journée ferme
 * celle d'un autre membre dont la caisse est encore ouverte, pour lui
 * (voir ClotureCaissePersonnellePolicy::fermerLaCaisseDe()) ; son relevé
 * porte « Fermée par » et son nom. Ce relevé reste aussi confidentiel que
 * dans la liste (voir ClotureCaissePersonnelleResource::getEloquentQuery()) :
 * seul qui voit les relevés de toute l'équipe (cloture_caisse.view_toutes)
 * le voit avant de fermer ; tout autre responsable ferme la caisse sans en
 * voir aucun montant. Chaque fenêtre porte le jeton de sa demande (R3,
 * voir PorteUnJeton) : une fermeture renvoyée après une réponse perdue
 * rend le relevé déjà fermé.
 */
class ListClotureCaissePersonnelles extends ListRecords
{
    use GereLesExceptionsMetier, PrendLeCalendrierDuMagasin;
    use PorteUnJeton;

    protected static string $resource = ClotureCaissePersonnelleResource::class;

    /**
     * Ce que dit l'info-bulle de « Fermer la caisse d'un vendeur » quand
     * aucun autre vendeur n'a de caisse ouverte (texte de l'éditeur,
     * 18/11/2026).
     */
    private const AUCUNE_CAISSE_DE_VENDEUR = 'Aucune caisse de vendeur à fermer : aucune vente ni encaissement.';

    private ?bool $caisseOuverte = null;

    private ?bool $caissesDeVendeurs = null;

    public function getTitle(): string
    {
        return ClotureCaissePersonnelleResource::libelle();
    }

    protected function getHeaderWidgets(): array
    {
        return [CaissesOuvertesDeLEquipeApercu::class];
    }

    protected function getHeaderActions(): array
    {
        return [$this->fermerMaCaisseAction(), $this->fermerLaCaisseDUnVendeurAction()];
    }

    /**
     * « Fermer ma caisse », grisé, avec son info-bulle, tant que la caisse
     * n'a ni vente ni encaissement à compter (voir
     * FermerSaCaisseAction::MESSAGE_RIEN_A_FERMER) : une caisse s'ouvre à la
     * prochaine vente (décision de l'éditeur du 18/11/2026). Le bouton
     * grisé reste cliquable pour l'oeil, sans fenêtre : sa demande, que le
     * domaine refuserait de toute façon, ne fait que rappeler le refus,
     * comme sur un téléphone, où l'info-bulle ne s'ouvre qu'au toucher.
     */
    private function fermerMaCaisseAction(): Actions\Action
    {
        return Actions\Action::make('fermer_ma_caisse')
            ->label('Fermer ma caisse')
            ->icon('heroicon-o-lock-closed')
            ->color(fn (): string => $this->saCaisseEstOuverte() ? 'primary' : 'gray')
            ->extraAttributes(fn (): array => self::attributsDUnBoutonGrise($this->saCaisseEstOuverte(), FermerSaCaisseAction::MESSAGE_RIEN_A_FERMER))
            ->tooltip(fn (): ?string => $this->saCaisseEstOuverte() ? null : FermerSaCaisseAction::MESSAGE_RIEN_A_FERMER)
            ->requiresConfirmation()
            ->modalHidden(fn (): bool => ! $this->saCaisseEstOuverte())
            ->modalDescription(fn (): HtmlString => self::recapitulatif(app(FermerSaCaisseAction::class)->apercu(CompteConnecte::exiger()), 'votre'))
            ->form(fn (): array => $this->saCaisseEstOuverte() ? [self::champDuJeton()] : [])
            ->visible(fn () => CompteConnecte::exiger()->can('fermer', ClotureCaissePersonnelle::class))
            ->action(function (array $data): void {
                static::executerSansCasser(fn () => app(FermerSaCaisseAction::class)->handle(CompteConnecte::exiger(), CompteConnecte::exiger(), ValeurSimple::enTexteOuNul($data['jeton'] ?? null)));
                $this->caisseOuverte = null;
            });
    }

    /**
     * « Fermer la caisse d'un vendeur », grisé de même, avec son info-bulle,
     * quand aucun autre vendeur du magasin n'a de caisse ouverte.
     */
    private function fermerLaCaisseDUnVendeurAction(): Actions\Action
    {
        return Actions\Action::make('fermer_la_caisse_d_un_vendeur')
            ->label('Fermer la caisse d\'un vendeur')
            ->icon('heroicon-o-user-minus')
            ->color('gray')
            ->extraAttributes(fn (): array => self::attributsDUnBoutonGrise($this->uneCaisseDeVendeurEstOuverte(), self::AUCUNE_CAISSE_DE_VENDEUR))
            ->tooltip(fn (): ?string => $this->uneCaisseDeVendeurEstOuverte() ? null : self::AUCUNE_CAISSE_DE_VENDEUR)
            ->modalHidden(fn (): bool => ! $this->uneCaisseDeVendeurEstOuverte())
            ->modalHeading('Fermer la caisse d\'un vendeur')
            ->modalSubmitActionLabel('Fermer sa caisse')
            ->form(fn (): array => $this->uneCaisseDeVendeurEstOuverte() ? [
                self::champDuJeton(),
                Select::make('vendeur_id')
                    ->label('Vendeur')
                    ->options(fn (): array => self::vendeursAvecCaisseOuverte()->pluck('name', 'id')->all())
                    ->required()
                    ->live(),
                Placeholder::make('releve')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => self::vendeurChoisi($get('vendeur_id')) !== null)
                    ->content(fn (Get $get): HtmlString|string => CompteConnecte::exiger()->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)
                        ? self::recapitulatif(app(FermerSaCaisseAction::class)->apercu(ValeurRequise::de(self::vendeurChoisi($get('vendeur_id')), 'le vendeur choisi, dont la caisse est ouverte')), 'sa')
                        : 'Sa caisse sera fermée jusqu\'à maintenant. Son relevé ne vous sera pas montré : seuls le vendeur et qui voit les relevés de toute l\'équipe le consultent.'),
            ] : [])
            ->visible(fn (): bool => CompteConnecte::exiger()->can(PermissionRegistry::CLOTURE_CAISSE_GERER))
            ->action(function (array $data): void {
                $vendeur = self::vendeurChoisi($data['vendeur_id'] ?? null);

                if ($vendeur === null) {
                    Notification::make()->title($this->uneCaisseDeVendeurEstOuverte() ? 'La caisse de ce vendeur n\'est plus ouverte.' : self::AUCUNE_CAISSE_DE_VENDEUR)->warning()->send();

                    return;
                }

                static::executerSansCasser(function () use ($vendeur, $data): void {
                    $releve = app(FermerSaCaisseAction::class)->handle(CompteConnecte::exiger(), $vendeur, ValeurSimple::enTexteOuNul($data['jeton'] ?? null));

                    Notification::make()->title("Caisse de {$releve->vendeur_nom} fermée")->success()->send();
                });
                $this->caissesDeVendeurs = null;
            });
    }

    /**
     * La caisse personnelle de la personne connectée a-t-elle une vente
     * émise, un encaissement ou une contre-passation qu'aucune fermeture n'a
     * comptés (voir VerificateurCaissesPersonnelles::aSaCaisseOuverte()) ?
     * Lu une fois par requête, relu après une fermeture.
     */
    private function saCaisseEstOuverte(): bool
    {
        return $this->caisseOuverte ??= app(VerificateurCaissesPersonnelles::class)->aSaCaisseOuverte(CompteConnecte::exiger());
    }

    /**
     * Un autre vendeur du magasin a-t-il sa caisse ouverte ? Lu une fois par
     * requête, relu après une fermeture.
     */
    private function uneCaisseDeVendeurEstOuverte(): bool
    {
        return $this->caissesDeVendeurs ??= self::vendeursAvecCaisseOuverte()->isNotEmpty();
    }

    /**
     * Les attributs d'un bouton qui se montre grisé sans être désactivé :
     * un bouton désactivé ne reçoit ni le survol ni le toucher, donc
     * aucune info-bulle. Il reste annoncé comme indisponible aux lecteurs
     * d'écran (aria-disabled), avec son texte pour nom et la raison pour
     * description (aria-description).
     *
     * @return array<string, string>
     */
    private static function attributsDUnBoutonGrise(bool $disponible, string $raison): array
    {
        return $disponible ? [] : [
            'aria-disabled' => 'true',
            'aria-description' => $raison,
            'class' => 'piliqi-action-grisee',
        ];
    }

    /**
     * Les autres membres dont la caisse est encore ouverte aujourd'hui :
     * les seuls qu'on peut choisir, et que l'on relit à l'enregistrement.
     *
     * @return Collection<int, User>
     */
    private static function vendeursAvecCaisseOuverte(): Collection
    {
        return app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(self::calendrierDuMagasin()->aujourdhui())
            ->reject(fn (User $vendeur): bool => $vendeur->is(auth()->user()))
            ->values();
    }

    private static function vendeurChoisi(mixed $vendeurId): ?User
    {
        return filled($vendeurId) ? self::vendeursAvecCaisseOuverte()->firstWhere('id', ValeurSimple::enEntier($vendeurId)) : null;
    }

    /**
     * Les quatre rubriques du relevé avant de le fermer (B18, voir
     * ClotureCaissePersonnelle) : le TTC vendu, les encaissements par mode
     * de paiement, le crédit d'avoir utilisé et le reste dû, puis ce que
     * couvre le relevé.
     *
     * @param  'votre'|'sa'  $possessif  « votre » quand on ferme sa propre caisse, « sa » celle d'un vendeur
     */
    private static function recapitulatif(ClotureCaissePersonnelle $releve, string $possessif): HtmlString
    {
        $calculateur = app(CalculateurLigne::class);
        $euros = fn (string $montant): string => $calculateur->enEuros($calculateur->enCentiemes($montant));
        $ses = $possessif === 'votre' ? 'vos' : 'ses';
        $lignes = [
            e('TTC vendu : '.$euros($releve->total_ttc).' ('.$releve->nombre_ventes.($releve->nombre_ventes > 1 ? ' ventes' : ' vente').')'),
            e('Encaissé : ').ClotureCaissePersonnelleResource::encaisseParMode($releve)->toHtml(),
            e('Crédit d\'avoir utilisé : '.$euros($releve->credit_avoir)),
            e('Reste dû : '.$euros($releve->reste_du)),
            e("Ce relevé compte {$ses} ventes et {$ses} paiements qu'aucune fermeture n'a encore comptés, y compris ceux d'un jour précédent. Il part de {$possessif} dernière fermeture de caisse (ou du début de la journée si c'est la première), ou de {$possessif} plus ancienne opération non comptée si elle est antérieure. Ceci ne clôture pas la journée du magasin."),
        ];

        return new HtmlString(implode('<br>', $lignes));
    }
}
