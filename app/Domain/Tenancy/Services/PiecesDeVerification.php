<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Les fichiers des pièces déposées pour l'examen de l'éditeur (voir
 * PieceVerification) : chiffrés sur le disque privé « verification »
 * (Crypt::encryptString, avec la clé de l'application), déchiffrés pour la
 * seule consultation de l'éditeur (voir editeur:societe:pieces), effacés
 * dès sa décision ou au plus tard trente jours après leur dépôt (voir
 * app:effacer-pieces-verification). Une copie de pièce d'identité ne se
 * garde jamais au-delà.
 */
class PiecesDeVerification
{
    public const DISQUE = 'verification';

    public const MOTIF_DECISION = 'decision';

    public const MOTIF_DELAI = 'delai';

    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * Range le fichier chiffré de la société, et rend son chemin.
     */
    public function ranger(Societe $societe, string $contenu): string
    {
        $chemin = "societe-{$societe->getKey()}/".bin2hex(random_bytes(16)).'.chiffre';
        Storage::disk(self::DISQUE)->put($chemin, Crypt::encryptString($contenu));

        return $chemin;
    }

    public function dechiffrer(PieceVerification $piece): string
    {
        return Crypt::decryptString(Storage::disk(self::DISQUE)->get($piece->chemin));
    }

    /**
     * Efface le fichier de chacune de ses pièces qui en a encore un, dans
     * la société active, et l'inscrit au journal d'audit.
     */
    public function effacerToutes(Societe $societe, string $motif, ?InterventionDeLEditeur $editeur = null): int
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): int => PieceVerification::query()
            ->whereNull('effacee_le')
            ->orderBy('id')
            ->get()
            ->each(fn (PieceVerification $piece) => $this->effacer($piece, $motif, $editeur))
            ->count());
    }

    /**
     * Efface le fichier d'une pièce, une fois noté sur sa ligne et la
     * transaction validée : la trace de la pièce reste, sans son contenu.
     */
    public function effacer(PieceVerification $piece, string $motif, ?InterventionDeLEditeur $editeur = null): void
    {
        DB::transaction(function () use ($piece, $motif, $editeur): void {
            $chemin = $piece->chemin;
            $piece->forceFill(['chemin' => null, 'effacee_le' => now()])->save();
            $this->journalAudit->inscrire(ActionAuditee::PieceEffacee, null, null, null, ['type_piece' => $piece->type->value, 'motif' => $motif], $piece, $editeur);

            if ($chemin !== null) {
                DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));
            }
        });
    }
}
