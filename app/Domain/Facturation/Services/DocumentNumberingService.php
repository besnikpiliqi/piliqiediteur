<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation légale des documents : indépendante par magasin ET par
 * type de document, jamais de trou ni de doublon même sous concurrence.
 *
 * Deux races gérées explicitement :
 *  1. Deux magasins (ou deux types) n'entrent jamais en collision : clé
 *     unique (magasin_id, type_document, period_key).
 *  2. Deux créations simultanées de la PREMIÈRE séquence d'un magasin/type
 *     (aucune ligne à verrouiller tant qu'elle n'existe pas) : gérée via
 *     createOrFirst(), qui rattrape la violation de contrainte unique côté
 *     "perdant" de la course plutôt que de planter.
 *
 * Une fois la ligne de séquence garantie existante, lockForUpdate()
 * sérialise tous les incréments suivants (verrou pessimiste InnoDB).
 * Sous SQLite (dev/tests), lockForUpdate() est un no-op silencieux : la
 * logique reste correcte en mono-processus, mais la garantie anti
 * race-condition réelle ne vaut que sous MySQL/Postgres en production.
 */
class DocumentNumberingService
{
    public function suivant(Magasin $magasin, TypeDocument $type, string $periodKey = ''): string
    {
        return DB::transaction(function () use ($magasin, $type, $periodKey) {
            $sequence = $this->sequenceVerrouillee($magasin, $type, $periodKey);

            $numero = $sequence->prefixe.str_pad(
                (string) $sequence->prochain_numero,
                $sequence->rembourrage,
                '0',
                STR_PAD_LEFT,
            );

            $sequence->increment('prochain_numero');

            return $numero;
        });
    }

    private function sequenceVerrouillee(Magasin $magasin, TypeDocument $type, string $periodKey): SequenceDocument
    {
        $critere = [
            'magasin_id' => $magasin->getKey(),
            'type_document' => $type->value,
            'period_key' => $periodKey,
        ];

        $sequence = SequenceDocument::query()->where($critere)->lockForUpdate()->first();

        if ($sequence) {
            return $sequence;
        }

        try {
            SequenceDocument::query()->createOrFirst($critere, [
                'prefixe' => $this->prefixeParDefaut($type, $periodKey),
                'prochain_numero' => 1,
                'rembourrage' => 6,
            ]);
        } catch (UniqueConstraintViolationException) {
            // L'autre transaction a gagné la course de création : on
            // continue, le verrou ci-dessous trouvera sa ligne.
        }

        // Re-sélection OBLIGATOIRE avec verrou : createOrFirst() ne pose
        // aucun lock, qu'on ait créé la ligne ou récupéré celle du
        // concurrent qui nous a devancé.
        return SequenceDocument::query()->where($critere)->lockForUpdate()->firstOrFail();
    }

    private function prefixeParDefaut(TypeDocument $type, string $periodKey): string
    {
        $code = match ($type) {
            TypeDocument::Devis => 'DEV',
            TypeDocument::Facture => 'FAC',
            TypeDocument::Avoir => 'AV',
            TypeDocument::FactureAchat => 'FA',
            TypeDocument::AvoirFournisseur => 'AF',
            TypeDocument::ClotureCaisse => 'Z',
            TypeDocument::SoumissionEReporting => 'ER',
        };

        return $periodKey !== '' ? "{$code}-{$periodKey}-" : "{$code}-";
    }
}
