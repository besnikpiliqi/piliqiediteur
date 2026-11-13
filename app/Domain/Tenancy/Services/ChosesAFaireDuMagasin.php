<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneRecuPaiement;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\PosteReconnuResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ServiceResource;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Filament\Societe\Resources\MagasinResource;
use App\Models\User;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as RequeteSql;
use Illuminate\Support\Collection;
use LogicException;

/**
 * L'« À faire » du tableau de bord d'un magasin (demande de l'éditeur du
 * 26/10/2026, voir AFaireApercu) : tout ce qui reste en attente dans ce
 * magasin pour ce compte, calculé à chaque affichage depuis les données,
 * jamais depuis les notifications, chaque chose avec son nombre, une
 * phrase qui dit ce qu'il faut faire, et le lien vers la fiche quand il
 * n'y a qu'un document, sinon vers la liste filtrée qui montre exactement
 * ces documents (le même filtre sert au compte et à la liste).
 *
 * Deux parties (règle 2) : « À faire », ce que la personne fait elle-même,
 * parce qu'elle a, cochée dans ce magasin, l'autorisation du geste ; « À
 * surveiller », ce que son équipe doit faire, pour le propriétaire, un
 * administrateur ou un responsable du magasin qui voit ces documents sans
 * avoir ce geste, avec « Voir » pour seul bouton. Le propriétaire, qui ne
 * vend jamais dans un magasin réel, n'a donc les ventes et les
 * encaissements qu'à surveiller. Les cases se lisent telles qu'elles sont
 * cochées (voir User::autorisationsDans()), pas le droit d'agir du
 * moment : dans un magasin en lecture seule, ce qui reste dû reste, et
 * son bouton ne dit plus que « Voir ». Les avoirs non utilisés sont
 * toujours à surveiller, pour qui voit les avoirs (décision de l'éditeur
 * du 01/11/2026).
 *
 * Un compte d'un cabinet d'expertise comptable lié et un expert-comptable
 * de la société n'en voient rien du tout (règles 3 et 9 : « ce n'est pas
 * son problème »).
 *
 * Ordre (règle 7, choix de présentation) : par urgence (rouge, orange,
 * gris), puis par date, la plus ancienne d'abord (voir
 * ChoseAFaire::comparer()) ; rien ne se masque.
 *
 * Isolation : toutes les lectures se font sous MagasinScope, dans ce seul
 * magasin, qui doit être le magasin actif ; aucune ne lit un autre
 * magasin ni une autre société. Les montants se calculent en centimes,
 * depuis les montants enregistrés, jamais par une somme que la base ferait
 * sur une colonne décimale.
 */
final class ChosesAFaireDuMagasin
{
    /**
     * Combien de documents une chose énumère sous sa phrase au plus : les
     * plus anciens.
     */
    public const LIGNES_ENUMEREES = 10;

    /** @var list<string> */
    private readonly array $cases;

    private readonly Calendrier $calendrier;

    private readonly string $aujourdhui;

    private readonly CalculateurLigne $calculateur;

    private function __construct(private readonly User $compte, private readonly Magasin $magasin)
    {
        $this->cases = $compte->autorisationsDans($magasin);
        $this->calendrier = $magasin->calendrier();
        $this->aujourdhui = $this->calendrier->aujourdhui();
        $this->calculateur = app(CalculateurLigne::class);
    }

    /**
     * Ce qui reste à faire dans ce magasin, le magasin actif, pour ce
     * compte : les deux parties, chacune dans son ordre.
     *
     * @return array{a_faire: list<ChoseAFaire>, a_surveiller: list<ChoseAFaire>}
     *
     * @throws LogicException quand ce magasin n'est pas le magasin actif
     */
    public static function pour(User $compte, Magasin $magasin): array
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new LogicException('L\'« À faire » ne se lit que pour le magasin actif.');
        }

        if ($compte->entreParLesAutorisationsDUnCabinet() || ! $compte->peutAgir() || $magasin->societe_id !== $compte->societe_id) {
            return ['a_faire' => [], 'a_surveiller' => []];
        }

        $choses = (new self($compte, $magasin))->toutes();
        usort($choses, ChoseAFaire::comparer(...));

        return [
            'a_faire' => array_values(array_filter($choses, fn (ChoseAFaire $chose): bool => ! $chose->aSurveiller)),
            'a_surveiller' => array_values(array_filter($choses, fn (ChoseAFaire $chose): bool => $chose->aSurveiller)),
        ];
    }

    /**
     * @return list<ChoseAFaire>
     */
    private function toutes(): array
    {
        $articles = $this->magasin->societe->aDesArticles();

        return array_values(array_filter([
            $this->facturesAEncaisser(),
            $articles ? $this->preuvesAFournir() : null,
            $articles ? $this->exonerationsNonProuvees() : null,
            $articles ? $this->visasANoter() : null,
            $this->brouillonsDeFacture(),
            ...$this->emailsEnEchec(),
            $this->venteEnCoursALaCaisse(),
            $this->brouillonsDeDevis(),
            $this->devisQuiExpirent(),
            $this->devisATransformer(),
            $this->maCaisseOuverte(),
            $this->caissesDeLEquipe(),
            ...$this->journeesACloturer(),
            $articles ? $this->achatsEnBrouillon() : null,
            $articles ? $this->piecesManquantesDesAchats() : null,
            $articles ? $this->piecesManquantesDesAvoirsFournisseur() : null,
            $articles ? $this->inventairesEnBrouillon() : null,
            $articles ? $this->stockNegatif() : null,
            $articles ? $this->stockBas() : null,
            $this->catalogueVide(),
            $this->societeAVerifier(),
            $this->membreDUnAssujettiUnique(),
            $this->etablissementAConfirmer(),
            $this->identiteIncomplete(),
            $this->monMotDePasse(),
            $this->motsDePasseDeLEquipe(),
            $this->aucunPosteReconnu(),
            ...$this->avoirsNonUtilises(),
        ]));
    }

    /**
     * Les factures et les tickets à encaisser (voir
     * Facture::scopeAEncaisser()) parmi ceux que le compte voit (les
     * siens, ou tous avec « voir celles de toute l'équipe ») : à faire
     * pour qui peut encaisser, même sans pouvoir contre-passer (règle 4 et
     * précision de l'éditeur du 02/11/2026) ; à surveiller pour le
     * propriétaire, un administrateur ou un responsable qui les voit sans
     * encaisser, et pour un membre autorisé à contre-passer (point 16 de
     * la série 8). Leur nombre, leur restant dû, ceux en retard et ceux
     * dont un paiement a été contre-passé, puis les dix plus anciens par
     * échéance.
     */
    private function facturesAEncaisser(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::PAIEMENT_CREER);

        if (! $this->a(PermissionRegistry::FACTURE_VIEW) || (! $aFaire && ! $this->estSuperviseur() && ! $this->a(PermissionRegistry::PAIEMENT_CONTRE_PASSER))) {
            return null;
        }

        $factures = Facture::query()
            ->aEncaisser()
            ->visiblesPar($this->compte)
            ->with(['allocationsPaiement:id,facture_id,montant_alloue', 'allocationsAvoir:id,facture_id,montant_alloue'])
            ->withExists(['allocationsPaiement as paiement_contre_passe' => fn (Builder $allocations): Builder => $allocations
                ->whereHas('paiement', fn (Builder $paiements): Builder => $paiements->whereNotNull('contre_passe_paiement_id'))])
            ->orderBy('date_echeance')
            ->orderBy('id')
            ->get()
            ->each(fn (Facture $facture): Facture => $facture->setRelation('magasin', $this->magasin));

        if ($factures->isEmpty()) {
            return null;
        }

        $totaux = Facture::totauxAEncaisser($factures);
        $contrePassees = $factures->filter(fn (Facture $facture): bool => (bool) $facture->getAttribute('paiement_contre_passe'))->count();
        $precision = $this->euros($totaux['restant_du']).' restant dû'
            .($totaux['nombre_en_retard'] > 0 ? ', dont '.trans_choice('{1} 1 en retard|[2,*] :count en retard', $totaux['nombre_en_retard']).' ('.$this->euros($totaux['restant_du_en_retard']).')' : '')
            .($contrePassees > 0 ? ($totaux['nombre_en_retard'] > 0 ? ' et ' : ', dont ').trans_choice('{1} 1 dont un paiement a été contre-passé|[2,*] :count dont un paiement a été contre-passé', $contrePassees) : '')
            .'.';
        $seule = $factures->count() === 1 ? $factures->first() : null;

        return new ChoseAFaire(
            cle: 'a_encaisser',
            groupe: 'Ventes',
            urgence: $totaux['nombre_en_retard'] > 0 || $contrePassees > 0 ? UrgenceAFaire::Rouge : UrgenceAFaire::Orange,
            nombre: $totaux['nombre'],
            phrase: trans_choice('{1} 1 facture à encaisser|[2,*] :count factures à encaisser', $totaux['nombre']),
            url: $seule === null ? FactureResource::urlDesFacturesAEncaisser() : FactureResource::getUrl('view', ['record' => $seule]),
            bouton: $this->bouton('Encaisser', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: ValeursLues::texteOuNul($factures->first()->date_echeance),
            precision: $this->avecLaLectureSeule($precision, $aFaire, permisEnLectureSeule: false),
            lignes: array_values($factures->take(self::LIGNES_ENUMEREES)->map(fn (Facture $facture): array => [
                'libelle' => (string) $facture->numero,
                'url' => FactureResource::getUrl('view', ['record' => $facture]),
                'description' => $facture->nomDuClient(),
                'montant' => $this->euros($facture->resteDuEnCentimes()),
                'date' => $facture->date_echeance === null ? null : Calendrier::afficherJour($facture->date_echeance, 'd/m/Y'),
                'date_en_rouge' => $facture->estEchue(),
                'badge' => $facture->getAttribute('paiement_contre_passe') ? 'Paiement contre-passé' : null,
            ])->all()),
            lienDesLignes: ['libelle' => 'Voir toutes les factures à encaisser', 'url' => FactureResource::urlDesFacturesAEncaisser()],
        );
    }

    /**
     * Les ventes hors de France qui attendent une preuve de leur
     * exonération (voir PreuvesRequises), parmi les factures que le compte
     * voit : à faire pour qui note les preuves, même dans un magasin en
     * lecture seule (décision de l'éditeur du 01/11/2026, Q-AF12). En
     * rouge dès qu'une preuve de douane (MRN ou CN23) manque depuis plus de
     * 30 jours après la date de la facture, ou que l'attestation de
     * l'acquéreur a passé son délai (Q-AF10, voir PreuvesRequises::enRetard()).
     */
    private function preuvesAFournir(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_JUSTIFIER);

        if (! $this->voit(PermissionRegistry::FACTURE_VIEW, $aFaire)) {
            return null;
        }

        $factures = Facture::query()
            ->preuvesAFournir()
            ->visiblesPar($this->compte)
            ->with('preuvesOperation:id,facture_id,type')
            ->orderBy('date_emission')
            ->orderBy('id')
            ->get()
            ->each(fn (Facture $facture): Facture => $facture->setRelation('magasin', $this->magasin));

        if ($factures->isEmpty()) {
            return null;
        }

        $preuves = app(PreuvesRequises::class);
        $enRetard = $factures->filter(fn (Facture $facture): bool => $preuves->enRetard($facture, $this->aujourdhui));
        $seule = $factures->count() === 1 ? $factures->first() : null;
        $precision = $seule !== null
            ? 'À fournir : '.implode(' ; ', $preuves->manquantes($seule)).'.'
            : 'Le n° MRN de la déclaration en douane ou le n° du CN23 de La Poste, ou les documents de transport.';

        return new ChoseAFaire(
            cle: 'preuves',
            groupe: 'Ventes hors de France',
            urgence: $enRetard->isNotEmpty() ? UrgenceAFaire::Rouge : UrgenceAFaire::Orange,
            nombre: $factures->count(),
            phrase: $seule !== null
                ? '1 vente hors de France attend sa preuve : '.$seule->numero.($seule->nomDuClient() === null ? '' : ', '.$seule->nomDuClient()).'.'
                : $factures->count().' ventes hors de France attendent leur preuve d\'export ou de livraison'.($enRetard->isNotEmpty() ? ', dont '.trans_choice('{1} 1 en retard|[2,*] :count en retard', $enRetard->count()) : '').'.',
            url: $seule === null ? FactureResource::urlDesPreuvesAFournir() : FactureResource::getUrl('view', ['record' => $seule]),
            bouton: $aFaire ? 'Ajouter la preuve' : 'Voir',
            aSurveiller: ! $aFaire,
            date: $this->jourDe($factures->first()->date_emission),
            precision: $precision,
            lignes: $seule !== null ? [] : array_values($factures->take(self::LIGNES_ENUMEREES)->map(fn (Facture $facture): array => [
                'libelle' => (string) $facture->numero,
                'url' => FactureResource::getUrl('view', ['record' => $facture]),
                'description' => $facture->nomDuClient(),
                'montant' => null,
                'date' => $this->afficherLeJourDe($facture->date_emission),
                'date_en_rouge' => $enRetard->contains($facture),
                'badge' => $enRetard->contains($facture) ? 'En retard' : null,
            ])->all()),
        );
    }

    /**
     * La société est déclarée membre d'un assujetti unique (groupe TVA,
     * décision P3.1, voir Magasin::REFUS_ASSUJETTI_UNIQUE) : aucun magasin
     * réel ne vend, et chaque compte du magasin le lit ici, en rouge, avec
     * la raison, puisque les gestes de vente ne s'y montrent plus. À faire
     * pour qui règle les paramètres fiscaux de la société (le propriétaire,
     * un administrateur autorisé), qui y mène, à surveiller pour les
     * autres ; jamais dans le magasin de démonstration, qui vend encore.
     */
    private function membreDUnAssujettiUnique(): ?ChoseAFaire
    {
        $societe = $this->magasin->societe;

        if ($this->magasin->est_demonstration || ! $societe->membre_assujetti_unique) {
            return null;
        }

        $regle = $this->compte->can('voirLesParametresFiscaux', $societe);

        return new ChoseAFaire(
            cle: 'assujetti_unique',
            groupe: 'Magasin',
            urgence: UrgenceAFaire::Rouge,
            nombre: 1,
            phrase: Magasin::REFUS_ASSUJETTI_UNIQUE,
            url: $regle ? ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $societe) : null,
            bouton: 'Voir',
            aSurveiller: ! $regle,
        );
    }

    /**
     * Les exports hors de l'Union et les livraisons vers un DOM sans preuve
     * de sortie 150 jours après leur date (T29.3, voir
     * RegularisationDUneExoneration), parmi les factures que le compte
     * voit, toujours en rouge : à faire pour qui vend (créer et émettre une
     * facture, faire un avoir), qui la régularise depuis sa fiche ; à
     * surveiller pour qui supervise et les voit. Le rappel de leur preuve
     * reste à côté (voir preuvesAFournir()). Dans un magasin qui ne peut
     * pas vendre (lecture seule, société membre d'un assujetti unique,
     * établissement à confirmer, voir Magasin::refusDeVente()), rien ne
     * s'émet et la fiche n'offre pas le geste : le bouton ne dit que
     * « Voir », et la précision dit pourquoi.
     */
    private function exonerationsNonProuvees(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_CREATE) && $this->a(PermissionRegistry::FACTURE_EMETTRE) && $this->a(PermissionRegistry::AVOIR_CREER);

        if (! $this->voit(PermissionRegistry::FACTURE_VIEW, $aFaire)) {
            return null;
        }

        $factures = Facture::query()
            ->exonerationsNonProuvees($this->calendrier, $this->aujourdhui)
            ->visiblesPar($this->compte)
            ->orderBy('date_emission')
            ->orderBy('id')
            ->get()
            ->each(fn (Facture $facture): Facture => $facture->setRelation('magasin', $this->magasin));

        if ($factures->isEmpty()) {
            return null;
        }

        $seule = $factures->count() === 1 ? $factures->first() : null;
        $refusDeVente = $aFaire ? $this->magasin->refusDeVente() : null;

        return new ChoseAFaire(
            cle: 'exonerations_non_prouvees',
            groupe: 'Ventes hors de France',
            urgence: UrgenceAFaire::Rouge,
            nombre: $factures->count(),
            phrase: $seule !== null
                ? '1 vente hors de France sans preuve de sortie 150 jours après sa date : '.$seule->numero.($seule->nomDuClient() === null ? '' : ', '.$seule->nomDuClient()).'.'
                : $factures->count().' ventes hors de France sans preuve de sortie 150 jours après leur date.',
            url: $seule === null ? FactureResource::urlDesExonerationsNonProuvees() : FactureResource::getUrl('view', ['record' => $seule]),
            bouton: $aFaire && $refusDeVente === null ? 'Régulariser' : 'Voir',
            aSurveiller: ! $aFaire,
            date: $this->jourDe($factures->first()->date_emission),
            precision: 'L\'exonération se régularise : un avoir sans retour en stock, puis une facture à la TVA française au même prix TTC.'.($refusDeVente === null ? '' : ' '.$refusDeVente),
            lignes: $seule !== null ? [] : array_values($factures->take(self::LIGNES_ENUMEREES)->map(fn (Facture $facture): array => [
                'libelle' => (string) $facture->numero,
                'url' => FactureResource::getUrl('view', ['record' => $facture]),
                'description' => $facture->nomDuClient(),
                'montant' => null,
                'date' => $this->afficherLeJourDe($facture->date_emission),
                'date_en_rouge' => true,
                'badge' => 'Exonération non prouvée',
            ])->all()),
        );
    }

    /**
     * Les tickets détaxés dont le bordereau qui fait foi attend la date de
     * son visa à la sortie de l'Union (décision de l'éditeur du 01/11/2026,
     * Q-AF13, voir Facture::scopeVisaANoter()), parmi ceux que le compte
     * voit : à faire pour qui note les bordereaux, même dans un magasin en
     * lecture seule ; à surveiller pour qui supervise et les voit. Datée
     * du plus ancien bordereau noté.
     */
    private function visasANoter(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_JUSTIFIER);

        if (! $this->voit(PermissionRegistry::FACTURE_VIEW, $aFaire)) {
            return null;
        }

        $tickets = Facture::query()
            ->visaANoter()
            ->visiblesPar($this->compte)
            ->with('bordereauDetaxe')
            ->get()
            ->sortBy(fn (Facture $ticket): string => (string) $ticket->bordereauDetaxe?->created_at?->getTimestamp())
            ->values();

        if ($tickets->isEmpty()) {
            return null;
        }

        $seul = $tickets->count() === 1 ? $tickets->first() : null;

        return new ChoseAFaire(
            cle: 'visas_detaxe',
            groupe: 'Ventes hors de France',
            urgence: UrgenceAFaire::Orange,
            nombre: $tickets->count(),
            phrase: $seul !== null
                ? "1 ticket détaxé attend la date du visa de son bordereau n° {$seul->bordereauDetaxe?->numero} : {$seul->numero}."
                : $tickets->count().' tickets détaxés attendent la date du visa de leur bordereau.',
            url: $seul === null ? FactureResource::urlDesVisasANoter() : FactureResource::getUrl('view', ['record' => $seul]),
            bouton: $aFaire ? 'Noter le visa' : 'Voir',
            aSurveiller: ! $aFaire,
            date: $this->jourDe($tickets->first()->bordereauDetaxe?->created_at),
            precision: 'La TVA du ticket ne se régularise qu\'après le visa du bordereau à la sortie de l\'Union européenne.',
        );
    }

    /**
     * Les brouillons de facture ou de ticket, dès leur création (règle 5),
     * parmi ceux que le compte voit : à faire pour qui les émet ; à
     * surveiller pour le propriétaire, un administrateur ou un
     * responsable qui les voit sans émettre. Un brouillon seul mène à sa
     * fiche, qui porte « Émettre » et « Modifier » (voir ViewFacture) ; sa
     * page de modification n'émet pas.
     */
    private function brouillonsDeFacture(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_EMETTRE);

        if (! $this->voit(PermissionRegistry::FACTURE_VIEW, $aFaire)) {
            return null;
        }

        $brouillons = Facture::query()->where('statut', StatutFacture::Brouillon)->visiblesPar($this->compte)->orderBy('created_at')->orderBy('id')->get();

        if ($brouillons->isEmpty()) {
            return null;
        }

        $seul = $brouillons->count() === 1 ? $brouillons->first() : null;
        $jour = $this->afficherLeJourDe($brouillons->first()->created_at);

        return new ChoseAFaire(
            cle: 'brouillons_de_facture',
            groupe: 'Ventes',
            urgence: UrgenceAFaire::Orange,
            nombre: $brouillons->count(),
            phrase: $seul !== null ? "1 brouillon de facture pas encore émis, du {$jour}." : $brouillons->count()." brouillons de facture pas encore émis, le plus ancien du {$jour}.",
            url: $seul === null ? FactureResource::urlDesBrouillons() : FactureResource::getUrl('view', ['record' => $seul]),
            bouton: $this->bouton('Émettre', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($brouillons->first()->created_at),
            precision: $this->avecLaLectureSeule(null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les e-mails d'un document qui ne sont pas partis après toutes leurs
     * tentatives, tant qu'aucun envoi plus récent du même document n'a
     * réussi ni n'est en cours : à faire pour qui peut renvoyer ce
     * document, à surveiller pour qui supervise et le voit. Une chose par
     * partie, chaque e-mail sur sa ligne avec le lien de la fiche où le
     * document se renvoie (voir urlDuDocument()). Les documents se lisent
     * avec ce que leurs policies relisent (les factures que règle un reçu
     * de paiement, voir RecuPaiementPolicy::envoyer()), une seule fois pour
     * tous les e-mails.
     *
     * @return list<ChoseAFaire>
     */
    private function emailsEnEchec(): array
    {
        $envois = EnvoiDocument::query()
            ->where('statut', StatutEnvoi::Echec)
            ->whereNotExists(fn (RequeteSql $plusRecents): RequeteSql => $plusRecents
                ->selectRaw('1')
                ->from('envois_documents as suivants')
                ->whereColumn('suivants.magasin_id', 'envois_documents.magasin_id')
                ->whereColumn('suivants.document_type', 'envois_documents.document_type')
                ->whereColumn('suivants.document_id', 'envois_documents.document_id')
                ->whereColumn('suivants.id', '>', 'envois_documents.id')
                ->whereIn('suivants.statut', [StatutEnvoi::Envoye->value, StatutEnvoi::EnAttente->value]))
            ->with(['document' => fn (MorphTo $documents): MorphTo => $documents->morphWith([RecuPaiement::class => ['lignes.facture']])])
            ->orderBy('id')
            ->get();
        $parPartie = ['a_faire' => [], 'a_surveiller' => []];

        foreach ($envois as $envoi) {
            $document = $envoi->document;

            if (! $document instanceof Model || ! $this->compte->can('view', $document)) {
                continue;
            }

            $partie = match (true) {
                $this->compte->can('envoyer', $document) => 'a_faire',
                $this->estSuperviseur() => 'a_surveiller',
                default => null,
            };

            if ($partie !== null) {
                $parPartie[$partie][] = $envoi;
            }
        }

        $choses = [];

        foreach ($parPartie as $partie => $echecs) {
            if ($echecs === []) {
                continue;
            }

            $nombre = count($echecs);
            $choses[] = new ChoseAFaire(
                cle: 'emails_en_echec',
                groupe: 'E-mails',
                urgence: UrgenceAFaire::Orange,
                nombre: $nombre,
                phrase: $nombre === 1 ? '1 e-mail n\'est pas parti : renvoyez-le depuis la fiche de son document.' : "{$nombre} e-mails ne sont pas partis : renvoyez-les depuis la fiche de leur document.",
                url: $nombre === 1 ? $this->urlDuDocument($echecs[0]) : null,
                bouton: $partie === 'a_faire' ? 'Renvoyer' : 'Voir',
                aSurveiller: $partie === 'a_surveiller',
                date: $this->jourDe($echecs[0]->created_at),
                lignes: array_map(fn (EnvoiDocument $envoi): array => [
                    'libelle' => $envoi->document instanceof DocumentEnvoyable ? trim($envoi->document->libelleDocument().' '.ValeursLues::texteOuNul($envoi->document->getAttribute('numero'))) : 'Document',
                    'url' => $this->urlDuDocument($envoi),
                    'description' => $envoi->destinataire,
                    'montant' => null,
                    'date' => $this->afficherLeJourDe($envoi->created_at),
                    'date_en_rouge' => false,
                    'badge' => 'Échec',
                ], array_slice($echecs, 0, self::LIGNES_ENUMEREES)),
            );
        }

        return $choses;
    }

    /**
     * La vente commencée à la caisse par ce compte, pas encore encaissée :
     * en gris, la clôture de la journée l'abandonnera d'office.
     */
    private function venteEnCoursALaCaisse(): ?ChoseAFaire
    {
        if (! $this->compte->tientUneCaisseDans($this->magasin)) {
            return null;
        }

        $panier = PanierCaisse::query()->where('user_id', $this->compte->getKey())->first();
        $articles = $panier === null ? 0 : array_sum(array_map(fn (array $ligne): int => (int) $ligne['quantite'], $panier->lignes));

        if ($articles === 0) {
            return null;
        }

        return new ChoseAFaire(
            cle: 'vente_en_cours',
            groupe: 'Caisse',
            urgence: UrgenceAFaire::Gris,
            nombre: 1,
            phrase: 'Votre vente en cours à la caisse n\'est pas encore encaissée : '.trans_choice('{1} 1 article|[2,*] :count articles', $articles).'.',
            url: Caisse::getUrl(),
            bouton: 'Reprendre la vente',
            aSurveiller: false,
            precision: 'Sans encaissement, la clôture de la journée l\'abandonnera.',
        );
    }

    /**
     * Les devis en brouillon, dès leur création (règle 5), jamais
     * numérotés ou repassés en brouillon : à faire pour qui envoie les
     * devis. Les devis n'ont pas d'auteur : qui les voit les voit tous. Un
     * brouillon seul mène à sa fiche, qui porte « Envoyer par e-mail »,
     * « Numéroter sans e-mail » et « Modifier » (voir ViewDevis) ; sa page
     * de modification n'envoie pas.
     */
    private function brouillonsDeDevis(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::DEVIS_ENVOYER);

        if (! $this->voit(PermissionRegistry::DEVIS_VIEW, $aFaire)) {
            return null;
        }

        $brouillons = Devis::query()->where('statut', StatutDevis::Brouillon)->orderBy('created_at')->orderBy('id')->get();

        if ($brouillons->isEmpty()) {
            return null;
        }

        $seul = $brouillons->count() === 1 ? $brouillons->first() : null;
        $jour = $this->afficherLeJourDe($brouillons->first()->created_at);
        $depasses = $brouillons->filter(fn (Devis $devis): bool => $devis->date_validite !== null && $devis->date_validite < $this->aujourdhui)->count();

        return new ChoseAFaire(
            cle: 'brouillons_de_devis',
            groupe: 'Devis',
            urgence: UrgenceAFaire::Orange,
            nombre: $brouillons->count(),
            phrase: $seul !== null ? "1 devis en brouillon pas encore envoyé, du {$jour}." : $brouillons->count()." devis en brouillon pas encore envoyés, le plus ancien du {$jour}.",
            url: $seul === null ? DevisResource::urlDesBrouillons() : DevisResource::getUrl('view', ['record' => $seul]),
            bouton: $this->bouton('Envoyer', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($brouillons->first()->created_at),
            precision: $this->avecLaLectureSeule($depasses > 0 ? trans_choice('{1} La validité d\'un brouillon est dépassée : changez-la avant de l\'envoyer.|[2,*] La validité de :count brouillons est dépassée : changez-la avant de les envoyer.', $depasses) : null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les devis envoyés sans réponse du client dont la validité finit dans
     * les 7 jours du calendrier du magasin, aujourd'hui compris (règle 5 :
     * un devis valable jusqu'au 31/10 entre le 24/10) ; un devis dont la
     * validité est passée expire tout seul (voir ExpirerLesDevisAction).
     */
    private function devisQuiExpirent(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::DEVIS_ENVOYER);

        if (! $this->voit(PermissionRegistry::DEVIS_VIEW, $aFaire)) {
            return null;
        }

        $devis = Devis::query()->expirentBientot($this->aujourdhui)->orderBy('date_validite')->orderBy('id')->get();

        if ($devis->isEmpty()) {
            return null;
        }

        $seul = $devis->count() === 1 ? $devis->first() : null;

        return new ChoseAFaire(
            cle: 'devis_qui_expirent',
            groupe: 'Devis',
            urgence: UrgenceAFaire::Orange,
            nombre: $devis->count(),
            phrase: $seul !== null
                ? "Le devis {$seul->numero} expire le ".Calendrier::afficherJour((string) $seul->date_validite, 'd/m/Y').' sans réponse du client.'
                : $devis->count().' devis envoyés expirent dans les '.Devis::JOURS_DU_RAPPEL_AVANT_LA_FIN.' jours sans réponse du client.',
            url: $seul === null ? DevisResource::urlDesDevisQuiExpirent() : DevisResource::getUrl('view', ['record' => $seul]),
            bouton: 'Voir',
            aSurveiller: ! $aFaire,
            date: ValeursLues::texteOuNul($devis->first()->date_validite),
            precision: 'Relancez le client, ou notez sa réponse sur le devis.',
        );
    }

    /**
     * Les devis acceptés pas encore transformés en facture, dès leur
     * acceptation (règle 5) : à faire pour qui crée les factures ; à
     * surveiller pour le propriétaire, un administrateur ou un responsable
     * qui voit les devis sans vendre.
     */
    private function devisATransformer(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_CREATE);

        if (! $this->voit(PermissionRegistry::DEVIS_VIEW, $aFaire)) {
            return null;
        }

        $devis = Devis::query()->aTransformer()->orderBy('accepte_le')->orderBy('id')->get();

        if ($devis->isEmpty()) {
            return null;
        }

        $seul = $devis->count() === 1 ? $devis->first() : null;

        return new ChoseAFaire(
            cle: 'devis_a_transformer',
            groupe: 'Devis',
            urgence: UrgenceAFaire::Orange,
            nombre: $devis->count(),
            phrase: $seul !== null ? "Le devis {$seul->numero}, accepté, n'est pas encore transformé en facture." : $devis->count().' devis acceptés ne sont pas encore transformés en facture.',
            url: $seul === null ? DevisResource::urlDesDevisATransformer() : DevisResource::getUrl('view', ['record' => $seul]),
            bouton: $this->bouton('Transformer en facture', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($devis->first()->accepte_le),
            precision: $this->avecLaLectureSeule(null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Sa caisse personnelle ouverte (voir
     * VerificateurCaissesPersonnelles::aSaCaisseOuverte()) : en gris, la
     * clôture de la journée la fermera d'office.
     */
    private function maCaisseOuverte(): ?ChoseAFaire
    {
        if (! $this->compte->tientUneCaisseDans($this->magasin) || ! app(VerificateurCaissesPersonnelles::class)->aSaCaisseOuverte($this->compte)) {
            return null;
        }

        return new ChoseAFaire(
            cle: 'ma_caisse',
            groupe: 'Caisse',
            urgence: UrgenceAFaire::Gris,
            nombre: 1,
            phrase: 'Votre caisse est ouverte : fermez-la à la fin de votre service.',
            url: ClotureCaissePersonnelleResource::getUrl('index'),
            bouton: 'Fermer ma caisse',
            aSurveiller: false,
            precision: 'Sinon, la clôture de la journée la fermera d\'office.',
        );
    }

    /**
     * Les caisses de l'équipe encore ouvertes ce soir, la sienne à part
     * (voir maCaisseOuverte()), pour qui clôture la journée ou voit les
     * relevés de toute l'équipe : en gris, la clôture les fermera d'office.
     */
    private function caissesDeLEquipe(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::CLOTURE_CAISSE_GERER);

        if (! $aFaire && ! ($this->estSuperviseur() && $this->a(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES))) {
            return null;
        }

        $vendeurs = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte($this->aujourdhui)->except([$this->compte->id]);

        if ($vendeurs->isEmpty()) {
            return null;
        }

        return new ChoseAFaire(
            cle: 'caisses_de_l_equipe',
            groupe: 'Caisse',
            urgence: UrgenceAFaire::Gris,
            nombre: $vendeurs->count(),
            phrase: trans_choice('{1} 1 caisse de l\'équipe est ouverte|[2,*] :count caisses de l\'équipe sont ouvertes', $vendeurs->count()).' : '.$vendeurs->pluck('name')->implode(', ').'.',
            url: ClotureCaissePersonnelleResource::getUrl('index'),
            bouton: 'Voir les caisses',
            aSurveiller: ! $aFaire,
            precision: 'La clôture de la journée les fermera d\'office.',
        );
    }

    /**
     * Les journées pas encore clôturées (voir
     * CloturerLesJourneesAction::journeesEnAttente()) : celle d'aujourd'hui
     * en gris, la clôture automatique la fera à minuit ; une journée
     * passée en rouge, qui ne devrait jamais rester (planificateur
     * arrêté).
     *
     * @return list<ChoseAFaire>
     */
    private function journeesACloturer(): array
    {
        $aFaire = $this->a(PermissionRegistry::CLOTURE_CAISSE_GERER);

        if (! $aFaire && ! ($this->estSuperviseur() && $this->a(PermissionRegistry::CLOTURE_CAISSE_VIEW))) {
            return [];
        }

        $journees = app(CloturerLesJourneesAction::class)->journeesEnAttente($this->aujourdhui);
        $passees = $journees->filter(fn (string $jour): bool => $jour < $this->aujourdhui)->values();
        $choses = [];

        if ($passees->isNotEmpty()) {
            $premiere = Calendrier::afficherJour((string) $passees->first(), 'd/m/Y');
            $choses[] = new ChoseAFaire(
                cle: 'journees_passees',
                groupe: 'Caisse',
                urgence: UrgenceAFaire::Rouge,
                nombre: $passees->count(),
                phrase: $passees->count() === 1 ? "La journée du {$premiere} n'est pas clôturée." : $passees->count()." journées ne sont pas clôturées, depuis celle du {$premiere}.",
                url: ClotureCaisseResource::getUrl('index'),
                bouton: $aFaire ? 'Clôturer' : 'Voir',
                aSurveiller: ! $aFaire,
                date: (string) $passees->first(),
            );
        }

        if ($journees->contains($this->aujourdhui)) {
            $choses[] = new ChoseAFaire(
                cle: 'journee_du_jour',
                groupe: 'Caisse',
                urgence: UrgenceAFaire::Gris,
                nombre: 1,
                phrase: 'La journée du '.Calendrier::afficherJour($this->aujourdhui, 'd/m/Y').' sera clôturée à minuit si personne ne le fait avant.',
                url: ClotureCaisseResource::getUrl('index'),
                bouton: $aFaire ? 'Clôturer' : 'Voir',
                aSurveiller: ! $aFaire,
                date: $this->aujourdhui,
            );
        }

        return $choses;
    }

    /**
     * Les factures d'achat en brouillon, dès leur création (règle 5) :
     * leur stock n'est pas encore entré ; à faire pour qui valide les
     * achats. Un brouillon seul mène à sa fiche, qui porte « Valider la
     * réception » (voir ViewFactureAchat).
     */
    private function achatsEnBrouillon(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_ACHAT_VALIDER);

        if (! $this->voit(PermissionRegistry::FACTURE_ACHAT_VIEW, $aFaire)) {
            return null;
        }

        $brouillons = FactureAchat::query()->where('statut', StatutFactureAchat::Brouillon)->orderBy('created_at')->orderBy('id')->get();

        if ($brouillons->isEmpty()) {
            return null;
        }

        $seul = $brouillons->count() === 1 ? $brouillons->first() : null;
        $jour = $this->afficherLeJourDe($brouillons->first()->created_at);

        return new ChoseAFaire(
            cle: 'achats_en_brouillon',
            groupe: 'Achats',
            urgence: UrgenceAFaire::Orange,
            nombre: $brouillons->count(),
            phrase: $seul !== null ? "1 facture d'achat en brouillon, du {$jour} : son stock n'est pas encore entré." : $brouillons->count()." factures d'achat en brouillon, la plus ancienne du {$jour} : leur stock n'est pas encore entré.",
            url: $seul === null ? FactureAchatResource::urlDesBrouillons() : FactureAchatResource::getUrl('view', ['record' => $seul]),
            bouton: $this->bouton('Valider', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($brouillons->first()->created_at),
            precision: $this->avecLaLectureSeule(null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les factures d'achat saisies à la main sans la pièce du fournisseur,
     * en brouillon comme validées (voir PiecesDuFournisseur) : à faire pour
     * qui saisit les achats, qui la joint.
     */
    private function piecesManquantesDesAchats(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_ACHAT_CREER);

        if (! $this->voit(PermissionRegistry::FACTURE_ACHAT_VIEW, $aFaire)) {
            return null;
        }

        $factures = FactureAchat::query()->where('piece_manquante', true)->orderBy('created_at')->orderBy('id')->get();

        return $factures->isEmpty() ? null : new ChoseAFaire(
            cle: 'pieces_des_achats',
            groupe: 'Achats',
            urgence: UrgenceAFaire::Orange,
            nombre: $factures->count(),
            phrase: $factures->count() === 1 ? '1 facture d\'achat attend la pièce du fournisseur.' : $factures->count().' factures d\'achat attendent la pièce du fournisseur.',
            url: $factures->count() === 1 ? FactureAchatResource::getUrl('view', ['record' => $factures->first()]) : FactureAchatResource::urlDesPiecesManquantes(),
            bouton: $this->bouton('Joindre la pièce', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($factures->first()->created_at),
            precision: $this->avecLaLectureSeule('Le PDF ou la photo de la facture du fournisseur.', $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les avoirs fournisseur émis sans la note d'avoir du fournisseur : à
     * faire pour qui crée les avoirs fournisseur, qui la joint.
     */
    private function piecesManquantesDesAvoirsFournisseur(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::AVOIR_FOURNISSEUR_CREER);

        if (! $this->voit(PermissionRegistry::AVOIR_FOURNISSEUR_VIEW, $aFaire)) {
            return null;
        }

        $avoirs = AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->where('piece_manquante', true)->orderBy('created_at')->orderBy('id')->get();

        return $avoirs->isEmpty() ? null : new ChoseAFaire(
            cle: 'pieces_des_avoirs_fournisseur',
            groupe: 'Achats',
            urgence: UrgenceAFaire::Orange,
            nombre: $avoirs->count(),
            phrase: $avoirs->count() === 1 ? '1 avoir fournisseur attend la note d\'avoir du fournisseur.' : $avoirs->count().' avoirs fournisseur attendent la note d\'avoir du fournisseur.',
            url: $avoirs->count() === 1 ? AvoirFournisseurResource::getUrl('view', ['record' => $avoirs->first()]) : AvoirFournisseurResource::urlDesPiecesManquantes(),
            bouton: $this->bouton('Joindre la pièce', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($avoirs->first()->created_at),
            precision: $this->avecLaLectureSeule(null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les inventaires d'ouverture en brouillon : à valider avant la
     * première vente du magasin, par qui valide les achats et crée les
     * produits ; après elle, ils ne se valident plus et restent à
     * supprimer (voir InventaireOuverturePolicy). Un brouillon seul mène à
     * sa fiche, qui porte « Valider l'inventaire » et « Supprimer » (voir
     * ViewInventaireOuverture).
     */
    private function inventairesEnBrouillon(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_ACHAT_VALIDER) && $this->a(PermissionRegistry::PRODUIT_CREATE);

        if (! $this->voit(PermissionRegistry::FACTURE_ACHAT_VIEW, $aFaire)) {
            return null;
        }

        $brouillons = InventaireOuverture::query()->where('statut', StatutInventaireOuverture::Brouillon)->orderBy('created_at')->orderBy('id')->get();

        if ($brouillons->isEmpty()) {
            return null;
        }

        $aVendu = $this->magasin->aVendu();
        $nombre = $brouillons->count();

        return new ChoseAFaire(
            cle: 'inventaires_en_brouillon',
            groupe: 'Achats',
            urgence: UrgenceAFaire::Orange,
            nombre: $nombre,
            phrase: match (true) {
                $aVendu => trans_choice('{1} 1 inventaire d\'ouverture en brouillon ne se valide plus : le magasin a déjà vendu. Supprimez-le.|[2,*] :count inventaires d\'ouverture en brouillon ne se valident plus : le magasin a déjà vendu. Supprimez-les.', $nombre),
                default => trans_choice('{1} 1 inventaire d\'ouverture en brouillon : son stock n\'est pas encore entré. Validez-le avant la première vente.|[2,*] :count inventaires d\'ouverture en brouillon : leur stock n\'est pas encore entré. Validez-les avant la première vente.', $nombre),
            },
            url: $nombre === 1 ? InventaireOuvertureResource::getUrl('view', ['record' => $brouillons->first()]) : InventaireOuvertureResource::urlDesBrouillons(),
            bouton: $this->bouton($aVendu ? 'Supprimer' : 'Valider', $aFaire, permisEnLectureSeule: false),
            aSurveiller: ! $aFaire,
            date: $this->jourDe($brouillons->first()->created_at),
            precision: $this->avecLaLectureSeule(null, $aFaire, permisEnLectureSeule: false),
        );
    }

    /**
     * Les produits en stock négatif (voir Produit::scopeEnStockNegatif()),
     * à régulariser par un achat ou un inventaire : à faire pour qui ajuste
     * le stock ou valide les achats. Un produit seul mène à sa fiche (voir
     * urlDuProduit()), plusieurs à la liste filtrée.
     */
    private function stockNegatif(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::PRODUIT_AJUSTER_STOCK) || $this->a(PermissionRegistry::FACTURE_ACHAT_VALIDER);

        if (! $this->voit(PermissionRegistry::PRODUIT_VIEW, $aFaire)) {
            return null;
        }

        $produits = Produit::query()->enStockNegatif()->orderBy('stock_quantite')->orderBy('id')->get();

        if ($produits->isEmpty()) {
            return null;
        }

        $seul = $produits->count() === 1 ? $produits->first() : null;

        return new ChoseAFaire(
            cle: 'stock_negatif',
            groupe: 'Stock',
            urgence: UrgenceAFaire::Rouge,
            nombre: $produits->count(),
            phrase: $seul !== null
                ? "« {$seul->nom} » est en stock négatif ({$seul->stock_quantite}) : régularisez-le par un achat ou un inventaire."
                : $produits->count().' produits sont en stock négatif, à régulariser par un achat ou un inventaire.',
            url: $this->urlDuProduit($seul) ?? ProduitResource::urlDuStock(ProduitResource::STOCK_NEGATIF),
            bouton: $aFaire ? 'Régulariser' : 'Voir',
            aSurveiller: ! $aFaire,
        );
    }

    /**
     * Les produits suivis au seuil d'alerte ou dessous, sans être en stock
     * négatif (voir Produit::scopeEnStockBas()) : à faire pour qui
     * réapprovisionne (saisit ou valide les achats) ou ajuste le stock. Un
     * produit seul mène à sa fiche (voir urlDuProduit()), plusieurs à la
     * liste filtrée.
     */
    private function stockBas(): ?ChoseAFaire
    {
        $aFaire = $this->a(PermissionRegistry::FACTURE_ACHAT_CREER) || $this->a(PermissionRegistry::FACTURE_ACHAT_VALIDER) || $this->a(PermissionRegistry::PRODUIT_AJUSTER_STOCK);

        if (! $this->voit(PermissionRegistry::PRODUIT_VIEW, $aFaire)) {
            return null;
        }

        $produits = Produit::query()->enStockBas()->orderBy('stock_quantite')->orderBy('id')->get();

        if ($produits->isEmpty()) {
            return null;
        }

        $seul = $produits->count() === 1 ? $produits->first() : null;

        return new ChoseAFaire(
            cle: 'stock_bas',
            groupe: 'Stock',
            urgence: UrgenceAFaire::Orange,
            nombre: $produits->count(),
            phrase: $seul !== null
                ? "« {$seul->nom} » est au seuil d'alerte ou dessous ({$seul->stock_quantite} pour un seuil de {$seul->seuil_alerte}) : réapprovisionnez-le."
                : $produits->count().' produits sont au seuil d\'alerte ou dessous : réapprovisionnez-les.',
            url: $this->urlDuProduit($seul) ?? ProduitResource::urlDuStock(ProduitResource::STOCK_BAS),
            bouton: $aFaire ? 'Réapprovisionner' : 'Voir',
            aSurveiller: ! $aFaire,
        );
    }

    /**
     * Un magasin réel sans aucun produit ni service au catalogue (sans
     * service, dans une société de services) ne peut rien vendre : à faire
     * pour qui peut créer un service, ou saisir un inventaire d'ouverture
     * avant la première vente (produits).
     */
    private function catalogueVide(): ?ChoseAFaire
    {
        if ($this->magasin->est_demonstration) {
            return null;
        }

        $articles = $this->magasin->societe->aDesArticles();
        $inventaire = $articles && ! $this->magasin->aVendu() && $this->a(PermissionRegistry::FACTURE_ACHAT_VALIDER) && $this->a(PermissionRegistry::PRODUIT_CREATE);
        $aFaire = $inventaire || $this->a(PermissionRegistry::SERVICE_CREATE);

        if (! $aFaire && ! $this->estSuperviseur()) {
            return null;
        }

        if (Service::query()->exists() || ($articles && Produit::query()->exists())) {
            return null;
        }

        return new ChoseAFaire(
            cle: 'catalogue_vide',
            groupe: 'Catalogue',
            urgence: UrgenceAFaire::Rouge,
            nombre: 1,
            phrase: $articles ? 'Aucun produit ni service n\'est au catalogue : rien ne peut se vendre.' : 'Aucun service n\'est au catalogue : rien ne peut se vendre.',
            url: $inventaire ? InventaireOuvertureResource::getUrl('create') : ServiceResource::getUrl('index'),
            bouton: match (true) {
                $inventaire => 'Saisir l\'inventaire d\'ouverture',
                $aFaire => 'Ajouter un service',
                default => 'Voir',
            },
            aSurveiller: ! $aFaire,
            precision: $articles ? 'Un produit naît d\'une facture d\'achat ou de l\'inventaire d\'ouverture ; un service s\'ajoute au catalogue.' : null,
        );
    }

    /**
     * La société attend le code de son courrier recommandé : aucune vente
     * réelle avant sa validation. À faire pour le propriétaire, seul à
     * saisir le code ; à surveiller pour un administrateur ou un
     * responsable.
     */
    private function societeAVerifier(): ?ChoseAFaire
    {
        $societe = $this->magasin->societe;

        if ($this->magasin->est_demonstration || ! $societe->attendSonCode() || (! $this->compte->estProprietaire() && ! $this->estSuperviseur())) {
            return null;
        }

        return new ChoseAFaire(
            cle: 'societe_a_verifier',
            groupe: 'Magasin',
            urgence: UrgenceAFaire::Rouge,
            nombre: 1,
            phrase: 'La société attend le code de son courrier recommandé : aucune vente avant sa validation.',
            url: $this->compte->estProprietaire() ? VerificationSociete::getUrl(panel: 'societe', tenant: $societe) : null,
            bouton: $this->compte->estProprietaire() ? 'Saisir le code' : 'Voir',
            aSurveiller: ! $this->compte->estProprietaire(),
        );
    }

    /**
     * L'établissement de ce magasin réel n'est pas encore confirmé par le
     * répertoire Sirene, la société étant validée : aucune vente dans ce
     * magasin avant sa confirmation. À faire pour qui gère les magasins de
     * la société (corriger son NIC), à surveiller pour un autre
     * superviseur ; la reprise automatique le revérifie chaque heure.
     */
    private function etablissementAConfirmer(): ?ChoseAFaire
    {
        if ($this->magasin->est_demonstration || ! $this->magasin->societe->estValidee() || $this->magasin->etablissement_verifie_le !== null || ! $this->estSuperviseur()) {
            return null;
        }

        $aFaire = $this->compte->aLAutorisationDeSociete(AutorisationsSociete::MAGASINS_GERER);

        return new ChoseAFaire(
            cle: 'etablissement_a_confirmer',
            groupe: 'Magasin',
            urgence: UrgenceAFaire::Rouge,
            nombre: 1,
            phrase: "L'établissement de ce magasin (SIRET {$this->magasin->siret}) n'est pas encore confirmé par le répertoire Sirene : aucune vente avant sa confirmation.",
            url: $aFaire ? MagasinResource::getUrl('edit', ['record' => $this->magasin], panel: 'societe', tenant: $this->magasin->societe) : null,
            bouton: $aFaire ? 'Vérifier le SIRET' : 'Voir',
            aSurveiller: ! $aFaire,
            precision: 'Le répertoire est consulté de nouveau chaque heure.',
        );
    }

    /**
     * Les mentions de l'identité légale qui manquent pour facturer un
     * professionnel depuis ce magasin (voir
     * Magasin::mentionsLegalesManquantes()) : les tickets restent
     * possibles. À faire pour qui règle l'identité de la société, à
     * surveiller pour un autre superviseur.
     */
    private function identiteIncomplete(): ?ChoseAFaire
    {
        if (! $this->estSuperviseur() || ($manquantes = $this->magasin->mentionsLegalesManquantes()) === []) {
            return null;
        }

        $aFaire = $this->compte->aLAutorisationDeSociete(AutorisationsSociete::PARAMETRES_GERER);

        return new ChoseAFaire(
            cle: 'identite_incomplete',
            groupe: 'Magasin',
            urgence: UrgenceAFaire::Orange,
            nombre: count($manquantes),
            phrase: 'Aucune facture à un professionnel tant que ces mentions manquent : '.implode(', ', $manquantes).'.',
            url: $aFaire ? IdentiteSociete::getUrl(panel: 'societe', tenant: $this->magasin->societe) : null,
            bouton: $aFaire ? 'Compléter l\'identité' : 'Voir',
            aSurveiller: ! $aFaire,
            precision: 'Les tickets de caisse restent possibles.',
        );
    }

    /**
     * Son mot de passe est encore son identifiant (un vendeur ou un
     * observateur, les autres rôles le choisissent à la connexion) : il le
     * choisit dans son profil.
     */
    private function monMotDePasse(): ?ChoseAFaire
    {
        return ! $this->compte->mot_de_passe_est_l_identifiant ? null : new ChoseAFaire(
            cle: 'mon_mot_de_passe',
            groupe: 'Mon compte',
            urgence: UrgenceAFaire::Orange,
            nombre: 1,
            phrase: 'Votre mot de passe est encore votre identifiant : choisissez le vôtre dans votre profil.',
            url: Filament::getProfileUrl(),
            bouton: 'Choisir mon mot de passe',
            aSurveiller: false,
        );
    }

    /**
     * Les comptes de l'équipe de ce magasin dont le mot de passe est
     * encore l'identifiant (voir EquipeDuMagasinResource::auMotDePasseIdentifiant()),
     * qui n'ont donc jamais choisi le leur : pour le propriétaire, tous ;
     * pour un responsable du magasin, ses vendeurs et ses observateurs. À
     * surveiller : seule la personne choisit son mot de passe.
     */
    private function motsDePasseDeLEquipe(): ?ChoseAFaire
    {
        if (! $this->compte->estProprietaire() && ! $this->compte->estResponsableDe($this->magasin)) {
            return null;
        }

        $nombre = EquipeDuMagasinResource::auMotDePasseIdentifiant(EquipeDuMagasinResource::getEloquentQuery(), $this->compte->estProprietaire())->count();

        return $nombre === 0 ? null : new ChoseAFaire(
            cle: 'mots_de_passe_de_l_equipe',
            groupe: 'Équipe',
            urgence: UrgenceAFaire::Orange,
            nombre: $nombre,
            phrase: trans_choice('{1} 1 compte de l\'équipe a encore son identifiant pour mot de passe|[2,*] :count comptes de l\'équipe ont encore leur identifiant pour mot de passe', $nombre).' : la personne choisit le sien dans son profil.',
            url: EquipeDuMagasinResource::urlDesMotsDePasseIdentifiants(),
            bouton: 'Voir',
            aSurveiller: true,
        );
    }

    /**
     * Aucun poste du magasin n'est reconnu alors qu'un vendeur ou un
     * observateur actif a encore son identifiant pour mot de passe : ce
     * compte ne s'ouvre que depuis un poste reconnu (voir PostesReconnus).
     * À faire pour le propriétaire et les responsables du magasin, qui
     * reconnaissent les postes.
     */
    private function aucunPosteReconnu(): ?ChoseAFaire
    {
        if ((! $this->compte->estProprietaire() && ! $this->compte->estResponsableDe($this->magasin)) || PosteReconnu::query()->exists()) {
            return null;
        }

        // Une affectation n'a pas de MagasinScope : elle se filtre ici par
        // ce magasin, explicitement.
        $bloques = Affectation::query()
            ->where('affectations.magasin_id', $this->magasin->getKey())
            ->whereIn('role', [RoleMagasin::Vendeur->value, RoleMagasin::Observateur->value])
            ->whereHas('utilisateur', fn (Builder $comptes): Builder => $comptes->where('mot_de_passe_est_l_identifiant', true)->where('statut', StatutCompte::Actif->value))
            ->count();

        return $bloques === 0 ? null : new ChoseAFaire(
            cle: 'aucun_poste_reconnu',
            groupe: 'Équipe',
            urgence: UrgenceAFaire::Rouge,
            nombre: $bloques,
            phrase: 'Aucun poste n\'est reconnu : '.trans_choice('{1} 1 compte qui a encore son identifiant pour mot de passe ne peut pas se connecter|[2,*] :count comptes qui ont encore leur identifiant pour mot de passe ne peuvent pas se connecter', $bloques).'.',
            url: PosteReconnuResource::getUrl('index'),
            bouton: 'Reconnaître ce poste',
            aSurveiller: false,
        );
    }

    /**
     * Les avoirs non utilisés, à surveiller par qui voit les avoirs de ce
     * magasin (décision de l'éditeur du 01/11/2026) : les bons d'avoir du
     * client comptoir dont il reste un crédit, chacun avec son numéro, sa
     * date et ce qui en reste, sans nom de client ; puis les crédits des
     * autres clients, chacun avec ce qui en reste et les avoirs d'où il
     * vient ; le crédit d'un avoir de régularisation y dit à quelle
     * facture il est réservé. L'avoir d'annulation d'un ticket transformé
     * en facture, toujours consommé en entier, n'y figure donc jamais.
     * Chaque crédit se calcule en centimes, depuis le total de l'avoir et
     * ses imputations enregistrées (voir Avoir::creditApres()).
     *
     * @return list<ChoseAFaire>
     */
    private function avoirsNonUtilises(): array
    {
        if (! $this->a(PermissionRegistry::AVOIR_VIEW)) {
            return [];
        }

        $avoirs = Avoir::query()
            ->visiblesPar($this->compte)
            ->where('statut', StatutAvoir::Emise)
            ->with(['allocations:id,avoir_id,montant_alloue', 'client:id,magasin_id,nom,est_comptoir'])
            ->orderBy('date_emission')
            ->orderBy('id')
            ->get()
            ->map(fn (Avoir $avoir): array => ['avoir' => $avoir, 'credit' => $avoir->creditApres($avoir->allocations->map(fn (AvoirAllocation $allocation): string => (string) $allocation->montant_alloue))])
            ->filter(fn (array $restant): bool => $restant['credit'] > 0)
            ->values();

        if ($avoirs->isEmpty()) {
            return [];
        }

        $bons = $avoirs->filter(fn (array $restant): bool => $this->estUnBonDAvoir($restant['avoir']))->values();
        $credits = $avoirs->reject(fn (array $restant): bool => $this->estUnBonDAvoir($restant['avoir']))->values();
        $choses = [];

        if ($bons->isNotEmpty()) {
            $choses[] = new ChoseAFaire(
                cle: 'bons_d_avoir',
                groupe: 'Avoirs non utilisés',
                urgence: UrgenceAFaire::Gris,
                nombre: $bons->count(),
                phrase: trans_choice('{1} 1 bon d\'avoir du comptoir n\'est pas encore utilisé|[2,*] :count bons d\'avoir du comptoir ne sont pas encore utilisés', $bons->count()).' : '.$this->euros($bons->sum(fn (array $restant): int => $restant['credit'])).' restant.',
                url: null,
                bouton: 'Voir',
                aSurveiller: true,
                date: $this->jourDe($bons->first()['avoir']->date_emission),
                lignes: array_values($bons->take(self::LIGNES_ENUMEREES)->map(fn (array $restant): array => [
                    'libelle' => (string) $restant['avoir']->numero,
                    'url' => AvoirResource::getUrl('view', ['record' => $restant['avoir']]),
                    'description' => null,
                    'montant' => $this->euros($restant['credit']),
                    'date' => $this->afficherLeJourDe($restant['avoir']->date_emission),
                    'date_en_rouge' => false,
                    'badge' => null,
                ])->all()),
            );
        }

        if ($credits->isNotEmpty()) {
            $parClient = $credits->groupBy(fn (array $restant): int => (int) $restant['avoir']->client_id);
            $choses[] = new ChoseAFaire(
                cle: 'credits_des_clients',
                groupe: 'Avoirs non utilisés',
                urgence: UrgenceAFaire::Gris,
                nombre: $parClient->count(),
                phrase: trans_choice('{1} 1 client a un crédit d\'avoir non utilisé|[2,*] :count clients ont un crédit d\'avoir non utilisé', $parClient->count()).' : '.$this->euros($credits->sum(fn (array $restant): int => $restant['credit'])).' restant.',
                url: null,
                bouton: 'Voir',
                aSurveiller: true,
                date: $this->jourDe($credits->first()['avoir']->date_emission),
                lignes: array_values($parClient->take(self::LIGNES_ENUMEREES)->map(function (Collection $siens): array {
                    $premier = $siens->firstOrFail()['avoir'];
                    $regularisations = $siens->filter(fn (array $restant): bool => $restant['avoir']->estUneRegularisation());

                    return [
                        'libelle' => $premier->nomDuClient() ?? 'Client',
                        'url' => $premier->client !== null && $this->compte->can('view', $premier->client) ? ClientResource::getUrl('view', ['record' => $premier->client]) : AvoirResource::getUrl('view', ['record' => $premier]),
                        'description' => 'Avoirs '.$siens->map(fn (array $restant): string => (string) $restant['avoir']->numero)->implode(', '),
                        'montant' => $this->euros($siens->sum(fn (array $restant): int => $restant['credit'])),
                        'date' => $this->afficherLeJourDe($premier->date_emission),
                        'date_en_rouge' => false,
                        'badge' => $regularisations->isEmpty() ? null : 'Réservé à la facture rectificative de la facture n° '.$regularisations->map(fn (array $restant): string => (string) $restant['avoir']->factureRegularisee()->numero)->implode(', '),
                    ];
                })->all()),
            );
        }

        return $choses;
    }

    /**
     * Un avoir au client comptoir sert de bon d'avoir, sauf un avoir de
     * régularisation, dont le crédit est réservé à la facture rectificative.
     */
    private function estUnBonDAvoir(Avoir $avoir): bool
    {
        return $avoir->client?->estComptoir() === true && ! $avoir->estUneRegularisation();
    }

    /**
     * A-t-il cette autorisation cochée dans ce magasin (voir
     * User::autorisationsDans()) ?
     */
    private function a(string $autorisation): bool
    {
        return in_array($autorisation, $this->cases, true);
    }

    /**
     * Voit-il la chose : il voit ces documents, et il la fait, ou il
     * supervise ce magasin (« À surveiller », règle 2) ?
     */
    private function voit(string $consulter, bool $aFaire): bool
    {
        return $this->a($consulter) && ($aFaire || $this->estSuperviseur());
    }

    /**
     * Supervise-t-il ce magasin : son propriétaire, un administrateur qui y
     * a accès, ou un responsable de ce magasin (règle 2) ?
     */
    private function estSuperviseur(): bool
    {
        return $this->compte->estProprietaire() || $this->compte->estAdministrateur() || $this->compte->estResponsableDe($this->magasin);
    }

    /**
     * Le bouton d'une chose : le verbe de son geste pour qui la fait, tant
     * que le magasin le permet ; « Voir » sinon.
     */
    private function bouton(string $geste, bool $aFaire, bool $permisEnLectureSeule): string
    {
        return $aFaire && ($permisEnLectureSeule || ! $this->magasin->estEnLectureSeule()) ? $geste : 'Voir';
    }

    /**
     * La précision d'une chose qui ne se fait plus dans un magasin en
     * lecture seule : son motif, à la suite.
     */
    private function avecLaLectureSeule(?string $precision, bool $aFaire, bool $permisEnLectureSeule): ?string
    {
        if (! $aFaire || $permisEnLectureSeule || ($motif = $this->magasin->motifDeLectureSeule()) === null) {
            return $precision;
        }

        return trim(($precision ?? '').' '.$motif);
    }

    private function euros(int $centimes): string
    {
        return $this->calculateur->enEuros($centimes);
    }

    /**
     * Le jour de cet instant dans le calendrier du magasin, « AAAA-MM-JJ ».
     */
    private function jourDe(mixed $instant): ?string
    {
        return $instant instanceof DateTimeInterface ? $this->calendrier->jourDe($instant) : null;
    }

    private function afficherLeJourDe(mixed $instant): ?string
    {
        $jour = $this->jourDe($instant);

        return $jour === null ? null : Calendrier::afficherJour($jour, 'd/m/Y');
    }

    /**
     * La fiche de ce produit seul, quand le compte peut l'ouvrir : un
     * produit n'a d'autre fiche que sa page de modification (voir
     * ProduitResource::getPages()), que ProduitPolicy::update() ouvre ;
     * sinon null, et la chose mène à la liste filtrée, qui le montre seul.
     */
    private function urlDuProduit(?Produit $seul): ?string
    {
        return $seul !== null && $this->compte->can('update', $seul) ? ProduitResource::getUrl('edit', ['record' => $seul]) : null;
    }

    /**
     * La fiche où se renvoie le document d'un e-mail, dans ce magasin : sa
     * propre fiche, ou, pour un reçu de paiement, qui n'en a pas, celle de
     * la première facture qu'il règle que ce compte voit, dont la section
     * « Paiements reçus » le renvoie (voir FactureResource::envoyerLeRecu()).
     */
    private function urlDuDocument(EnvoiDocument $envoi): ?string
    {
        $document = $envoi->document;
        $factureDuRecu = $document instanceof RecuPaiement
            ? $document->lignes->map(fn (LigneRecuPaiement $ligne): ?Facture => $ligne->facture)->first(fn (?Facture $facture): bool => $facture !== null && $this->compte->can('view', $facture))
            : null;

        return match (true) {
            $document instanceof Facture => FactureResource::getUrl('view', ['record' => $document]),
            $document instanceof Devis => DevisResource::getUrl('view', ['record' => $document]),
            $document instanceof Avoir => AvoirResource::getUrl('view', ['record' => $document]),
            $factureDuRecu instanceof Facture => FactureResource::getUrl('view', ['record' => $factureDuRecu]),
            default => null,
        };
    }
}
