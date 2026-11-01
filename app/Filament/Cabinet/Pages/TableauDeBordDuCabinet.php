<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Le tableau de bord d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet (voir CabinetPanelProvider), où arrive son propriétaire après la
 * confirmation de son inscription et à chaque connexion : en tête,
 * « Bonjour » et le rôle de la personne dans le cabinet (voir
 * User::libelleDuRoleDeSociete()) ; pour le propriétaire seul, tant que le
 * cabinet n'est pas vérifié, la liste de ses étapes, chacune avec son état
 * et l'écran qui la fait (voir etapes()) ; où en est sa vérification. Ni
 * magasin ni démonstration : un cabinet n'en a aucun.
 */
class TableauDeBordDuCabinet extends Dashboard
{
    public const ETAPE_CODE_DU_COURRIER = 'Saisir le code du courrier recommandé';

    public const ETAPE_ACTIVITE = 'Faire contrôler votre code d\'activité 69.20Z';

    public const ETAPE_ORDRE = 'Faire vérifier votre inscription au tableau de l\'Ordre';

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string $view = 'filament.cabinet.pages.tableau-de-bord-du-cabinet';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && $societe->estUnCabinet() && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->cabinet()->raison_sociale ?? 'Votre cabinet';
    }

    public function cabinet(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * L'état de la vérification du cabinet, tel que l'écran le dit.
     */
    public function etatDeLaVerification(): string
    {
        $cabinet = $this->cabinet();

        return match (true) {
            $cabinet->estClose() => 'L\'inscription de votre cabinet est close.',
            $cabinet->estUnCabinetVerifie() => 'Votre cabinet est validé, contrôlé 69.20Z et vérifié à l\'Ordre : il peut inviter et lier ses clients.',
            $cabinet->estValidee() => 'Votre cabinet est validé depuis le '.Calendrier::deParis()->afficher($cabinet->validee_le, 'd/m/Y').' : il invitera et liera ses clients une fois son code d\'activité contrôlé et son inscription à l\'Ordre vérifiée.',
            default => 'Votre cabinet attend le code de son courrier recommandé.',
        };
    }

    /**
     * Les étapes du propriétaire du cabinet, dans l'ordre : saisir le code
     * du courrier recommandé, qui valide le cabinet, faire contrôler son
     * code d'activité et faire vérifier son inscription au tableau de
     * l'Ordre. Chacune dit si elle est faite, où elle en est, et mène à
     * l'écran qui la fait. Aucune pour un collaborateur.
     *
     * @return list<array{titre: string, faite: bool, etat: string, url: string|null}>
     */
    public function etapes(): array
    {
        if (! auth()->user()->estProprietaire()) {
            return [];
        }

        $cabinet = $this->cabinet();
        $verification = $cabinet->cabinet()->first();
        $page = VerificationDuCabinet::getUrl();

        return [
            [
                'titre' => self::ETAPE_CODE_DU_COURRIER,
                'faite' => $cabinet->estValidee(),
                'etat' => $this->prochaineEtapeDuCourrier(),
                'url' => $page,
            ],
            [
                'titre' => self::ETAPE_ACTIVITE,
                'faite' => $verification?->aSonActiviteControlee() ?? false,
                'etat' => ($verification?->aSonActiviteControlee() ?? false)
                    ? 'Contrôlé : '.($verification->code_activite ?? 'code donné à l\'éditeur').'.'
                    : 'Le répertoire Sirene ne donne pas votre code dans la nomenclature attendue : l\'éditeur le contrôle avec votre inscription à l\'Ordre.',
                'url' => $page,
            ],
            [
                'titre' => self::ETAPE_ORDRE,
                'faite' => $verification?->estVerifieALOrdre() ?? false,
                'etat' => match (true) {
                    $verification === null => 'À demander.',
                    $verification->estVerifieALOrdre() => 'Vérifiée le '.Calendrier::deParis()->afficher($verification->ordre_verifie_le, 'd/m/Y').'.',
                    $verification->ordre_retire_le !== null => 'Radiation constatée par l\'éditeur le '.Calendrier::deParis()->afficher($verification->ordre_retire_le, 'd/m/Y').'.',
                    $verification->attendLEditeur() => 'Demandée le '.Calendrier::deParis()->afficher($verification->ordre_demande_le, 'd/m/Y').' : l\'éditeur la vérifie.',
                    $verification->ordre_refuse_le !== null => "Refusée : {$verification->ordre_motif_refus}",
                    default => 'Donnez votre n° d\'inscription au tableau et votre conseil régional.',
                },
                'url' => $page,
            ],
        ];
    }

    /**
     * Reste-t-il une étape à faire ? La liste ne se montre qu'au
     * propriétaire, tant qu'il en reste une.
     */
    public function resteDesEtapes(): bool
    {
        return collect($this->etapes())->contains(fn (array $etape): bool => ! $etape['faite']);
    }

    /**
     * Où en est le courrier, et ce qu'il reste à faire : saisir son code,
     * jusqu'à quand.
     */
    private function prochaineEtapeDuCourrier(): string
    {
        $cabinet = $this->cabinet();

        return match (true) {
            $cabinet->estValidee() => 'Validé le '.Calendrier::deParis()->afficher($cabinet->validee_le, 'd/m/Y').'.',
            $cabinet->estClose() => 'Inscription close.',
            default => 'Le courrier recommandé est parti au représentant légal de votre cabinet, à l\'adresse de son siège : saisissez son code avant le '.Calendrier::deParis()->afficher(app(CourriersDeVerification::class)->cloturePrevueLe($cabinet), 'd/m/Y').'.',
        };
    }
}
