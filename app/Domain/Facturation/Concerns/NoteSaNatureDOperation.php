<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Support\Str;
use LogicException;

/**
 * La nature d'opération d'un brouillon de facture ou d'un devis (voir
 * NatureOperation), pour des biens qui partent hors de France leur
 * livraison, et la date de l'opération (T49) : écrites à chaque
 * enregistrement du document, avec les lignes que la nature fait calculer
 * (voir ContexteDeVente), jamais par mass assignment. Une facture émise
 * les garde telles qu'elle les a signées (voir ProtegeLesDonneesValidees).
 *
 * La date de l'opération est celle de la livraison ou de la prestation,
 * un jour du calendrier du magasin, quelle que soit la nature : vide,
 * l'opération est du jour de l'émission ; un seul jour
 * (date_operation_fin), celui de la livraison ou de la fin de la
 * prestation ; une période (date_operation_debut, puis
 * date_operation_fin), pour une prestation de plusieurs jours. Une
 * facture l'imprime quand elle n'est pas le jour de son émission, un devis
 * comme la date prévue (voir MentionsFiscales).
 */
trait NoteSaNatureDOperation
{
    public const REFUS_DEBUT_SANS_FIN = 'Indiquez aussi le dernier jour de la prestation : son premier jour seul ne date pas l\'opération.';

    public const REFUS_DEBUT_APRES_FIN = 'Le premier jour de la prestation doit précéder son dernier jour.';

    public const REFUS_JOUR_ILLISIBLE = 'La date de la livraison ou de la prestation s\'écrit en jour, mois et année.';

    /**
     * Note la nature choisie et, quand elle demande une livraison (voir
     * NatureOperation::exigeUneLivraison()), la livraison saisie, sans
     * espaces autour, le pays en majuscules ; sinon, aucune livraison ne
     * reste d'un choix précédent. Ce qui n'est pas fourni reste tel quel :
     * la nature déjà notée (la vente en France pour un nouveau document)
     * et sa livraison. Le transport est celui que la nature impose (le
     * magasin, pour un export ou une livraison vers un DOM), sinon celui
     * saisi. La date de l'opération, de toute nature, se note comme
     * dateDeLOperationNotee() la lit.
     *
     * @param  array<string, mixed>  $attributs  nature_operation (NatureOperation ou sa valeur), livraison_adresse, livraison_complement, livraison_code_postal, livraison_ville, livraison_pays, transport_par (TransportPar ou sa valeur), date_operation_debut et date_operation_fin
     *
     * @throws LogicException une date de l'opération illisible, ou une période dont le début ne précède pas la fin
     */
    public function noterSaNatureDOperation(array $attributs): NatureOperation
    {
        $nature = $attributs['nature_operation'] ?? $this->nature_operation;
        $nature = $nature instanceof NatureOperation ? $nature : NatureOperation::from(ValeurSimple::enTexte($nature));
        $livree = $nature->exigeUneLivraison();
        $saisie = fn (string $champ): mixed => match (true) {
            ! $livree => null,
            ! array_key_exists($champ, $attributs) => $this->getAttribute($champ),
            blank($attributs[$champ]) => null,
            default => is_string($attributs[$champ]) ? trim($attributs[$champ]) : $attributs[$champ],
        };
        $transport = $saisie('transport_par');

        $this->forceFill([
            'nature_operation' => $nature,
            'livraison_adresse' => $saisie('livraison_adresse'),
            'livraison_complement' => $saisie('livraison_complement'),
            'livraison_code_postal' => $saisie('livraison_code_postal'),
            'livraison_ville' => $saisie('livraison_ville'),
            'livraison_pays' => ($pays = $saisie('livraison_pays')) === null ? null : Str::upper(ValeurSimple::enTexte($pays)),
            'transport_par' => $livree
                ? ($nature->transportImpose() ?? ($transport instanceof TransportPar ? $transport : TransportPar::tryFrom(ValeurSimple::enTexte($transport))))
                : null,
            ...$this->dateDeLOperationNotee($attributs),
        ]);

        return $nature;
    }

    /**
     * La date de l'opération saisie, telle qu'elle se note : chaque jour
     * « AAAA-MM-JJ », sans espaces autour, un champ vide valant aucun jour ;
     * un premier jour égal au dernier, un seul jour ; un premier jour sans
     * dernier jour, ou après lui, refusé, comme la base le refuse (voir la
     * migration des factures). Sans aucun des deux champs, la date déjà
     * notée reste ; avec l'un seulement, l'autre reste celui déjà noté.
     *
     * @param  array<string, mixed>  $attributs
     * @return array{date_operation_debut?: string|null, date_operation_fin?: string|null}
     *
     * @throws LogicException
     */
    private function dateDeLOperationNotee(array $attributs): array
    {
        if (! array_key_exists('date_operation_debut', $attributs) && ! array_key_exists('date_operation_fin', $attributs)) {
            return [];
        }

        $jour = function (string $champ) use ($attributs): ?string {
            $valeur = array_key_exists($champ, $attributs) ? $attributs[$champ] : $this->getAttribute($champ);

            if (blank($valeur)) {
                return null;
            }

            return (is_string($valeur) ? Calendrier::jourValide(trim($valeur)) : null)
                ?? throw new LogicException(self::REFUS_JOUR_ILLISIBLE);
        };
        $debut = $jour('date_operation_debut');
        $fin = $jour('date_operation_fin');

        if ($debut !== null && $fin === null) {
            throw new LogicException(self::REFUS_DEBUT_SANS_FIN);
        }

        if ($debut !== null && $debut > $fin) {
            throw new LogicException(self::REFUS_DEBUT_APRES_FIN);
        }

        return ['date_operation_debut' => $debut === $fin ? null : $debut, 'date_operation_fin' => $fin];
    }

    /**
     * La date de l'opération telle que le document l'imprime : un seul
     * jour, « 12/10/2026 », ou une période, « du 03/11/2026 au
     * 07/11/2026 » ; null quand aucune n'est notée.
     */
    public function dateDeLOperation(): ?string
    {
        return match (true) {
            $this->date_operation_fin === null => null,
            $this->date_operation_debut === null => Calendrier::afficherJour($this->date_operation_fin, 'd/m/Y'),
            default => 'du '.Calendrier::afficherJour($this->date_operation_debut, 'd/m/Y').' au '.Calendrier::afficherJour($this->date_operation_fin, 'd/m/Y'),
        };
    }

    /**
     * La date de l'opération notée est-elle autre que ce jour, celui de
     * l'émission du document (T49) : une période, ou un autre jour ?
     */
    public function dateDeLOperationDiffereDu(string $jour): bool
    {
        return $this->date_operation_debut !== null
            || ($this->date_operation_fin !== null && $this->date_operation_fin !== $jour);
    }

    /**
     * La livraison telle que le document l'imprime : l'adresse, fermée par
     * le pays, et qui transporte les biens ; rien pour une nature sans
     * livraison. Sa date est celle de l'opération (voir dateDeLOperation()).
     *
     * @return list<string>
     */
    public function lignesDeLivraison(): array
    {
        if (! $this->nature_operation->exigeUneLivraison()) {
            return [];
        }

        return array_values(array_filter([
            $this->livraison_adresse,
            $this->livraison_complement,
            trim("{$this->livraison_code_postal} {$this->livraison_ville}"),
            $this->livraison_pays,
            $this->transport_par?->getLabel(),
        ], filled(...)));
    }
}
