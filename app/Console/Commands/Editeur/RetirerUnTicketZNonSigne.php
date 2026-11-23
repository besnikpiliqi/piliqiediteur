<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Actions\RetirerUnTicketZNonSigneAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le retrait par l'éditeur, sur pièces, d'un ticket Z que rien ne signe
 * (voir RetirerUnTicketZNonSigneAction), procédure grave lancée par deux
 * opérateurs distincts, qui font retaper le numéro du ticket Z : il est
 * retiré, les documents qu'on y avait rattachés s'en détachent, puis les
 * journées en attente du magasin se clôturent, le vrai ticket Z de sa
 * journée compris.
 */
#[Signature('editeur:magasin:retirer-ticket-z {societe : Identifiant ou uuid de la société} {magasin : Code du magasin dans la société} {numero : Numéro du ticket Z que rien ne signe} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Retire un ticket Z que rien ne signe, écrit hors de la clôture de sa journée, puis clôture les journées en attente du magasin')]
class RetirerUnTicketZNonSigne extends ProcedureDeLEditeur
{
    protected function operateursExiges(): int
    {
        return 2;
    }

    protected function resume(Societe $societe): array
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin'));
        $numero = (string) $this->argument('numero');
        $journee = $magasin === null ? null : ValeursLues::texteOuNul(DB::table('clotures_caisse')->where('magasin_id', $magasin->getKey())->where('numero', $numero)->value('date_cloture'));

        return [
            $magasin === null
                ? 'Magasin introuvable dans cette société : la procédure le refusera.'
                : "Magasin {$magasin->code} — {$magasin->nom} — SIRET {$magasin->siret}.",
            $journee === null
                ? "Ticket Z {$numero} introuvable dans ce magasin : la procédure le refusera."
                : "Ticket Z {$numero} de la journée du ".Calendrier::afficherJour($journee, 'd/m/Y').'.',
            'Il sera retiré seulement si aucune clôture ne le signe au journal des événements ; les documents qu\'on y a rattachés s\'en détacheront, puis les journées en attente du magasin se clôtureront.',
            'Procédure grave : deux opérateurs distincts s\'authentifient chacun par son secret.',
        ];
    }

    protected function valeurAConfirmer(Societe $societe): array
    {
        return ['le numéro du ticket Z', (string) $this->argument('numero')];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $magasin = self::magasinDeLaSociete($societe, (string) $this->argument('magasin')) ?? throw new LogicException(self::MESSAGE_MAGASIN_INTROUVABLE);
        $resultat = app(RetirerUnTicketZNonSigneAction::class)->handle($magasin, (string) $this->argument('numero'), $editeur);
        $produits = $resultat['tickets_z'] === [] ? 'aucun ticket Z produit' : 'tickets Z produits : '.implode(', ', $resultat['tickets_z']);
        $message = "Ticket Z {$resultat['retire']['numero']} retiré du magasin {$magasin->code} ({$resultat['retire']['documents_detaches']} document(s) détaché(s)) ; {$produits}.";

        return $resultat['cloture_arretee'] === null ? $message : "{$message} La clôture s'est arrêtée : {$resultat['cloture_arretee']}";
    }
}
