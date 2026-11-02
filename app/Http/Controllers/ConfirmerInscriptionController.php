<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Support\AvertissementsDuCodeDActivite;
use App\Filament\EspaceDeLaSociete;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Le second temps de l'inscription d'une société (voir
 * InscriptionSociete) : le lien envoyé à l'adresse du futur propriétaire.
 *
 * Ouvrir le lien (GET) n'affiche qu'un petit formulaire et ne consomme
 * rien : un outil qui suit les liens d'un e-mail n'inscrit personne et ne
 * fait partir aucun courrier. Le formulaire rappelle le SIREN et le type
 * d'activité choisi à la demande, et montre le menu des types (voir
 * TypeDActivite), où le type peut encore changer sans nouvel e-mail, les
 * avertissements du code d'activité de l'entreprise, chacun avec les types
 * qu'il concerne (voir AvertissementsDuCodeDActivite), relus dans la
 * demande sans appeler l'INSEE, et une case obligatoire : « J'ai compris
 * que ce type d'activité ne pourra plus changer. ». Le bouton (POST)
 * envoie le type final, contrôlé parmi ceux du menu, et la case : sans
 * l'un ou l'autre, rien n'est consommé ni créé, et le formulaire dit
 * pourquoi. Il consomme ensuite la demande d'un seul
 * coup, en base (une mise à jour qui ne touche que la demande encore
 * valable et jamais servie) : deux confirmations simultanées n'en créent
 * qu'une, et un lien expiré, déjà servi ou inconnu dit seulement « Ce lien
 * n'est plus valable. ». La demande se cherche par l'empreinte de son
 * jeton, jamais par le jeton lui-même, qui n'est ni enregistré ni
 * journalisé ; les pages ne transmettent pas leur adresse aux liens qui en
 * partent (Referrer-Policy: no-referrer).
 *
 * Chaque confirmation fait partir un courrier recommandé, payé par
 * l'éditeur : au plus COURRIERS_PAR_JOUR_ET_PAR_ADRESSE_IP par jour depuis
 * une même adresse IP ; au-delà, la demande reste valable et la page dit
 * de réessayer plus tard. La consommation et la création de la société,
 * avec son courrier (voir CreerSocieteAction), se font dans la même
 * transaction : une création refusée (SIREN pris entre-temps, entreprise
 * cessée, cabinet d'expertise comptable hors du code 69.20Z, registre ou
 * prestataire qui ne répond pas, adresse prise par un compte...) laisse le
 * lien valable et dit pourquoi. La société naît avec le type confirmé,
 * définitif dès cet instant. Créée sans magasin,
 * la société attend le code de son courrier ; son propriétaire, dont le
 * lien suivi a confirmé l'adresse, est connecté et arrive à l'accueil de
 * l'espace de sa société, sur la liste de ses premières étapes, ou, pour
 * un cabinet d'expertise comptable, à celui de l'espace du cabinet, sur la
 * liste des siennes (voir EspaceDeLaSociete).
 */
class ConfirmerInscriptionController
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public const COURRIERS_PAR_JOUR_ET_PAR_ADRESSE_IP = 5;

    public const MESSAGE_TROP_DE_COURRIERS = 'Trop d\'inscriptions ont été confirmées depuis cette adresse aujourd\'hui : réessayez demain, ce lien reste valable jusqu\'à son expiration, ou contactez l\'éditeur.';

    public const MESSAGE_TYPE_A_CHOISIR = 'Choisissez le type d\'activité de votre société.';

    public const MESSAGE_CHOIX_DEFINITIF_A_CONFIRMER = 'Cochez la case qui confirme que ce type d\'activité ne pourra plus changer.';

    public const CASE_DU_CHOIX_DEFINITIF = 'J\'ai compris que ce type d\'activité ne pourra plus changer.';

    public function afficher(string $jeton): Response
    {
        return $this->page($jeton, self::demandeValable($jeton), null, null);
    }

    public function confirmer(Request $requete, string $jeton, CreerSocieteAction $creerSociete): Response|RedirectResponse
    {
        $demande = self::demandeValable($jeton);

        if ($demande === null) {
            return $this->page($jeton, null, null, null);
        }

        $type = TypeDActivite::tryFrom((string) $requete->input('type_activite'));

        if ($type === null) {
            return $this->page($jeton, $demande, self::MESSAGE_TYPE_A_CHOISIR, null);
        }

        if (! $requete->boolean('choix_definitif')) {
            return $this->page($jeton, $demande, self::MESSAGE_CHOIX_DEFINITIF_A_CONFIRMER, $type);
        }

        $cle = 'courriers-ip:'.sha1((string) $requete->ip());

        if (RateLimiter::tooManyAttempts($cle, self::COURRIERS_PAR_JOUR_ET_PAR_ADRESSE_IP)) {
            return $this->page($jeton, $demande, self::MESSAGE_TROP_DE_COURRIERS, $type);
        }

        try {
            $societe = DB::transaction(function () use ($jeton, $type, $creerSociete) {
                $consommee = DemandeInscription::query()
                    ->where('jeton_hache', DemandeInscription::empreinte($jeton))
                    ->whereNull('consommee_le')
                    ->where('expire_le', '>', now())
                    ->update(['consommee_le' => now()]);

                if ($consommee === 0) {
                    return null;
                }

                $demande = DemandeInscription::query()->where('jeton_hache', DemandeInscription::empreinte($jeton))->firstOrFail();
                $donnees = $demande->donnees;

                return $creerSociete->handle($donnees['siren'], $type, $demande->email, $donnees['mot_de_passe']);
            });
        } catch (ValidationException $refus) {
            return $this->page($jeton, $demande, collect($refus->errors())->flatten()->first(), $type);
        }

        if ($societe === null) {
            return $this->page($jeton, null, null, null);
        }

        RateLimiter::hit($cle, 86400);
        Auth::login($societe->proprietaire);
        request()->session()->regenerate();

        return redirect()->to(EspaceDeLaSociete::accueil($societe))
            ->header('Referrer-Policy', 'no-referrer');
    }

    private static function demandeValable(string $jeton): ?DemandeInscription
    {
        return DemandeInscription::query()
            ->where('jeton_hache', DemandeInscription::empreinte($jeton))
            ->whereNull('consommee_le')
            ->where('expire_le', '>', now())
            ->first();
    }

    /**
     * La page du lien : le formulaire d'une demande encore valable, avec le
     * type choisi (celui de la demande, ou celui que le formulaire vient
     * d'envoyer) et ce qui l'a refusé ; sinon, seulement que le lien n'est
     * plus valable.
     */
    private function page(string $jeton, ?DemandeInscription $demande, ?string $refus, ?TypeDActivite $type): Response
    {
        $donnees = $demande?->donnees;

        return response()
            ->view('inscription.confirmer', [
                'valable' => $demande !== null,
                'refus' => $refus,
                'action' => route('inscription.confirmer', ['jeton' => $jeton]),
                'siren' => $donnees['siren'] ?? null,
                'typeChoisi' => $type ?? TypeDActivite::tryFrom((string) ($donnees['type_activite'] ?? '')),
                'avertissements' => $donnees === null ? [] : AvertissementsDuCodeDActivite::pour($donnees['code_activite'] ?? null, $donnees['nomenclature_activite'] ?? null),
            ], $demande !== null ? 200 : 410)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
