<?php

namespace App\Http\Controllers;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Le lien de l'e-mail d'une demande de rattachement : un cabinet
 * d'expertise comptable vérifié a invité le SIREN d'une entreprise déjà
 * inscrite (voir InviterUneEntrepriseAction). Ouvrir le lien n'écrit rien.
 * La demande se lit par l'empreinte de son jeton, avant qu'aucune société
 * ne soit ouverte (voir LienCabinet::parSonJeton()) ; une demande absente,
 * qui n'est plus en cours ou expirée donne une page neutre. Sans compte
 * connecté, la page dit seulement que ce cabinet demande à suivre
 * l'entreprise de ce SIREN et que seul son propriétaire, connecté à son
 * compte, y répond, avec un bouton vers la connexion, qui ramène ici. Le
 * propriétaire actif de l'entreprise visée, ni close ni révoquée, va à la
 * page de rattachement de son espace (voir RattachementDUnCabinet) ; tout
 * autre compte lit une page neutre, qui ne nomme pas la société. Rien ne
 * s'affiche de la société avant que son propriétaire soit connecté.
 */
class RattachementAuCabinetController
{
    public const MESSAGE_LIEN_INVALIDE = 'Ce lien n\'est plus valable. Demandez au cabinet une nouvelle invitation.';

    public const MESSAGE_AUTRE_COMPTE = 'Seul le propriétaire de l\'entreprise invitée répond à cette demande. Connectez-vous avec son compte.';

    public function ouvrir(Request $requete, string $jeton): Response|RedirectResponse
    {
        $lien = LienCabinet::parSonJeton($jeton);

        if ($lien === null || ! $lien->estUneDemandeValable()) {
            return $this->neutre(self::MESSAGE_LIEN_INVALIDE, 410);
        }

        $compte = $requete->user();

        if (! $compte instanceof User) {
            $requete->session()->put('url.intended', $requete->fullUrl());
            $cabinet = Societe::query()->whereKey($lien->cabinet_id)->firstOrFail(['id', 'raison_sociale', 'siege_ville']);

            return response()->view('cabinets.rattachement', [
                'cabinet' => $cabinet,
                'siren' => Societe::query()->whereKey($lien->societe_id)->value('siren'),
                'connexion' => Filament::getPanel('societe')->getLoginUrl(),
            ])->header('Referrer-Policy', 'no-referrer');
        }

        $societe = Societe::query()->whereKey($lien->societe_id)->firstOrFail(['id', 'uuid', 'close_le', 'statut']);

        if (! $compte->estProprietaire() || ! $compte->peutAgir() || $compte->societe_id !== CleDuModele::de($societe) || $societe->close_le !== null || $societe->statut === StatutSociete::Revoquee) {
            return $this->neutre(self::MESSAGE_AUTRE_COMPTE, 403);
        }

        return redirect()->to(RattachementDUnCabinet::getUrl(['lien' => $lien->uuid], panel: 'societe', tenant: $societe))->header('Referrer-Policy', 'no-referrer');
    }

    private function neutre(string $message, int $statut): Response
    {
        return response()->view('cabinets.rattachement-invalide', ['message' => $message], $statut)->header('Referrer-Policy', 'no-referrer');
    }
}
