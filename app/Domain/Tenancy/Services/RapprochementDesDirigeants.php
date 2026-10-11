<?php

namespace App\Domain\Tenancy\Services;

use App\Integrations\Identite\ResultatDIdentite;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;
use Illuminate\Support\Str;

/**
 * Rapproche une identité vérifiée des dirigeants qui ont le pouvoir de
 * représenter une entreprise au registre national des entreprises (B1) :
 * même nom de naissance ou d'usage, même premier prénom, même mois et même
 * année de naissance, sans tenir compte des accents ni de la casse. Quand
 * le dirigeant est une personne morale (une holding présidente), ses
 * propres représentants comptent aussi, un niveau plus bas au plus (voir
 * candidats()) ; la chaîne qui mène à la personne rapprochée se garde avec
 * la preuve.
 */
class RapprochementDesDirigeants
{
    public function __construct(private readonly RegistreNationalDesEntreprises $rne) {}

    /**
     * Les personnes physiques qui peuvent représenter l'entreprise : ses
     * dirigeants qui en ont le pouvoir, puis, pour chaque dirigeant
     * personne morale qui l'a, ses propres représentants, chacune avec la
     * chaîne qui mène à elle.
     *
     * @return list<array{representant: RepresentantRne, chaine: list<string>}>
     *
     * @throws RneIndisponibleException
     */
    public function candidats(string $siren): array
    {
        $candidats = [];

        foreach ($this->rne->representants($siren) as $dirigeant) {
            if (! $dirigeant->pouvoirDeRepresentation) {
                continue;
            }

            if (! $dirigeant->estUnePersonneMorale()) {
                $candidats[] = ['representant' => $dirigeant, 'chaine' => [$dirigeant->libelle()]];

                continue;
            }

            foreach ($this->rne->representants($dirigeant->sirenPersonneMorale) as $representant) {
                if ($representant->pouvoirDeRepresentation && ! $representant->estUnePersonneMorale()) {
                    $candidats[] = ['representant' => $representant, 'chaine' => [$dirigeant->libelle(), "{$representant->libelle()} de {$dirigeant->nom}"]];
                }
            }
        }

        return $candidats;
    }

    /**
     * Le candidat que désigne cette identité, null si aucun ne lui
     * correspond.
     *
     * @return array{representant: RepresentantRne, chaine: list<string>}|null
     *
     * @throws RneIndisponibleException
     */
    public function rapprocher(string $siren, ResultatDIdentite $identite): ?array
    {
        foreach ($this->candidats($siren) as $candidat) {
            if (self::correspond($identite, $candidat['representant'])) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Cette identité est-elle celle de ce dirigeant ?
     */
    public static function correspond(ResultatDIdentite $identite, RepresentantRne $representant): bool
    {
        $nomsDeLIdentite = array_filter(array_map(self::normaliser(...), [$identite->nom, $identite->nomUsage]));
        $nomsDuDirigeant = array_filter(array_map(self::normaliser(...), [$representant->nom, $representant->nomUsage]));
        $prenom = self::normaliser($identite->prenoms[0] ?? null);

        return array_intersect($nomsDeLIdentite, $nomsDuDirigeant) !== []
            && $prenom !== ''
            && $prenom === self::normaliser($representant->prenoms[0] ?? null)
            && $identite->naissance !== null
            && $identite->naissance === $representant->naissance;
    }

    private static function normaliser(?string $texte): string
    {
        return (string) preg_replace('/[^A-Z]/', '', Str::upper(Str::ascii((string) $texte)));
    }
}
