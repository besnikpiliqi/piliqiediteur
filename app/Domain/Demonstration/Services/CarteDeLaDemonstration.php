<?php

namespace App\Domain\Demonstration\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Enums\CauseDeLaFin;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ce que dit la carte « Ma démonstration » (B.2), sur le tableau de bord
 * de l'espace de la société et sur celui de l'espace du cabinet, au seul
 * propriétaire, sur le site réel (voir ConditionsDeLaDemonstration) : son
 * état, ses phrases et ses boutons, avec les textes de la maquette
 * (version 3). Elle ne parle que du type de la société (choix 30) : aucun
 * texte ne nomme ni ne décrit un autre type ou sa démonstration.
 *
 * Elle ne dit jamais un état que le site de démonstration n'a pas
 * confirmé ; tant qu'une démonstration n'est pas prête, ou qu'un
 * renouvellement attend son accusé, son affichage demande l'état au site
 * de démonstration, au plus une fois par minute et par société.
 */
final class CarteDeLaDemonstration
{
    public const ADRESSE_A_CONFIRMER = 'adresse_a_confirmer';

    public const A_CREER = 'a_creer';

    public const EN_PREPARATION = 'en_preparation';

    public const EN_ATTENTE_D_UNE_PLACE = 'en_attente_d_une_place';

    public const PRETE = 'prete';

    public const EN_ECHEC = 'en_echec';

    public const SITE_ABSENT = 'site_absent';

    public const PHRASE_DU_JOURNAL = 'Chaque création, renouvellement, ouverture et nouveau départ s\'inscrit au journal d\'audit de la société, sans jamais le lien.';

    public const PHRASE_EN_PREPARATION = 'Votre démonstration se prépare. Elle s\'ouvrira dès qu\'elle sera prête.';

    public const PHRASE_EN_ATTENTE = 'Votre démonstration sera prête dès qu\'une place se libère.';

    public const PHRASE_EN_ECHEC = 'Votre démonstration n\'a pas pu être créée. Nous réessayons ; l\'éditeur est prévenu.';

    public const PHRASE_COUPURE_EN_COURS = 'Coupure de l\'ancien lien en cours…';

    public const PHRASE_COUPURE_EN_RETARD = 'La démonstration n\'a pas encore reçu le renouvellement : l\'ancien lien marche peut-être encore. Nous réessayons ; l\'éditeur est prévenu.';

    public const PHRASE_ANCIEN_PROPRIETAIRE = 'La démonstration a été créée par l\'ancien propriétaire. Pour lui retirer l\'accès, renouvelez le lien.';

    /**
     * La dernière démonstration lue de chaque société par cette instance.
     *
     * @var array<int, Demonstration|null>
     */
    private array $dernieres = [];

    public function __construct(
        private readonly ConditionsDeLaDemonstration $conditions,
        private readonly SiteDeDemonstration $site,
        private readonly BoiteDEnvoi $boite,
        private readonly TenantContext $contexte,
    ) {}

    public const ETAPE = 'Ouvrir ma démonstration';

    /**
     * L'étape « Ouvrir ma démonstration » de la liste des premières étapes
     * de la société et de celle du cabinet (B.14), pour qui voit la carte :
     * cochée quand une démonstration existe ou a existé, son lien menant à
     * la carte.
     *
     * @return array{titre: string, faite: bool, etat: string, url: string|null}|null
     */
    public function etape(?User $compte, Societe $societe, string $tableauDeBord): ?array
    {
        if ($compte === null || ! $this->conditions->voitLaCarte($compte, $societe)) {
            return null;
        }

        $faite = $this->derniere($societe) !== null;

        return [
            'titre' => self::ETAPE,
            'faite' => $faite,
            'etat' => match (true) {
                $faite => 'Votre démonstration est créée.',
                $societe->estUnCabinet() => 'Essayez un cabinet fictif, sans rien de votre vrai cabinet.',
                default => 'Essayez une société fictive de votre activité, sans rien de votre vraie société.',
            },
            'url' => $tableauDeBord.'#ma-demonstration',
        ];
    }

    /**
     * La phrase « À créer », selon le type de la société.
     */
    public static function phraseACreer(TypeDActivite $type): string
    {
        return match ($type) {
            TypeDActivite::Commerce => 'Une société fictive, déjà remplie : plusieurs magasins, toute l\'équipe, treize mois de ventes, d\'achats et de clôtures. Rien de votre vraie société n\'y entre.',
            TypeDActivite::Services => 'Une société fictive, déjà remplie : un magasin, toute l\'équipe, treize mois de ventes et de clôtures. Rien de votre vraie société n\'y entre.',
            TypeDActivite::Cabinet => 'Un cabinet fictif, déjà rempli : ses collaborateurs, ses clients et ce qu\'ils vous autorisent. Rien de votre vrai cabinet n\'y entre.',
        };
    }

    /**
     * La phrase « Rien de … n'y entre » des fenêtres, selon le type.
     */
    public static function rienNEntre(TypeDActivite $type): string
    {
        return $type === TypeDActivite::Cabinet ? 'Rien de votre vrai cabinet n\'y entre.' : 'Rien de votre vraie société n\'y entre.';
    }

    /**
     * La carte pour ce compte et cette société, ou null quand il ne la
     * voit pas.
     *
     * @return array{etat: string, phrases: list<string>, notes: list<string>, repere: string|null, peut_creer: bool, peut_ouvrir: bool, peut_renouveler: bool, peut_recommencer: bool, a_suivre: bool}|null
     */
    public function pour(?User $compte, Societe $societe): ?array
    {
        if ($compte === null || ! $this->conditions->voitLaCarte($compte, $societe)) {
            return null;
        }

        return $this->contexte->runAsSociete($societe, function () use ($compte, $societe): array {
            $derniere = $this->derniere($societe);
            $vivante = $derniere?->etat->estVivante() === true ? $derniere : null;
            $calendrier = Calendrier::deParis();
            $notes = [];

            if (! $this->site->estLance()) {
                return $this->carte(self::SITE_ABSENT, [ConditionsDeLaDemonstration::MESSAGE_SITE_NON_LANCE], [], null);
            }

            if ($vivante === null) {
                if ($derniere?->terminee_le !== null) {
                    $notes[] = $derniere->cause_de_la_fin === CauseDeLaFin::Echec
                        ? 'Votre démonstration n\'a pas pu être créée le '.$calendrier->afficher($derniere->terminee_le, 'd/m/Y').'. Vous pouvez la redemander.'
                        : 'Votre démonstration précédente s\'est terminée le '.$calendrier->afficher($derniere->terminee_le, 'd/m/Y').'.';
                }

                if (! $compte->aUneAdresseDeConnexion()) {
                    return $this->carte(self::ADRESSE_A_CONFIRMER, [ConditionsDeLaDemonstration::MESSAGE_ADRESSE_A_CONFIRMER], $notes, null);
                }

                $peutCreer = $this->conditions->peutCreerOuRecommencer($societe);

                return $this->carte(self::A_CREER, [self::phraseACreer($societe->type_activite), ...($peutCreer ? [] : [ConditionsDeLaDemonstration::messageDeSuspension($societe)])], $notes, null, peutCreer: $peutCreer);
            }

            $this->demanderLEtatSiBesoin($vivante, $societe);

            if ((int) $vivante->lien_tire_par !== (int) $compte->getKey()) {
                $notes[] = self::PHRASE_ANCIEN_PROPRIETAIRE;
            }

            return match ($vivante->etat) {
                EtatDeLaDemonstration::Demandee, EtatDeLaDemonstration::EnPreparation => $this->carte(self::EN_PREPARATION, [self::PHRASE_EN_PREPARATION], $notes, $vivante->repere, peutOuvrir: true, aSuivre: true),
                EtatDeLaDemonstration::EnAttenteDUnePlace => $this->carte(self::EN_ATTENTE_D_UNE_PLACE, [self::PHRASE_EN_ATTENTE], $notes, $vivante->repere, peutOuvrir: true, aSuivre: true),
                EtatDeLaDemonstration::EnEchec => $this->carte(self::EN_ECHEC, [self::PHRASE_EN_ECHEC], $notes, $vivante->repere, aSuivre: true),
                default => $this->carteDUnePrete($vivante, $societe, $compte, $notes),
            };
        });
    }

    /**
     * La démonstration la plus récente de la société, vivante d'abord, lue
     * une seule fois par instance du service.
     */
    private function derniere(Societe $societe): ?Demonstration
    {
        $cle = (int) $societe->getKey();

        if (! array_key_exists($cle, $this->dernieres)) {
            $this->dernieres[$cle] = $this->contexte->runAsSociete($societe, fn (): ?Demonstration => Demonstration::query()
                ->orderByRaw("case when etat in ('demandee', 'en_preparation', 'en_attente_d_une_place', 'prete', 'en_echec') then 0 else 1 end")
                ->orderByDesc('terminee_le')
                ->orderByDesc('id')
                ->first());
        }

        return $this->dernieres[$cle];
    }

    /**
     * @param  list<string>  $notes
     * @return array{etat: string, phrases: list<string>, notes: list<string>, repere: string|null, peut_creer: bool, peut_ouvrir: bool, peut_renouveler: bool, peut_recommencer: bool, a_suivre: bool}
     */
    private function carteDUnePrete(Demonstration $demonstration, Societe $societe, User $compte, array $notes): array
    {
        $calendrier = Calendrier::deParis();
        $jours = max(0, (int) ceil(now()->diffInSeconds($demonstration->fin_le, false) / 86400));
        $phrases = ['Supprimée le '.$calendrier->afficher($demonstration->fin_le, 'd/m/Y').', dans '.$jours.' '.($jours > 1 ? 'jours' : 'jour').'. Vous pouvez donner le lien à votre équipe. Repère de votre démonstration : '.$demonstration->repere.'.'];

        if ($demonstration->renouvellementSansAccuse()) {
            $notes[] = $demonstration->lien_tire_le->addMinutes(15)->isPast() ? self::PHRASE_COUPURE_EN_RETARD : self::PHRASE_COUPURE_EN_COURS;
        } elseif ($demonstration->generation_du_lien > 1 && $demonstration->confirmee_le !== null) {
            $notes[] = 'Ancien lien coupé le '.$calendrier->afficher($demonstration->confirmee_le, 'd/m/Y à H:i').'.';
        }

        if ($demonstration->recommencee_le !== null) {
            $notes[] = 'Remise à zéro le '.$calendrier->afficher($demonstration->recommencee_le, 'd/m/Y à H:i').'.';
        }

        return $this->carte(
            self::PRETE,
            $phrases,
            $notes,
            $demonstration->repere,
            peutOuvrir: true,
            peutRenouveler: $compte->aUneAdresseDeConnexion(),
            peutRecommencer: $this->conditions->peutCreerOuRecommencer($societe),
            aSuivre: $demonstration->renouvellementSansAccuse(),
        );
    }

    /**
     * L'affichage demande l'état au site de démonstration, au plus une fois
     * par minute et par société, tant que la démonstration n'est pas prête
     * ou qu'un renouvellement attend son accusé.
     */
    private function demanderLEtatSiBesoin(Demonstration $demonstration, Societe $societe): void
    {
        if (($demonstration->etat === EtatDeLaDemonstration::Prete && ! $demonstration->renouvellementSansAccuse())
            || ! Cache::add('demonstration-etat-demande:'.$societe->getKey(), true, 60)) {
            return;
        }

        DB::transaction(fn () => $this->boite->demanderLEtat($demonstration));
    }

    /**
     * @param  list<string>  $phrases
     * @param  list<string>  $notes
     * @return array{etat: string, phrases: list<string>, notes: list<string>, repere: string|null, peut_creer: bool, peut_ouvrir: bool, peut_renouveler: bool, peut_recommencer: bool, a_suivre: bool}
     */
    private function carte(string $etat, array $phrases, array $notes, ?string $repere, bool $peutCreer = false, bool $peutOuvrir = false, bool $peutRenouveler = false, bool $peutRecommencer = false, bool $aSuivre = false): array
    {
        return [
            'etat' => $etat,
            'phrases' => $phrases,
            'notes' => $notes,
            'repere' => $repere,
            'peut_creer' => $peutCreer,
            'peut_ouvrir' => $peutOuvrir,
            'peut_renouveler' => $peutRenouveler,
            'peut_recommencer' => $peutRecommencer,
            'a_suivre' => $aSuivre,
        ];
    }
}
