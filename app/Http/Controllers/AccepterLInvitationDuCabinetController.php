<?php

namespace App\Http\Controllers;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Exceptions\InvitationInvalideException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Support\AvertissementsDuCodeDActivite;
use App\Filament\EspaceDeLaSociete;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le lien de l'invitation qu'un cabinet d'expertise comptable vérifié
 * envoie à une entreprise pas encore inscrite (voir
 * InviterUneEntrepriseAction) :
 * - ouvrir le lien (GET) n'écrit rien : la page montre le cabinet qui
 *   invite (sa dénomination, sa ville), le SIREN et la dénomination de
 *   l'entreprise au répertoire Sirene, lu à ce moment, les autorisations
 *   que le cabinet recevra par défaut, ce que fait l'acceptation, puis le
 *   formulaire : le mot de passe, le menu « Votre activité » sans le type
 *   « Cabinet d'expertise comptable », sans valeur par défaut, avec les
 *   avertissements du code d'activité lu (voir
 *   AvertissementsDuCodeDActivite::pourUneInvitation()), et la case du
 *   choix définitif ; à part, le bouton qui refuse l'invitation ;
 * - accepter (POST) crée et valide la société (voir
 *   AccepterLInvitationAction), connecte son propriétaire et le mène à
 *   l'accueil de l'espace de sa société (voir EspaceDeLaSociete), jamais un
 *   cabinet ; une erreur revient au formulaire, avec
 *   le type déjà choisi ;
 * - refuser (POST) refuse l'invitation (voir RefuserLInvitationAction).
 * Une invitation absente, close ou périmée donne une page neutre ; une
 * entreprise cessée ou inconnue au répertoire, ou un répertoire qui ne
 * répond pas, une page sans formulaire. Les pages ne transmettent pas leur
 * adresse aux liens qui en partent (Referrer-Policy: no-referrer).
 */
class AccepterLInvitationDuCabinetController
{
    public const CASE_DU_CHOIX_DEFINITIF = 'J\'ai compris que ce type d\'activité ne pourra plus changer.';

    public const MESSAGE_REFUSEE = 'Vous avez refusé l\'invitation. Aucune société n\'a été créée.';

    public function __construct(private readonly ControleSirene $controleSirene) {}

    public function afficher(string $jeton): Response
    {
        return $this->page($jeton, InvitationCabinet::parSonJeton($jeton), null, [], null);
    }

    public function accepter(Request $requete, string $jeton, AccepterLInvitationAction $accepter, SuiviDesConnexions $suivi): Response|RedirectResponse
    {
        $type = TypeDActivite::tryFrom((string) $requete->input('type_activite'));

        try {
            $proprietaire = $accepter->handle($jeton, [
                'password' => (string) $requete->input('password'),
                'password_confirmation' => (string) $requete->input('password_confirmation'),
                'type_activite' => (string) $requete->input('type_activite'),
                'choix_definitif' => $requete->boolean('choix_definitif'),
            ]);
        } catch (InvitationInvalideException) {
            return $this->invalide();
        } catch (ValidationException $refus) {
            return $this->page($jeton, InvitationCabinet::parSonJeton($jeton), null, array_values($refus->validator->errors()->all()), $type);
        } catch (LogicException $refus) {
            return $this->page($jeton, InvitationCabinet::parSonJeton($jeton), null, [$refus->getMessage()], $type);
        }

        Auth::login($proprietaire);
        $requete->session()->regenerate();
        $suivi->noterLaConnexion($proprietaire);

        return redirect()->to(EspaceDeLaSociete::accueil(Societe::query()->findOrFail($proprietaire->societe_id)))
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function refuser(string $jeton, RefuserLInvitationAction $refuser): Response
    {
        try {
            $refuser->handle($jeton);
        } catch (InvitationInvalideException) {
            return $this->invalide();
        }

        return response()->view('cabinets.invitation-refusee', ['message' => self::MESSAGE_REFUSEE])->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * La page d'une invitation ouverte, après la lecture du répertoire
     * Sirene : le formulaire, ou, pour une entreprise cessée, inconnue ou
     * un répertoire injoignable, le seul motif.
     *
     * @param  list<string>  $erreurs
     */
    private function page(string $jeton, ?InvitationCabinet $invitation, ?string $motif, array $erreurs, ?TypeDActivite $type): Response
    {
        if ($invitation === null || ! $invitation->estOuverte()) {
            return $this->invalide();
        }

        $cabinet = Societe::query()->whereKey($invitation->societe_id)->firstOrFail(['id', 'raison_sociale', 'siege_ville']);
        $consultation = $this->controleSirene->unite((string) $invitation->siren);
        $unite = $consultation['unite'];
        $motif ??= match (true) {
            $consultation['resultat'] === ResultatVerification::Indisponible => AccepterLInvitationAction::MESSAGE_REPERTOIRE_INDISPONIBLE,
            $consultation['resultat'] !== ResultatVerification::Concluante || $unite === null => AccepterLInvitationAction::MESSAGE_ENTREPRISE_INACTIVE,
            default => null,
        };

        return response()->view('cabinets.invitation', [
            'cabinet' => $cabinet,
            'siren' => $invitation->siren,
            'denomination' => $unite?->denomination,
            'motif' => $motif,
            'erreurs' => $erreurs,
            'types' => [TypeDActivite::Commerce, TypeDActivite::Services],
            'typeChoisi' => $type === TypeDActivite::Cabinet ? null : $type,
            'autorisationsCommunes' => self::libelles(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Services)),
            'autorisationsDesArticles' => self::libelles(array_diff(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce), PermissionRegistry::parDefautDuCabinet(TypeDActivite::Services))),
            'avertissements' => $unite === null ? [] : AvertissementsDuCodeDActivite::pourUneInvitation($unite->activitePrincipale, $unite->nomenclatureActivite),
            'expireLe' => $invitation->expire_le,
            'action' => route('cabinets.invitation.accepter', ['jeton' => $jeton]),
            'actionDuRefus' => route('cabinets.invitation.refuser', ['jeton' => $jeton]),
        ], $motif === null ? 200 : 422)->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Les libellés de ces autorisations, dans l'ordre du registre.
     *
     * @param  array<array-key, string>  $autorisations
     * @return list<string>
     */
    private static function libelles(array $autorisations): array
    {
        return array_values(array_intersect_key(PermissionRegistry::libelles(), array_flip($autorisations)));
    }

    private function invalide(): Response
    {
        return response()->view('cabinets.invitation-invalide', ['message' => InvitationInvalideException::MESSAGE], 410)->header('Referrer-Policy', 'no-referrer');
    }
}
