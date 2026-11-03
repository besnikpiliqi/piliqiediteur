<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TeteJournalAudit;

/**
 * Vérifie les chaînes du journal d'audit d'une société (voir JournalAudit),
 * chacune depuis son début, ou depuis son point de reprise une fois son
 * début effacé à expiration (voir app:purger-journal-audit) : les
 * séquences se suivent sans trou, chaque empreinte se recalcule avec la
 * clé qui l'a chaînée (la clé courante ou l'une des précédentes, voir
 * JournalAudit::cle()), et la dernière entrée est celle que retient la
 * tête de la chaîne. Une entrée modifiée, insérée, retirée au milieu ou à
 * la fin se voit.
 *
 * Lecture explicitement limitée à cette société, sous son contexte.
 */
class VerificateurJournalAudit
{
    /**
     * Les anomalies des chaînes de la société, une phrase chacune ; aucune
     * quand le journal est intègre.
     *
     * @return list<string>
     */
    public function verifier(Societe $societe): array
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe): array {
            $anomalies = [];
            $tetes = TeteJournalAudit::query()->where('societe_id', $societe->getKey())->orderBy('id')->get();
            $chainesSansTete = EntreeAudit::query()
                ->select(['magasin_id', 'categorie'])
                ->distinct()
                ->get()
                ->reject(fn (EntreeAudit $chaine): bool => $tetes->contains(fn (TeteJournalAudit $tete): bool => self::memeMagasin($tete->magasin_id, $chaine->magasin_id) && $tete->categorie === $chaine->categorie));

            foreach ($chainesSansTete as $chaine) {
                $anomalies[] = self::nomDeLaChaine($chaine->magasin_id === null ? null : (int) $chaine->magasin_id, $chaine->categorie->value).' : entrées sans tête de chaîne.';
            }

            foreach ($tetes as $tete) {
                array_push($anomalies, ...$this->verifierLaChaine($tete));
            }

            return $anomalies;
        });
    }

    /**
     * @return list<string>
     */
    private function verifierLaChaine(TeteJournalAudit $tete): array
    {
        $nom = self::nomDeLaChaine($tete->magasin_id === null ? null : (int) $tete->magasin_id, $tete->categorie->value);
        $anomalies = [];
        $sequenceAttendue = ($tete->reprise_sequence ?? 0) + 1;
        $empreintePrecedente = $tete->reprise_empreinte;
        $derniere = null;

        EntreeAudit::query()
            ->where('categorie', $tete->categorie->value)
            ->when($tete->magasin_id === null, fn ($requete) => $requete->whereNull('magasin_id'), fn ($requete) => $requete->where('magasin_id', $tete->magasin_id))
            ->orderBy('sequence')
            ->orderBy('id')
            ->lazy(500)
            ->each(function (EntreeAudit $entree) use ($nom, &$anomalies, &$sequenceAttendue, &$empreintePrecedente, &$derniere): void {
                if ($entree->sequence !== $sequenceAttendue) {
                    $anomalies[] = "{$nom} : séquence {$entree->sequence} trouvée, {$sequenceAttendue} attendue.";
                }

                $cle = JournalAudit::cle($entree->cle_id);

                if ($cle === null) {
                    $anomalies[] = "{$nom} : séquence {$entree->sequence} chaînée par une clé inconnue ({$entree->cle_id}).";
                } elseif (! hash_equals(JournalAudit::empreinte($cle, $empreintePrecedente, $entree), $entree->empreinte)) {
                    $anomalies[] = "{$nom} : séquence {$entree->sequence} altérée ou hors de sa place dans la chaîne.";
                }

                $sequenceAttendue = $entree->sequence + 1;
                $empreintePrecedente = $entree->empreinte;
                $derniere = $entree;
            });

        $derniereSequence = $derniere?->sequence ?? ($tete->reprise_sequence ?? 0);
        $derniereEmpreinte = $derniere?->empreinte ?? $tete->reprise_empreinte;

        if ($derniereSequence !== $tete->sequence || $derniereEmpreinte !== $tete->empreinte) {
            $anomalies[] = "{$nom} : la tête retient la séquence {$tete->sequence}, la chaîne s'arrête à {$derniereSequence} ; des dernières entrées manquent ou ont été ajoutées hors du journal.";
        }

        return $anomalies;
    }

    private static function memeMagasin(mixed $premier, mixed $second): bool
    {
        return ($premier === null && $second === null) || ($premier !== null && $second !== null && (int) $premier === (int) $second);
    }

    private static function nomDeLaChaine(?int $magasinId, string $categorie): string
    {
        return $magasinId === null ? "Chaîne de la société ({$categorie})" : "Chaîne du magasin n° {$magasinId} ({$categorie})";
    }
}
