<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\InstantFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Exceptions\TicketZNonSigneException;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\JourneesDeCaisse;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Agrège en un seul document fiscal tous les tickets de caisse (ventes à
 * des particuliers) d'une journée donnée, moins les avoirs sur des tickets
 * émis ce jour-là : la TVA d'une vente corrigée se régularise le jour de
 * l'avoir (art. 272 du CGI), et en caisse une correction est toujours une
 * opération en négatif, jamais une modification ni une suppression. Ce
 * net (total_ht, total_tva, total_ttc) est la base de l'e-reporting : les
 * factures à des professionnels et leurs avoirs n'y figurent pas. Le type
 * du client, figé sur chaque document à son émission, le dit pour
 * toujours, même si la fiche du client change depuis (voir
 * Facture::scopeTickets() et Avoir::corrigeDesTickets()). La correction
 * d'une vente déjà agrégée passe par un avoir, compté dans la clôture du
 * jour où il est émis, jamais par une réouverture de la clôture.
 *
 * La clôture garde aussi, écrit une seule fois, son récapitulatif (voir
 * RecapitulatifDeCloture) : les ventes et les avoirs séparés, par taux,
 * dont le net se déduit ; les factures et les avoirs aux professionnels,
 * pour information ; les encaissements par mode, chaque paiement comptant
 * dans une seule clôture, celle de la journée où il est enregistré, ou la
 * suivante s'il l'est après la clôture de cette journée ; le crédit
 * d'avoir imputé et les grands totaux perpétuels après le dernier document
 * qu'elle compte.
 * Son ticket Z s'écrit en une seule fois, déjà numéroté, dans une table
 * en ajout seul. Puis elle s'inscrit au journal des événements (JET 50,
 * clôture de période), dans sa transaction et sous le verrou du registre,
 * qui signe tout le ticket Z : son numéro, sa journée, ses nombres de
 * tickets et d'avoirs, son net, ses encaissements par mode, son crédit
 * d'avoir imputé et ses grands totaux, en centimes, son récapitulatif
 * entier tel qu'il est écrit et les dernières séquences des ventes et des
 * encaissements qu'elle compte ; l'en-tête signe son auteur et son
 * instant. Sans cet événement, la base refuse le ticket Z à la validation
 * de la transaction (voir GardesDesClotures). Manuelle, de minuit,
 * rattrapée, à zéro ou dans le magasin de démonstration, chaque clôture
 * en inscrit une, et une clôture refusée aucune.
 *
 * La clôture précédente est la dernière clôture signée au registre (voir
 * RegistreFiscal::derniereClotureSignee()), jamais la dernière ligne de la
 * table des tickets Z : ce que compte la clôture part des séquences
 * qu'elle a signées. Un ticket Z que le registre ne connaît pas (daté
 * après la dernière journée qu'il a close, ou sans son événement 50) n'a
 * pas été produit par une clôture : la clôture du magasin s'arrête (voir
 * TicketZNonSigneException), l'éditeur est alerté au journal de
 * l'application et l'anomalie s'inscrit au journal des événements (JET
 * 90), jusqu'à ce que sa procédure le retire sur pièces.
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
 *
 * Une clôture est faite par une personne (handle()) : relue sous le verrou
 * de sa ligne, après celui du registre (voir LecteurDesDroits), elle doit
 * pouvoir clôturer la journée ; la clôture l'enregistre comme auteur, avec
 * son nom figé, et le registre signe à son nom. Ou par le système
 * (parLeSysteme()) : la clôture automatique de minuit et ses journées
 * rattrapées, sans auteur (voir CloturerLesJourneesAction).
 */
class CloturerCaisseAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly RecapitulatifDeCloture $recapitulatif,
        private readonly RegistreFiscal $registre,
        private readonly PanierDeCaisse $panierDeCaisse,
        private readonly JourneesDeCaisse $journeesDeCaisse,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * La clôture de cette journée du calendrier (AAAA-MM-JJ) par son
     * auteur.
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Magasin $magasin, string $journee): ClotureCaisse
    {
        return $this->arreteeParUnTicketZNonSigne($magasin, fn (): ClotureCaisse => DB::transaction(function () use ($auteur, $magasin, $journee): ClotureCaisse {
            $instant = $this->registre->verrouiller($magasin);
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'cloturer', $magasin, ClotureCaisse::class);

            return $this->operateur->pendant($compte, fn (): ClotureCaisse => $this->cloturer($compte, $magasin, $journee, $instant));
        }));
    }

    /**
     * La clôture de cette journée du calendrier (AAAA-MM-JJ) par le
     * système, sans auteur : la clôture automatique de minuit et ses
     * journées rattrapées.
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function parLeSysteme(Magasin $magasin, string $journee): ClotureCaisse
    {
        return $this->arreteeParUnTicketZNonSigne($magasin, fn (): ClotureCaisse => DB::transaction(function () use ($magasin, $journee): ClotureCaisse {
            $instant = $this->registre->verrouiller($magasin);

            return $this->operateur->pendantLeSysteme(fn (): ClotureCaisse => $this->cloturer(null, $magasin, $journee, $instant));
        }));
    }

    /**
     * Signale un ticket Z non signé qui arrête la clôture : l'éditeur est
     * alerté au journal de l'application, et l'anomalie s'inscrit au
     * journal des événements du magasin (JET 90), dans sa propre
     * transaction, au nom du système, quand plus aucune transaction de
     * l'application n'est ouverte : celle de la clôture refusée est déjà
     * annulée, et une transaction encore ouverte autour d'elle l'annulerait
     * aussi. Son appelant le signale alors lui-même, une fois sa propre
     * transaction finie (voir CloturerLesJourneesAction::parUnePersonne()).
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function signalerLeTicketZNonSigne(Magasin $magasin, TicketZNonSigneException $refus): void
    {
        if (! $this->registre->horsDeToutTransaction()) {
            return;
        }

        Log::critical('Caisse sécurisée : un ticket Z que le registre fiscal ne connaît pas arrête la clôture du magasin.', [
            'magasin_id' => $magasin->getKey(),
            'cloture_id' => $refus->clotureId,
            'numero' => $refus->numero,
            'journee' => $refus->journee,
        ]);

        DB::transaction(function () use ($magasin, $refus): void {
            $this->registre->verrouiller($magasin);
            $this->operateur->pendantLeSysteme(fn () => $this->registre->journaliser($refus->anomalie()->code, $refus->anomalie()->details()));
        });
    }

    /**
     * La clôture, que signale puis laisse échouer un ticket Z non signé
     * (voir signalerLeTicketZNonSigne()).
     *
     * @param  Closure(): ClotureCaisse  $cloture
     *
     * @throws TicketZNonSigneException
     */
    private function arreteeParUnTicketZNonSigne(Magasin $magasin, Closure $cloture): ClotureCaisse
    {
        try {
            return $cloture();
        } catch (TicketZNonSigneException $refus) {
            $this->signalerLeTicketZNonSigne($magasin, $refus);

            throw $refus;
        }
    }

    /**
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function cloturer(?User $compte, Magasin $magasin, string $journee, InstantFiscal $instant): ClotureCaisse
    {
        $derniereJournee = $this->registre->derniereJourneeCloturee($magasin);
        $this->refuserUnTicketZNonSigne($derniereJournee);
        $this->verifierQueLaJourneePeutEtreCloturee($magasin, $journee, $instant->jour(), $derniereJournee);
        $this->panierDeCaisse->abandonnerLesVentesOuvertesLe($journee);

        $ventes = Facture::query()->enAttenteDeCloture($instant->calendrier(), $journee)->lockForUpdate()->get();
        $avoirs = Avoir::query()->enAttenteDeCloture($instant->calendrier(), $journee)->lockForUpdate()->get();
        $precedente = $this->registre->derniereClotureSignee($magasin);
        $etabli = $this->recapitulatif->etablir($magasin, $journee, $ventes, $avoirs, $precedente['derniere_sequence_vente'] ?? null, $precedente['derniere_sequence_encaissement'] ?? null);
        $recapitulatif = $etabli['recapitulatif'];
        $ventesNettes = $recapitulatif['ventes_particuliers'];
        $avoirsNets = $recapitulatif['avoirs_particuliers'];
        $net = ['ht' => $ventesNettes['ht'] + $avoirsNets['ht'], 'tva' => $ventesNettes['tva'] + $avoirsNets['tva'], 'ttc' => $ventesNettes['ttc'] + $avoirsNets['ttc']];

        $cloture = (new ClotureCaisse(['date_cloture' => $journee]))->forceFill([
            'numero' => $this->numerotation->suivant($magasin, TypeDocument::ClotureCaisse, substr($journee, 0, 4)),
            'cree_par' => $compte?->getKey(),
            'nombre_ventes' => $ventes->count(),
            'nombre_avoirs' => $avoirs->count(),
            'total_ht' => $this->calculateur->enDecimal($net['ht']),
            'total_tva' => $this->calculateur->enDecimal($net['tva']),
            'total_ttc' => $this->calculateur->enDecimal($net['ttc']),
            'recapitulatif' => $recapitulatif,
            'derniere_sequence_vente' => $etabli['derniere_sequence_vente'],
            'derniere_sequence_encaissement' => $etabli['derniere_sequence_encaissement'],
            'cloturee_a' => $instant->utc,
        ]);
        $cloture->save();

        // Seule écriture, avec le statut, encore faite sur des documents
        // émis : un update() en masse, qui ne passe pas par
        // ProtegeLesDonneesValidees, et que la garde des documents émis
        // laisse passer (voir GardesDesDocumentsEmis) ; la base ne la
        // permet que vers ce ticket Z, le dernier du magasin, puis la fige
        // (voir GardesDesClotures). L'étape des clôtures, calculées depuis
        // le registre fiscal, la supprimera.
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

        $this->registre->journaliser(CodeEvenement::CloturePeriode, [
            'numero' => $cloture->numero,
            'journee' => $journee,
            'nombre_ventes' => $cloture->nombre_ventes,
            'nombre_avoirs' => $cloture->nombre_avoirs,
            'total_ht' => $net['ht'],
            'total_tva' => $net['tva'],
            'total_ttc' => $net['ttc'],
            'encaissements' => array_column($recapitulatif['encaissements'], 'montant', 'mode'),
            'credit_avoir_impute' => $recapitulatif['credit_avoir_impute'],
            'grands_totaux' => $recapitulatif['grands_totaux'],
            'recapitulatif' => $recapitulatif,
            'derniere_sequence_vente' => $etabli['derniere_sequence_vente'],
            'derniere_sequence_encaissement' => $etabli['derniere_sequence_encaissement'],
        ], $cloture);
        $this->registre->noterLaJourneeCloturee($magasin, $journee);

        return $cloture;
    }

    /**
     * Refuse de clôturer tant qu'un ticket Z du magasin actif n'a pas été
     * produit par une clôture : daté après la dernière journée close au
     * registre, ou sans l'événement 50 qui le signe. Lu sous le verrou du
     * registre, sans verrouiller aucun ticket Z (table en ajout seul).
     *
     * @param  string|null  $derniereJournee  la dernière journée close au registre, AAAA-MM-JJ
     *
     * @throws TicketZNonSigneException
     */
    private function refuserUnTicketZNonSigne(?string $derniereJournee): void
    {
        $nonSigne = ClotureCaisse::query()
            ->where(function (Builder $tickets) use ($derniereJournee): void {
                $tickets->whereNotExists(fn (QueryBuilder $evenements) => $evenements
                    ->selectRaw('1')
                    ->from('enregistrements_fiscaux')
                    ->whereColumn('enregistrements_fiscaux.magasin_id', 'clotures_caisse.magasin_id')
                    ->where('enregistrements_fiscaux.chaine', ChaineFiscale::Evenement->value)
                    ->where('enregistrements_fiscaux.type', (string) CodeEvenement::CloturePeriode->value)
                    ->where('enregistrements_fiscaux.document_type', (new ClotureCaisse)->getMorphClass())
                    ->whereColumn('enregistrements_fiscaux.document_id', 'clotures_caisse.id'));

                if ($derniereJournee !== null) {
                    $tickets->orWhere('date_cloture', '>', $derniereJournee);
                }
            })
            ->orderBy('date_cloture')
            ->first();

        if ($nonSigne !== null) {
            throw new TicketZNonSigneException(CleDuModele::de($nonSigne), (string) $nonSigne->numero, $nonSigne->date_cloture);
        }
    }

    /**
     * @param  string  $journee  la journée à clôturer, AAAA-MM-JJ
     * @param  string  $aujourdhui  la journée en cours, AAAA-MM-JJ
     * @param  string|null  $derniere  la dernière journée clôturée, lue sous le verrou du registre
     *
     * @throws LogicException
     */
    private function verifierQueLaJourneePeutEtreCloturee(Magasin $magasin, string $journee, string $aujourdhui, ?string $derniere): void
    {
        if (Calendrier::jourValide($journee) === null) {
            throw new LogicException("« {$journee} » n'est pas une journée : une journée s'écrit AAAA-MM-JJ.");
        }

        $enClair = static fn (string $jour): string => Calendrier::afficherJour($jour, 'd/m/Y');

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

        $enAttente = $this->journeesDeCaisse->aCloturer($magasin, Calendrier::ajouterJours($journee, -1), $derniere)->first();

        if ($enAttente !== null) {
            throw new LogicException("Clôturez d'abord la journée du {$enClair($enAttente)}.");
        }
    }
}
