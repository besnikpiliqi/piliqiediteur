<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Agrège en un seul document fiscal toutes les ventes à des clients
 * PARTICULIERS d'une journée donnée, moins les avoirs qui leur ont été
 * émis ce jour-là : la TVA d'une vente corrigée se régularise le jour de
 * l'avoir (art. 272 du CGI), et en caisse une correction est toujours une
 * opération en négatif, jamais une modification ni une suppression. Les ventes
 * et avoirs à des professionnels n'y figurent jamais, ils relèvent de
 * l'e-invoicing individuelle (voir Facture::relevantDeLeInvoicing). La
 * correction d'une vente déjà agrégée passe par un avoir, compté dans la
 * clôture du jour où il est émis, jamais par une réouverture de la
 * clôture.
 *
 * Les journées se clôturent une à une, dans l'ordre du calendrier, et
 * chacune une seule fois (contrainte unique magasin_id/date_cloture) :
 * une journée pas encore commencée, déjà clôturée, antérieure à la
 * dernière journée clôturée, ou dont la veille n'est pas clôturée alors
 * que le magasin vendait déjà, est refusée (voir CloturerLesJourneesAction,
 * qui les clôture toutes dans l'ordre). Le numéro (Z-CODE-AAAA-NNNNNN)
 * prend l'année de la journée clôturée, et non celle du moment de la
 * clôture : la clôture du 31 décembre, faite à minuit le 1er janvier,
 * termine la série de son année. Les numéros suivent donc toujours l'ordre
 * des journées.
 *
 * Le registre fiscal du magasin se verrouille d'abord (voir
 * RegistreFiscal), comme pour toute émission : un ticket émis pendant la
 * clôture de sa journée attend qu'elle soit terminée, puis se voit refusé
 * (voir ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()) ; aucune
 * vente ne s'ajoute à une journée déjà agrégée.
 */
class CloturerCaisseAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
    ) {}

    /**
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Magasin $magasin, CarbonInterface|string $date): ClotureCaisse
    {
        $journee = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($magasin, $journee) {
            $instant = $this->registre->verrouiller($magasin);
            $this->verifierQueLaJourneePeutEtreCloturee($journee, $instant->enHeureLocale()->toDateString());

            $ventes = Facture::query()->enAttenteDeCloture($journee)->lockForUpdate()->get();
            $avoirs = Avoir::query()->enAttenteDeCloture($journee)->lockForUpdate()->get();

            $cloture = ClotureCaisse::create(['date_cloture' => $journee]);

            $numero = $this->numerotation->suivant($magasin, TypeDocument::ClotureCaisse, CarbonImmutable::parse($journee)->format('Y'));

            $cloture->forceFill([
                'numero' => $numero,
                'nombre_ventes' => $ventes->count(),
                'nombre_avoirs' => $avoirs->count(),
                ...$this->calculateur->deduire($this->calculateur->cumuler($ventes), $this->calculateur->cumuler($avoirs)),
                'cloturee_a' => now(),
            ])->save();

            // Seule écriture, avec le statut, encore faite sur des documents
            // émis : un update() en masse, qui ne passe pas par
            // ProtegeLesDonneesValidees. L'étape des clôtures, calculées
            // depuis le registre fiscal, la supprimera.
            if ($ventes->isNotEmpty()) {
                Facture::query()
                    ->whereIn('id', $ventes->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            if ($avoirs->isNotEmpty()) {
                Avoir::query()
                    ->whereIn('id', $avoirs->pluck('id'))
                    ->update(['cloture_caisse_id' => $cloture->id]);
            }

            return $cloture;
        });
    }

    /**
     * @param  string  $journee  la journée à clôturer, AAAA-MM-JJ
     * @param  string  $aujourdhui  la journée en cours à l'heure de Paris, AAAA-MM-JJ
     *
     * @throws LogicException
     */
    private function verifierQueLaJourneePeutEtreCloturee(string $journee, string $aujourdhui): void
    {
        $enClair = static fn (string $jour): string => CarbonImmutable::parse($jour)->format('d/m/Y');

        if ($journee > $aujourdhui) {
            throw new LogicException("La journée du {$enClair($journee)} n'est pas encore commencée.");
        }

        if (ClotureCaisse::query()->duJour($journee)->exists()) {
            throw new LogicException("La journée du {$enClair($journee)} est déjà clôturée.");
        }

        $derniere = ClotureCaisse::derniereJourneeCloturee();

        if ($derniere !== null && $journee < $derniere) {
            throw new LogicException("La journée du {$enClair($journee)} précède la dernière journée clôturée, celle du {$enClair($derniere)} : les journées se clôturent dans l'ordre.");
        }

        $veille = CarbonImmutable::parse($journee)->subDay()->toDateString();
        $premiereVente = Facture::jourDeLaPremiereVente();

        if ($premiereVente !== null && $premiereVente <= $veille && ClotureCaisse::query()->duJour($veille)->doesntExist()) {
            throw new LogicException("Clôturez d'abord la journée du {$enClair($veille)}.");
        }
    }
}
