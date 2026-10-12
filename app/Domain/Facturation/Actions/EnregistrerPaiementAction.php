<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un paiement règle une ou plusieurs factures du même client (un virement
 * qui solde trois factures d'un coup), et son montant est exactement le
 * total qu'il impute sur elles (B3) : un trop-perçu ne devient jamais un
 * crédit, et chaque imputation reste au plus égale à ce qui reste dû de sa
 * facture. Ses imputations naissent avec lui et sont signées avec lui :
 * aucune ne s'ajoute après coup.
 *
 * Après ses imputations, le crédit d'avoir disponible du client solde ce
 * qui reste dû des factures qu'il règle, de la plus ancienne à la plus
 * récente, les avoirs les plus anciens d'abord (K9) : c'est le cas d'une
 * facture rouverte par la contre-passation d'un paiement saisi par erreur
 * (voir ContrePasserPaiementAction), qui a rendu à ses avoirs le crédit
 * que ce paiement avait imputé, ou quand le client a reçu un avoir
 * entre-temps. Jamais pour le client comptoir, qui n'a pas de crédit (voir
 * Client::soldeCrediteur()).
 *
 * Le client arrive déjà résolu par une requête limitée au magasin, jamais
 * en client_id brut : chaque facture imputée doit appartenir à CE client.
 * Un paiement s'enregistre le jour où il est reçu ou plus tard (un
 * virement vu hier sur le relevé), jamais à une date future.
 *
 * Le registre fiscal du magasin actif se verrouille avant toute lecture de
 * la transaction (voir RegistreFiscal) : deux paiements simultanés d'une
 * même facture se suivent, et le second relit sous ce verrou la facture et
 * ce qui en reste dû après le premier, qu'une lecture faite avant le
 * verrou aurait pu ignorer. Le paiement, ses imputations et le crédit
 * qu'il impute sont signés ensemble dans la chaîne des encaissements (voir
 * ContenuFiscal), dans la même transaction : sans clé de signature, rien
 * n'est enregistré.
 *
 * Son auteur est relu sous le verrou de sa ligne, après celui du registre
 * (voir LecteurDesDroits) : il doit pouvoir enregistrer un paiement, et
 * encaisser chaque facture qu'il règle (voir FacturePolicy::encaisser() :
 * un vendeur sans « voir celles de toute l'équipe » n'encaisse que les
 * siennes). Il n'encaisse que dans un magasin à la fois (voir
 * VerificateurCaissesPersonnelles). C'est lui que le paiement enregistre
 * et que le registre signe.
 */
class EnregistrerPaiementAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly VerificateurCaissesPersonnelles $caissesPersonnelles,
    ) {}

    /**
     * @param  array{mode_paiement: string, montant: float|string, reference?: ?string, notes?: ?string, recu_le: string}  $donneesPaiement
     * @param  array<int, array{facture_id: int|string, montant: float|string}>  $allocations
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Client $client, array $donneesPaiement, array $allocations): Paiement
    {
        if (Carbon::parse($donneesPaiement['recu_le'])->startOfDay()->gt(today())) {
            throw new LogicException('Un paiement ne peut pas être reçu à une date future.');
        }

        $montant = $this->calculateur->arrondirAuCentime((float) $donneesPaiement['montant']);
        $imputations = $this->imputationsDemandees($allocations);
        $totalImpute = $this->calculateur->sommer($imputations);

        if ($montant <= 0) {
            throw new LogicException('Le montant reçu doit être strictement positif.');
        }

        if ($montant !== $totalImpute) {
            throw new LogicException(sprintf('Le montant reçu (%s) doit être égal au total imputé sur les factures (%s).', self::enEuros($montant), self::enEuros($totalImpute)));
        }

        return DB::transaction(function () use ($auteur, $client, $donneesPaiement, $montant, $imputations) {
            $magasin = app(TenantContext::class)->magasin();
            $this->registre->verrouiller($magasin);
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Paiement::class);
            $this->caissesPersonnelles->refuserSiSaCaisseEstOuverteAilleurs($compte, $magasin);

            if ((int) $client->magasin_id !== $magasin->getKey()) {
                throw new LogicException('Ce client n\'est pas un client du magasin.');
            }

            return $this->operateur->pendant($compte, fn (): Paiement => $this->enregistrer($compte, $magasin, $client, $donneesPaiement, $montant, $imputations));
        });
    }

    /**
     * @param  array{mode_paiement: string, montant: float|string, reference?: ?string, notes?: ?string, recu_le: string}  $donneesPaiement
     * @param  array<int, float>  $imputations
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function enregistrer(User $compte, Magasin $magasin, Client $client, array $donneesPaiement, float $montant, array $imputations): Paiement
    {
        $paiement = new Paiement([
            ...$donneesPaiement,
            'client_id' => $client->getKey(),
            'montant' => $montant,
        ]);
        $paiement->forceFill(['cree_par' => $compte->getKey()])->save();
        $factures = [];

        foreach ($imputations as $factureId => $montantImpute) {
            $facture = Facture::query()->whereKey($factureId)->lockForUpdate()->firstOrFail();
            $this->verifierQuElleAttendCeReglement($facture, $client, $montantImpute);
            $this->lecteur->exiger($compte, 'encaisser', $magasin, $facture);

            PaiementAllocation::create([
                'paiement_id' => $paiement->id,
                'facture_id' => $facture->id,
                'montant_alloue' => $montantImpute,
            ]);

            $facture->constaterUnReglement();
            $factures[] = $facture;
        }

        if (! $client->estComptoir()) {
            $this->imputerLeCreditDisponible($paiement, $factures);
        }

        $paiement = $paiement->fresh();
        $this->registre->enregistrer(ChaineFiscale::Encaissement, 'paiement', $this->contenuFiscal->encaissement($paiement), $paiement);

        return $paiement;
    }

    /**
     * Le montant à imputer sur chaque facture, au centime, par identifiant
     * de facture : au moins une facture, chacune une seule fois, pour un
     * montant strictement positif.
     *
     * @param  array<int, array{facture_id: int|string, montant: float|string}>  $allocations
     * @return array<int, float>
     *
     * @throws LogicException
     */
    private function imputationsDemandees(array $allocations): array
    {
        if ($allocations === []) {
            throw new LogicException('Un paiement règle au moins une facture.');
        }

        $imputations = [];

        foreach ($allocations as $allocation) {
            $factureId = (int) $allocation['facture_id'];
            $montantImpute = $this->calculateur->arrondirAuCentime((float) $allocation['montant']);

            if ($montantImpute <= 0) {
                throw new LogicException('Le montant imputé sur une facture doit être strictement positif.');
            }

            if (isset($imputations[$factureId])) {
                throw new LogicException('Une facture ne s\'impute qu\'une fois par paiement.');
            }

            $imputations[$factureId] = $montantImpute;
        }

        return $imputations;
    }

    /**
     * La facture, relue sous verrou, est une facture émise de CE client qui
     * doit encore au moins ce montant.
     *
     * @throws LogicException
     */
    private function verifierQuElleAttendCeReglement(Facture $facture, Client $client, float $montantImpute): void
    {
        if ((int) $facture->client_id !== $client->getKey()) {
            throw new LogicException("La facture {$facture->numero} n'appartient pas au client de ce paiement.");
        }

        if (! $facture->statut->attendUnReglement()) {
            throw new LogicException("Impossible d'encaisser un paiement sur une facture au statut [{$facture->statut->value}].");
        }

        $resteDu = $facture->montantRestant();

        if ($montantImpute > $resteDu) {
            throw new LogicException(sprintf('Le montant imputé sur la facture %s (%s) dépasse ce qui en reste dû (%s).', $facture->numero, self::enEuros($montantImpute), self::enEuros($resteDu)));
        }
    }

    /**
     * Le crédit d'avoir disponible du client solde ce qui reste dû des
     * factures que règle le paiement, de la plus ancienne à la plus
     * récente, les avoirs les plus anciens d'abord : chaque crédit imputé
     * désigne ce paiement, qui le signe.
     *
     * @param  list<Facture>  $factures  relues sous verrou
     */
    private function imputerLeCreditDisponible(Paiement $paiement, array $factures): void
    {
        $facturesDues = collect($factures)
            ->filter(fn (Facture $facture): bool => $facture->montantRestant() > 0)
            ->sortBy(fn (Facture $facture): array => [$facture->date_emission?->getTimestamp(), $facture->id]);

        if ($facturesDues->isEmpty()) {
            return;
        }

        $avoirs = Avoir::query()
            ->where('client_id', $paiement->client_id)
            ->where('statut', StatutAvoir::Emise)
            ->orderBy('date_emission')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($facturesDues as $facture) {
            $resteDu = $facture->montantRestant();

            foreach ($avoirs as $avoir) {
                if ($resteDu <= 0) {
                    break;
                }

                $montantImpute = min($avoir->montantNonAlloue(), $resteDu);

                if ($montantImpute <= 0) {
                    continue;
                }

                AvoirAllocation::create([
                    'avoir_id' => $avoir->id,
                    'facture_id' => $facture->id,
                    'paiement_id' => $paiement->id,
                    'montant_alloue' => $montantImpute,
                ]);

                $resteDu = $this->calculateur->soustraire($resteDu, $montantImpute);
            }

            $facture->constaterUnReglement();
        }
    }

    private static function enEuros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }
}
