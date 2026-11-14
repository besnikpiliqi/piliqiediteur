<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * La révocation par l'éditeur d'une société validée qui a pris le SIREN
 * d'une autre entreprise (voir RevoquerSocieteAction), procédure grave
 * lancée par deux opérateurs distincts, une fois saisi le code du courrier
 * de révocation envoyé au représentant légal, à l'adresse officielle du
 * siège (editeur:courrier:envoyer --motif=revocation) : la société passe en
 * lecture seule pour toujours, ses documents restent consultables, son
 * SIREN et ses SIRET se libèrent, ses liens avec un cabinet prennent fin,
 * et la conservation de ses données de vérification prend fin cinq ans
 * plus tard. Pour un cabinet dont un lien, une demande ou une invitation
 * n'a pas pu prendre fin, la commande échoue en le disant : relancée, elle
 * termine ce qui reste.
 */
#[Signature('editeur:societe:revoquer {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Révoque une société validée qui a pris le SIREN d\'une autre entreprise, ce qui libère son SIREN et ses SIRET')]
class RevoquerLaSociete extends ProcedureDeLEditeur
{
    protected function operateursExiges(): int
    {
        return 2;
    }

    protected function resume(Societe $societe): array
    {
        return [
            'Statut : '.$societe->statut->getLabel().' ; '.($societe->estValidee() ? 'validée' : 'non validée, la procédure le refusera').'.',
            'Révocation définitive : la société passera en lecture seule pour toujours, ses documents restant consultables ; son SIREN et les SIRET de ses magasins seront libérés ; ses liens avec un cabinet d\'expertise comptable prendront fin.',
            'Procédure grave : deux opérateurs distincts s\'authentifient chacun par son secret.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $revocation = app(RevoquerSocieteAction::class)->handle($societe, $editeur);

        if ($revocation['en_echec'] > 0) {
            throw new LogicException("Société révoquée, mais {$revocation['en_echec']} lien(s), demande(s) ou invitation(s) de ce cabinet n'ont pas pu prendre fin (erreur signalée au journal de l'application) : relancez la procédure pour les terminer.");
        }

        if ($revocation['reprise']) {
            return 'Révocation terminée : les liens, demandes et invitations qui restaient à ce cabinet ont pris fin.';
        }

        return 'Société révoquée : son SIREN est libéré'.($revocation['sirets'] === [] ? '.' : ', ainsi que les SIRET '.implode(', ', $revocation['sirets']).'.');
    }
}
