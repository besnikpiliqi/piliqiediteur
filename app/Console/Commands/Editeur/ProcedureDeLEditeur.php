<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\RegistreDesOperateurs;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Command;
use LogicException;

/**
 * Le cadre commun des procédures exceptionnelles de l'éditeur (php
 * artisan list editeur), chacune sur une société désignée par son
 * identifiant ou son uuid :
 * - elle exige sa justification (--justification, au moins vingt
 *   caractères) et la référence de sa pièce (--piece) ;
 * - elle affiche son résumé, puis fait retaper la valeur attendue (le
 *   SIREN de la société) : --confirmer=<valeur> la donne d'avance ; sans
 *   elle, la commande la demande, et la refuse en mode non interactif ;
 * - l'opérateur saisit son nom et son secret personnel dans un champ
 *   masqué, jamais en option de la ligne de commande, pour ne pas le
 *   laisser dans l'historique : la procédure ne se lance donc que dans un
 *   terminal interactif (voir RegistreDesOperateurs) ;
 * - elle s'inscrit, origine « éditeur », avec sa justification, sa pièce,
 *   ses opérateurs, l'utilisateur système et l'hôte (voir
 *   InterventionDeLEditeur), et ne réécrit jamais un document émis.
 * Un refus n'écrit rien.
 */
abstract class ProcedureDeLEditeur extends Command
{
    public const OPTIONS_COMMUNES = '{--justification= : Pourquoi l\'éditeur intervient, en vingt caractères au moins} {--piece= : La référence de la pièce qui fonde l\'intervention} {--confirmer= : La valeur attendue, retapée d\'avance (le SIREN de la société)}';

    /**
     * Les lignes du résumé affiché avant la confirmation.
     *
     * @return list<string>
     */
    abstract protected function resume(Societe $societe): array;

    /**
     * Fait la procédure, une fois tout confirmé ; le message de réussite
     * qu'elle rend s'affiche.
     *
     * @throws LogicException
     */
    abstract protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string;

    public function handle(RegistreDesOperateurs $operateurs): int
    {
        $societe = Societe::trouverParIdentifiantOuUuid((string) $this->argument('societe'));

        if ($societe === null) {
            $this->error('Société inconnue : donnez son identifiant ou son uuid.');

            return self::FAILURE;
        }

        $justification = trim((string) $this->option('justification'));
        $piece = trim((string) $this->option('piece'));

        if (mb_strlen($justification) < InterventionDeLEditeur::LONGUEUR_MINIMALE_DE_LA_JUSTIFICATION || $piece === '') {
            $this->error('Une procédure de l\'éditeur exige sa justification (--justification, '.InterventionDeLEditeur::LONGUEUR_MINIMALE_DE_LA_JUSTIFICATION.' caractères au moins) et la référence de sa pièce (--piece).');

            return self::FAILURE;
        }

        foreach ([$this->getDescription(), "Société n° {$societe->getKey()} — {$societe->getFilamentName()} — SIREN {$societe->siren}", ...$this->resume($societe)] as $ligne) {
            $this->line($ligne);
        }

        $attendu = (string) $societe->siren;
        $saisie = $this->option('confirmer') ?? ($this->input->isInteractive() ? $this->ask("Retapez le SIREN {$attendu} pour confirmer") : null);

        if (! is_string($saisie) || ! hash_equals($attendu, trim($saisie))) {
            $this->error('Confirmation absente ou différente : rien n\'est fait.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Une procédure de l\'éditeur ne se lance que dans un terminal interactif : l\'opérateur y saisit son secret dans un champ masqué.');

            return self::FAILURE;
        }

        try {
            $operateur = $operateurs->authentifier($this->ask('Nom de l\'opérateur'), $this->secret('Secret de l\'opérateur'));
            $this->info($this->executer($societe, new InterventionDeLEditeur($justification, $piece, [$operateur])));
        } catch (VerificationRefuseeException|LogicException $refus) {
            $this->error($refus->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
