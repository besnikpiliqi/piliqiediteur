<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Filament\Societe\Pages\TableauDeBord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le second temps de l'inscription d'une société (voir
 * InscriptionSociete) : le lien envoyé à l'adresse du futur propriétaire.
 *
 * Ouvrir le lien (GET) n'affiche qu'un bouton et ne consomme rien : un
 * outil qui suit les liens d'un e-mail n'inscrit personne. Le bouton
 * (POST) consomme la demande d'un seul coup, en base (une mise à jour qui
 * ne touche que la demande encore valable et jamais servie) : deux
 * confirmations simultanées n'en créent qu'une, et un lien expiré, déjà
 * servi ou inconnu dit seulement « Ce lien n'est plus valable. ». La
 * demande se cherche par l'empreinte de son jeton, jamais par le jeton
 * lui-même, qui n'est ni enregistré ni journalisé ; les pages ne
 * transmettent pas leur adresse aux liens qui en partent
 * (Referrer-Policy: no-referrer).
 *
 * La consommation et la création de la société (voir CreerSocieteAction)
 * se font dans la même transaction : une création refusée (le répertoire
 * Sirene ayant entre-temps déclaré l'entreprise cessée, l'adresse
 * entre-temps prise par un compte...) laisse le lien valable et dit
 * pourquoi. Créée sans magasin, la société attend sa vérification ; son
 * propriétaire, dont le lien suivi a confirmé l'adresse, est connecté et
 * arrive dans l'espace de sa société, sur la liste de ses premières étapes
 * (voir TableauDeBord).
 */
class ConfirmerInscriptionController
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable.';

    public function afficher(string $jeton): Response
    {
        return $this->page($jeton, self::demandeValable($jeton) !== null, null);
    }

    public function confirmer(string $jeton, CreerSocieteAction $creerSociete): Response|RedirectResponse
    {
        try {
            $societe = DB::transaction(function () use ($jeton, $creerSociete) {
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

                return $creerSociete->handle(
                    ['siren' => $donnees['siren']],
                    ['name' => $donnees['nom'], 'email' => $demande->email, 'password' => $donnees['mot_de_passe']],
                );
            });
        } catch (ValidationException $refus) {
            return $this->page($jeton, true, collect($refus->errors())->flatten()->first());
        }

        if ($societe === null) {
            return $this->page($jeton, false, null);
        }

        Auth::login($societe->proprietaire);
        request()->session()->regenerate();

        return redirect()->to(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))
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

    private function page(string $jeton, bool $valable, ?string $refus): Response
    {
        return response()
            ->view('inscription.confirmer', [
                'valable' => $valable,
                'refus' => $refus,
                'action' => route('inscription.confirmer', ['jeton' => $jeton]),
            ], $valable ? 200 : 410)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
