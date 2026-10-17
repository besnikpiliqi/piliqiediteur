<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation légale des documents : une série par magasin, par type de
 * document et par année, jamais de trou ni de doublon même sous
 * concurrence.
 *
 * Chaque numéro porte le code du type, le code du magasin (voir
 * CodeMagasin) et l'année : FAC-LYON1-2026-000001, 26 caractères au plus
 * jusqu'au rang 999999, sous la limite de 35 du numéro de facture (BT-1).
 * La plateforme contrôle l'unicité d'un numéro sur le SIREN du vendeur,
 * le numéro et l'année : deux magasins d'une même société, qui ont chacun
 * leurs séries, ne produisent donc jamais le même numéro, leurs codes
 * étant différents dans la société, et jamais repris d'une société
 * révoquée du même SIREN (voir CodeMagasin::estPris()). Les références de produits (PRD-000001), qui ne sont
 * pas des numéros de document, ne portent ni code de magasin ni année.
 *
 * Le préfixe d'une série s'écrit à sa création, avec le code du magasin
 * relu en base sous un verrou partagé : un changement de code en cours
 * (voir Magasin::aNumeroteUnDocument()) est attendu et pris en compte, et le
 * code ne change plus une fois la première série créée.
 *
 * Deux races gérées explicitement :
 *  1. Deux magasins (ou deux types) n'entrent jamais en collision : clé
 *     unique (magasin_id, type_document, period_key).
 *  2. Deux créations simultanées de la PREMIÈRE séquence d'un magasin/type
 *     (aucune ligne à verrouiller tant qu'elle n'existe pas) : gérée via
 *     createOrFirst(). Le "perdant" de la course tente sa création dans un
 *     point de sauvegarde, que le refus de la clé unique annule seul (sous
 *     PostgreSQL, une erreur SQL annulerait sinon toute la transaction de
 *     l'émission), puis relit la ligne du gagnant, validée entre-temps.
 *
 * Une fois la ligne de séquence garantie existante, lockForUpdate()
 * sérialise tous les incréments suivants (SELECT ... FOR UPDATE). Sous
 * SQLite (développement et tests), lockForUpdate() est un no-op
 * silencieux : la logique reste correcte en mono-processus, mais la
 * garantie contre les accès simultanés ne vaut que sous PostgreSQL, en
 * production.
 */
class DocumentNumberingService
{
    /**
     * Le nombre de chiffres, au moins, du rang d'un numéro : 000001 dans
     * FAC-LYON1-2026-000001.
     */
    public const CHIFFRES_DU_RANG = 6;

    /**
     * Le numéro de document que désigne une saisie : sans espace autour, en
     * majuscules, comme tout numéro s'écrit (le code du type, celui du
     * magasin, voir CodeMagasin, l'année et le rang), et le rang complété
     * par des zéros à gauche, comme suivant() l'écrit : « tk-lyon1-2026-12 »
     * désigne TK-LYON1-2026-000012. Une saisie d'une autre forme reste telle
     * quelle, en majuscules.
     */
    public static function numeroSaisi(string $saisie): string
    {
        $numero = mb_strtoupper(trim($saisie));

        if (preg_match('/^([A-Z]+-[A-Z0-9]+-\d{4}-)(\d+)$/', $numero, $segments) !== 1) {
            return $numero;
        }

        return $segments[1].str_pad(ltrim($segments[2], '0'), self::CHIFFRES_DU_RANG, '0', STR_PAD_LEFT);
    }

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

        SequenceDocument::query()->createOrFirst($critere, [
            'prefixe' => $this->prefixe($magasin, $type, $periodKey),
            'prochain_numero' => 1,
            'rembourrage' => self::CHIFFRES_DU_RANG,
        ]);

        // Re-sélection OBLIGATOIRE avec verrou : createOrFirst() ne pose
        // aucun lock, qu'on ait créé la ligne ou récupéré celle du
        // concurrent qui nous a devancé.
        return SequenceDocument::query()->where($critere)->lockForUpdate()->firstOrFail();
    }

    /**
     * « FAC-LYON1-2026- » pour un document, « PRD- » pour une référence de
     * produit.
     */
    private function prefixe(Magasin $magasin, TypeDocument $type, string $periodKey): string
    {
        if ($type === TypeDocument::Produit) {
            return "{$type->code()}-";
        }

        $codeDuMagasin = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->value('code');

        return "{$type->code()}-{$codeDuMagasin}-{$periodKey}-";
    }
}
