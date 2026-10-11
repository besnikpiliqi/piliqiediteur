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
use App\Domain\Facturation\Services\JourneesDeCaisse;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Tenancy\Models\Magasin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Agrège en un seul document fiscal tous les tickets de caisse (ventes à
 * des particuliers) d'une journée donnée, moins les avoirs sur des tickets
 * émis ce jour-là : la TVA d'une vente corrigée se régularise le jour de
 * l'avoir (art. 272 du CGI), et en caisse une correction est toujours une
 * opération en négatif, jamais une modification ni une suppression. Les
 * factures à des professionnels et leurs avoirs n'y figurent pas. Le type
 * du client, figé sur chaque document à son émission, le dit pour
 * toujours, même si la fiche du client change depuis (voir
 * Facture::scopeTickets() et Avoir::corrigeDesTickets()). La correction
 * d'une vente déjà agrégée passe par un avoir, compté dans la clôture du
 * jour où il est émis, jamais par une réouverture de la clôture.
 *
 * Les journées se clôturent une à une, dans l'ordre du calendrier des
 * journées de caisse (voir JourneesDeCaisse), et chacune une seule fois
 * (contrainte unique magasin_id/date_cloture) : une journée pas encore
 * commencée, hors des périodes d'activité du magasin, déjà clôturée,
 * antérieure à la dernière journée clôturée, ou précédée d'une journée de
 * caisse pas encore clôturée, est refusée (voir CloturerLesJourneesAction,
 * qui les clôture toutes dans l'ordre). Le numéro (Z-CODE-AAAA-NNNNNN)
 * prend l'année de la journée clôturée, et non celle du moment de la
 * clôture : la clôture du 31 décembre, faite à minuit le 1er janvier,
 * termine la série de son année. Les numéros suivent donc toujours l'ordre
 * des journées.
 *
 * Le registre fiscal du magasin se verrouille d'abord (voir
 * RegistreFiscal), comme pour toute émission, et la clôture note sa
 * journée sur la ligne de verrou (voir
 * RegistreFiscal::noterLaJourneeCloturee()) : un ticket émis pendant la
 * clôture de sa journée attend qu'elle soit terminée, relit cette journée
 * sous le verrou, puis se voit refusé (voir
 * ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()) ; aucune vente
 * ne s'ajoute à une journée déjà agrégée. Les ventes restées ouvertes à la
 * caisse ce jour-là sont abandonnées dans la même transaction (voir
 * PanierDeCaisse::abandonnerLesVentesOuvertesLe()).
 *
 * Une caisse personnelle restée ouverte ne retient jamais la clôture (R5) :
 * la clôture agrège les factures, jamais les relevés personnels, et ses
 * appelants ferment ensuite d'office, hors de sa transaction, les caisses
 * encore ouvertes (voir FermerLesCaissesPersonnellesAction), dont l'échec
 * ne la défait jamais.
 */
class CloturerCaisseAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
        private readonly PanierDeCaisse $panierDeCaisse,
        private readonly JourneesDeCaisse $journeesDeCaisse,
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
            $this->verifierQueLaJourneePeutEtreCloturee($magasin, $journee, $instant->enHeureLocale()->toDateString(), $this->registre->derniereJourneeCloturee($magasin));
            $this->panierDeCaisse->abandonnerLesVentesOuvertesLe($journee);

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

            $this->registre->noterLaJourneeCloturee($magasin, $journee);

            return $cloture;
        });
    }

    /**
     * @param  string  $journee  la journée à clôturer, AAAA-MM-JJ
     * @param  string  $aujourdhui  la journée en cours à l'heure de Paris, AAAA-MM-JJ
     * @param  string|null  $derniere  la dernière journée clôturée, lue sous le verrou du registre
     *
     * @throws LogicException
     */
    private function verifierQueLaJourneePeutEtreCloturee(Magasin $magasin, string $journee, string $aujourdhui, ?string $derniere): void
    {
        $enClair = static fn (string $jour): string => CarbonImmutable::parse($jour)->format('d/m/Y');

        if ($journee > $aujourdhui) {
            throw new LogicException("La journée du {$enClair($journee)} n'est pas encore commencée.");
        }

        if (! $this->journeesDeCaisse->estUneJourneeDActivite($magasin, $journee)) {
            throw new LogicException("Le magasin était fermé le {$enClair($journee)} : cette journée n'a pas de clôture.");
        }

        if ($derniere !== null && $journee <= $derniere) {
            throw new LogicException(ClotureCaisse::query()->duJour($journee)->exists()
                ? "La journée du {$enClair($journee)} est déjà clôturée."
                : "La journée du {$enClair($journee)} précède la dernière journée clôturée, celle du {$enClair($derniere)} : les journées se clôturent dans l'ordre.");
        }

        $enAttente = $this->journeesDeCaisse->aCloturer($magasin, CarbonImmutable::parse($journee)->subDay()->toDateString(), $derniere)->first();

        if ($enAttente !== null) {
            throw new LogicException("Clôturez d'abord la journée du {$enClair($enAttente)}.");
        }
    }
}
