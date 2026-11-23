<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Fichiers\RevueAntivirus;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Les fichiers des pièces déposées pour le traitement à la main, par
 * l'éditeur, d'un courrier recommandé non reçu (voir PieceVerification) :
 * chiffrés sur le disque privé « verification »
 * (Crypt::encryptString, avec la clé de l'application), déchiffrés pour la
 * seule consultation de l'éditeur (voir editeur:societe:pieces), effacés
 * dès sa décision ou au plus tard trente jours après leur dépôt (voir
 * app:effacer-pieces-verification), comme les dossiers d'examen restés.
 * Une copie de pièce d'identité ne se garde jamais au-delà. Une pièce se
 * déchiffre pour l'examen après une nouvelle analyse par l'antivirus quand
 * la dernière date de plus de 24 heures (voir dechiffrerPourLExamen()) :
 * reconnue alors comme une menace, elle est bloquée et ne se sert plus.
 */
class PiecesDeVerification
{
    public const DISQUE = 'verification';

    public const MOTIF_DECISION = 'decision';

    public const MOTIF_DELAI = 'delai';

    /**
     * Le début du nom des dossiers temporaires, à la racine du disque
     * « verification », à côté des dossiers chiffrés des sociétés, où
     * l'éditeur lit les pièces déchiffrées (voir editeur:societe:pieces).
     */
    public const PREFIXE_DES_DOSSIERS_D_EXAMEN = 'examen-';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly RevueAntivirus $revue,
    ) {}

    /**
     * Le chemin d'un nouveau dossier d'examen, au nom imprévisible.
     */
    public static function nouveauDossierDExamen(): string
    {
        return Storage::disk(self::DISQUE)->path(self::PREFIXE_DES_DOSSIERS_D_EXAMEN.bin2hex(random_bytes(8)));
    }

    /**
     * Les dossiers d'examen qui existent encore (voir
     * app:effacer-pieces-verification), par leur chemin.
     *
     * @return list<string>
     */
    public static function dossiersDExamen(): array
    {
        $disque = Storage::disk(self::DISQUE);

        return array_values(collect($disque->directories())
            ->filter(fn (string $dossier): bool => str_starts_with($dossier, self::PREFIXE_DES_DOSSIERS_D_EXAMEN))
            ->map(fn (string $dossier): string => $disque->path($dossier))
            ->all());
    }

    /**
     * Range le fichier chiffré de la société, et rend son chemin.
     */
    public function ranger(Societe $societe, string $contenu): string
    {
        $chemin = 'societe-'.CleDuModele::de($societe).'/'.bin2hex(random_bytes(16)).'.chiffre';
        Storage::disk(self::DISQUE)->put($chemin, Crypt::encryptString($contenu));

        return $chemin;
    }

    /**
     * Le contenu d'une pièce pour l'examen de l'éditeur, de la société
     * active ; null pour une pièce bloquée par l'antivirus. Sa dernière
     * analyse date de plus de 24 heures : elle est analysée de nouveau,
     * et, reconnue comme une menace, bloquée : le blocage s'inscrit au
     * journal d'audit et le propriétaire de la société en est prévenu.
     *
     * @throws AntivirusInjoignableException quand l'antivirus ne répond pas pour une analyse due
     */
    public function dechiffrerPourLExamen(PieceVerification $piece, InterventionDeLEditeur $editeur): ?string
    {
        if ($piece->bloquee_le !== null) {
            return null;
        }

        $contenu = Crypt::decryptString(ValeurRequise::de(Storage::disk(self::DISQUE)->get(ValeurRequise::de($piece->chemin, 'le fichier de la pièce, pas encore effacée')), 'le contenu chiffré de la pièce'));

        if (! $this->revue->aRevoir(ValeurRequise::de($piece->analysee_le, 'l\'instant de la dernière analyse de la pièce'))) {
            return $contenu;
        }

        $resultat = $this->revue->analyser($contenu);
        $maintenant = CarbonImmutable::now();

        if ($resultat->estSain()) {
            $piece->forceFill(['analysee_le' => $maintenant])->save();

            return $contenu;
        }

        DB::transaction(function () use ($piece, $resultat, $maintenant, $editeur): void {
            Societe::verrouillerAvantSonJournalDAudit($piece->societe_id);
            $piece->forceFill(['analysee_le' => $maintenant, 'bloquee_le' => $maintenant, 'menace' => $resultat->menace])->save();
            $this->journalAudit->inscrire(ActionAuditee::PieceDeVerificationBloquee, null, null, null, ['type_piece' => $piece->type->value, 'nom' => $piece->nom, 'empreinte' => $piece->empreinte, 'menace' => $resultat->menace], $piece, $editeur);
        });

        $societe = app(TenantContext::class)->societe();
        $societe->proprietaire()->first()?->notify(new AvisDeLAntivirus($societe, 'Pièce pour l\'éditeur bloquée par l\'antivirus', "La pièce « {$piece->nom} » ({$piece->type->getLabel()}) déposée pour l'éditeur est bloquée : l'antivirus y a trouvé la menace « {$resultat->menace} ». L'éditeur ne l'examinera pas : déposez-en une copie saine."));

        return null;
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
