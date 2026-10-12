<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\CreerSaSocieteAvecUnCompteRetireAction;
use App\Filament\Societe\Pages\TableauDeBord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * La création de sa propre société par une personne dont le compte a été
 * retiré de sa société (voir CreerSaSocieteAvecUnCompteRetireAction) : le
 * formulaire de l'inscription (SIREN, premier magasin), avec l'adresse et
 * le mot de passe du compte, redemandés dans la requête qui crée, sans
 * qu'aucune session ne suffise. Créée, la société attend sa vérification ;
 * son propriétaire est connecté et arrive dans l'espace de sa société.
 */
class CreerSaSocieteController
{
    public function afficher(): Response
    {
        return $this->page([]);
    }

    public function creer(Request $requete, CreerSaSocieteAvecUnCompteRetireAction $creer): Response|RedirectResponse
    {
        $donnees = $requete->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'siren' => ['required', 'string', 'max:20'],
            'nom' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'nic' => ['required', 'string', 'max:10'],
        ]);

        try {
            $societe = $creer->handle($donnees['email'], $donnees['password'], ['siren' => $donnees['siren']], ['nom' => $donnees['nom'], 'code' => $donnees['code'] ?? null, 'nic' => $donnees['nic']]);
        } catch (ValidationException $refus) {
            return $this->page(collect($refus->errors())->flatten()->all(), $requete->only(['email', 'siren', 'nom', 'code', 'nic']));
        }

        Auth::login($societe->proprietaire);
        $requete->session()->regenerate();

        return redirect()->to(TableauDeBord::getUrl(panel: 'societe', tenant: $societe));
    }

    /**
     * @param  list<string>  $refus
     * @param  array<string, mixed>  $saisie
     */
    private function page(array $refus, array $saisie = []): Response
    {
        return response()->view('societe.creer', ['refus' => $refus, 'saisie' => $saisie], $refus === [] ? 200 : 422);
    }
}
