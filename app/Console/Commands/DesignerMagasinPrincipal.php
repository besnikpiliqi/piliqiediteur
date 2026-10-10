<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande de l'éditeur, jusqu'à son administration (étape 13 de la
 * feuille de route) : désigne le magasin principal d'une société (K5),
 * celui qui fixe seul les réglages communs aux magasins de son SIREN (voir
 * ReglagesCommunsDeLaSociete) et qui recevra les factures adressées au
 * SIREN seul (J4). Sans désignation, le principal est le magasin qui porte
 * ce SIREN depuis le plus longtemps ; l'éditeur en désigne un autre à la
 * demande de la société, par exemple quand son principal est fermé.
 *
 * Avant de désigner, la commande dit quels autres magasins du SIREN n'ont
 * pas les réglages communs du nouveau principal : ils n'émettront plus ni
 * facture ni ticket tant qu'ils ne s'y seront pas alignés. Elle demande
 * confirmation, sauf --force. Aucun réglage n'est modifié chez aucun
 * magasin : chacun s'aligne lui-même, par ses propres écrans, son journal
 * des événements notant alors le changement de son régime de TVA ou de
 * son option pour les débits (JET 270). La désignation, elle, s'inscrit
 * au journal de l'application.
 */
#[Signature('app:designer-magasin-principal {magasin : L\'identifiant ou l\'uuid du magasin à désigner} {--force : Désigne sans demander de confirmation}')]
#[Description('Désigne le magasin principal d\'une société, qui fixe seul les réglages communs aux magasins de son SIREN')]
class DesignerMagasinPrincipal extends Command
{
    public function handle(ReglagesCommunsDeLaSociete $reglagesCommuns): int
    {
        $identifiant = (string) $this->argument('magasin');
        $magasin = Magasin::query()
            ->where(fn (Builder $magasins): Builder => ctype_digit($identifiant)
                ? $magasins->whereKey((int) $identifiant)
                : $magasins->where('uuid', $identifiant))
            ->first();

        if ($magasin === null) {
            $this->error("Aucun magasin « {$identifiant} ».");

            return self::FAILURE;
        }

        if (blank($magasin->siren)) {
            $this->error("Le magasin « {$magasin->nom} » n'a pas de SIREN : il n'appartient à aucune société.");

            return self::FAILURE;
        }

        $siren = $magasin->siren;
        $principalActuel = $reglagesCommuns->idDuMagasinPrincipal($siren);

        if ($magasin->designe_principal) {
            $this->info("Le magasin « {$magasin->nom} » est déjà le magasin principal désigné du SIREN {$siren}.");

            return self::SUCCESS;
        }

        $this->table(['Magasin du SIREN', 'Nom', 'Réglages communs à aligner sur le nouveau principal'], Magasin::query()
            ->where('siren', $siren)
            ->whereKeyNot($magasin->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (Magasin $autre): array => [
                '#'.$autre->getKey().($autre->getKey() === $principalActuel ? ' (principal actuel)' : ''),
                $autre->nom,
                ReglagesCommunsDeLaSociete::liste(ReglagesCommunsDeLaSociete::ecartsAvec($autre, $magasin)) ?: 'aucun',
            ])
            ->all());

        if (! $this->option('force') && ! $this->confirm("Désigner « {$magasin->nom} » (#{$magasin->getKey()}) magasin principal du SIREN {$siren} ?")) {
            $this->warn('Désignation abandonnée : rien n\'a changé.');

            return self::FAILURE;
        }

        // Un seul magasin désigné par SIREN (voir l'index unique de la
        // table magasins) : l'ancienne désignation s'efface d'abord, même
        // portée par un magasin supprimé depuis. La requête, sans
        // MagasinScope sur la table magasins, est limitée à ce SIREN.
        DB::transaction(function () use ($magasin, $siren): void {
            Magasin::withTrashed()->where('siren', $siren)->where('designe_principal', true)->update(['designe_principal' => null]);
            $magasin->forceFill(['designe_principal' => true])->save();
        });

        Log::info('Magasin principal désigné par l\'éditeur', [
            'siren' => $siren,
            'magasin_id' => $magasin->getKey(),
            'ancien_principal_id' => $principalActuel,
        ]);

        $this->info("« {$magasin->nom} » (#{$magasin->getKey()}) est désormais le magasin principal du SIREN {$siren}.");

        return self::SUCCESS;
    }
}
