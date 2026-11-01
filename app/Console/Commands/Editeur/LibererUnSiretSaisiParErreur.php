<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\LibererUnSiretSaisiParErreurAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * La libération par l'éditeur d'un SIRET saisi par erreur pour un magasin
 * d'une société validée (voir LibererUnSiretSaisiParErreurAction),
 * procédure grave lancée par deux opérateurs distincts, une fois saisi le
 * code du courrier de libération envoyé au représentant légal, à
 * l'adresse officielle du siège (editeur:courrier:envoyer
 * --motif=liberation_siret), et le magasin fautif passé à son bon SIRET ou
 * fermé pour cessation : le SIRET peut alors se réserver pour le magasin
 * qui doit le porter.
 */
#[Signature('editeur:siret:liberer {societe : Identifiant ou uuid de la société} {siret : Le SIRET saisi par erreur} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Libère un SIRET saisi par erreur pour un magasin, une fois le magasin passé à son bon SIRET ou fermé pour cessation')]
class LibererUnSiretSaisiParErreur extends ProcedureDeLEditeur
{
    protected function operateursExiges(): int
    {
        return 2;
    }

    protected function resume(Societe $societe): array
    {
        return [
            'SIRET à libérer : '.$this->siret().'.',
            'Il ne se libère que réservé par un magasin de cette société qui porte désormais un autre SIRET, ou fermé pour cessation, et une fois saisi le code du courrier de libération.',
            'Procédure grave : deux opérateurs distincts s\'authentifient chacun par son secret.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $magasin = app(LibererUnSiretSaisiParErreurAction::class)->handle($societe, $this->siret(), $editeur);

        return "SIRET {$this->siret()} libéré (magasin {$magasin->code}) : il peut se réserver pour le magasin qui doit le porter.";
    }

    private function siret(): string
    {
        return (string) preg_replace('/\s+/', '', (string) $this->argument('siret'));
    }
}
