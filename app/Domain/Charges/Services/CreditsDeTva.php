<?php

namespace App\Domain\Charges\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDuCreditDeTva;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Le crédit de TVA des déclarations d'une société faites hors de PILIQI
 * (lot TVA27 ; choix K2 de l'éditeur du 15/11/2026 ; règle de l'éditeur
 * du 18/11/2026, maquette « Message TVA avant PILIQI » approuvée) :
 *
 * - PILIQI calcule la TVA dès l'arrivée de la société, le jour de sa
 *   création dans PILIQI ; il ne lui manque que le crédit d'une
 *   déclaration réellement déposée, que la société indique quand elle le
 *   peut, même des mois après son arrivée, pour la période de son choix :
 *   la période de son arrivée ou une suivante (voir premierePeriode()),
 *   une fois terminée, puisque sa déclaration ne se dépose qu'ensuite ; la
 *   plus récente de la cadence de son régime sert d'ancre au report (voir
 *   ancre() et ReportDuCreditDeTva), les autres restent en historique ;
 * - l'indiquent le propriétaire de la société et son comptable : le
 *   cabinet lié (son propriétaire et le collaborateur à qui le client est
 *   confié) ou l'expert-comptable déclaré, quand la société lui accorde
 *   « Charges : voir, ajouter, saisir et valider » (voir
 *   DroitsSurLesCharges) ; jamais un administrateur, un responsable, un
 *   vendeur ni un observateur (voir peutDeclarer()) ; le comptable seul le
 *   vérifie (voir peutVerifier()) ;
 * - son état de vérification se lit à chaque affichage (voir etat()) :
 *   vérifié dès qu'un comptable l'a confirmé, corrigé ou saisi ; sinon « à
 *   vérifier par votre comptable » quand la société en a un, « non vérifié
 *   par un comptable » quand elle n'en a pas, qui passe « à vérifier » dès
 *   qu'un comptable arrive ;
 * - rien de tout cela en franchise en base, ni dans un cabinet.
 *
 * Une lecture hors de la société courante, déclarée dans CLAUDE.md : les
 * déclarations de cette société, ou de ces clients pour un compte de
 * cabinet, filtrées par leur société, jamais celles d'une autre.
 */
final class CreditsDeTva
{
    /**
     * Le conseil que montre toujours le bloc de la saisie (décision de
     * l'éditeur du 15/11/2026).
     */
    public const CONSEIL = 'Faites vérifier ce montant par votre expert-comptable : il figure à la ligne « Crédit de TVA à reporter » de votre déclaration. Une erreur fausse toute la TVA à payer qui suit.';

    /**
     * Les colonnes qu'une déclaration montre et que ses gestes demandent
     * (voir declarations() et ancre()), lues même hors de la société
     * courante : jamais l'identifiant des comptes qui l'ont saisie ou
     * vérifiée, dont seul le nom figé se montre, ni ses horodatages
     * techniques.
     */
    private const COLONNES = ['id', 'uuid', 'societe_id', 'cadence', 'periode', 'solde', 'saisi_par_nom', 'saisi_comme', 'saisi_le', 'verifie_par_nom', 'verifie_comme', 'verifie_le', 'motif'];

    public function __construct(
        private readonly DroitsSurLesCharges $droits,
        private readonly AccesDesCabinets $cabinets,
    ) {}

    /**
     * Le jour où la société est arrivée dans PILIQI, « AAAA-MM-JJ » au
     * calendrier de Paris : celui de sa création, que toute lecture de la
     * société qui sert au crédit de TVA doit charger.
     *
     * @throws LogicException une société lue sans sa date de création
     */
    public static function jourDArrivee(Societe $societe): string
    {
        return Calendrier::deParis()->jourDe($societe->created_at ?? throw new LogicException('La date d\'arrivée de la société dans PILIQI manque : lisez sa date de création.'));
    }

    /**
     * La première période que PILIQI compte, et la première qu'une
     * déclaration peut porter : celle qui contient le jour d'arrivée de la
     * société (maquette approuvée du 18/11/2026 : « du mois d'arrivée ou
     * d'un mois suivant »). Seul endroit où se décide le sort d'une
     * période commencée avant PILIQI, que l'éditeur n'a pas encore tranché
     * (question du compte rendu du lot TVA27) : ses jours d'avant l'arrivée
     * ne sont pas dans PILIQI, et la TVA de PILIQI pour elle reste une
     * estimation tant qu'une déclaration de cette période ou d'une suivante
     * ne la remplace pas.
     */
    public static function premierePeriode(Societe $societe, CadenceDeLaTva $cadence): string
    {
        return $cadence->periodeDu(Mois::duJour(self::jourDArrivee($societe)));
    }

    /**
     * Les périodes qu'une déclaration peut porter ce jour « AAAA-MM-JJ » :
     * de la première (voir premierePeriode()) à la dernière terminée, la
     * plus récente d'abord ; aucune tant que la première n'est pas finie.
     *
     * @return list<string>
     */
    public static function periodesDeclarables(Societe $societe, CadenceDeLaTva $cadence, string $aujourdhui): array
    {
        $periodes = [];

        for ($periode = self::premierePeriode($societe, $cadence); $cadence->lendemainDe($periode) <= $aujourdhui; $periode = $cadence->decaler($periode, 1)) {
            array_unshift($periodes, $periode);
        }

        return $periodes;
    }

    /**
     * Le jour, « AAAA-MM-JJ », à partir duquel la première période se
     * déclare : le lendemain de sa fin.
     */
    public static function premiereDeclarationLe(Societe $societe, CadenceDeLaTva $cadence): string
    {
        return $cadence->lendemainDe(self::premierePeriode($societe, $cadence));
    }

    /**
     * Les déclarations de cette société, la plus récente période d'abord,
     * toutes cadences confondues, limitées aux colonnes nommées (voir
     * COLONNES).
     *
     * @return Collection<int, CreditDeTva>
     */
    public function declarations(Societe $societe): Collection
    {
        return CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->orderByDesc('periode')
            ->orderByDesc('id')
            ->get(self::COLONNES);
    }

    /**
     * La déclaration qui sert d'ancre au report du crédit : celle de la
     * cadence du régime actuel de la société dont la période est la plus
     * récente, parmi celles de sa première période ou d'une suivante,
     * limitée aux colonnes nommées (voir COLONNES) ; null sans
     * déclaration, ou hors du réel normal.
     */
    public function ancre(Societe $societe): ?CreditDeTva
    {
        $cadence = $societe->regime_tva?->cadence();

        if ($cadence === null) {
            return null;
        }

        return CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->where('cadence', $cadence->value)
            ->where('periode', '>=', self::premierePeriode($societe, $cadence))
            ->orderByDesc('periode')
            ->first(self::COLONNES);
    }

    /**
     * L'état de la vérification de cette déclaration, lu à cet instant :
     * vérifiée par le comptable, à vérifier quand la société en a un, non
     * vérifiée quand elle n'en a pas (voir DroitsSurLesCharges::aUnComptable()).
     */
    public function etat(Societe $societe, CreditDeTva $credit): EtatDuCreditDeTva
    {
        return match (true) {
            $credit->estVerifie() => EtatDuCreditDeTva::Verifie,
            $this->droits->aUnComptable($societe) => EtatDuCreditDeTva::AVerifier,
            default => EtatDuCreditDeTva::NonVerifie,
        };
    }

    /**
     * Le crédit de TVA a-t-il lieu d'être dans cette société : une société
     * qui a des magasins (jamais un cabinet), au réel normal, mensuel ou
     * trimestriel, qui dépose elle-même ses déclarations de TVA ? Une
     * société membre d'un assujetti unique (art. 256 C du CGI) n'en dépose
     * pas : le représentant de l'assujetti unique déclare pour tout le
     * groupe, et le crédit de TVA est celui du groupe, jamais d'un membre ;
     * PILIQI ne lui demande donc aucun crédit de départ (décision de
     * Claude, D76, écrite dans l'onglet « À faire vérifier par un
     * professionnel »), comme il lui refuse déjà toute opération réelle.
     */
    public static function aLieuDansLa(Societe $societe): bool
    {
        return ! $societe->estUnCabinet() && $societe->type_activite->aDesMagasins() && ! $societe->membre_assujetti_unique && $societe->regime_tva?->cadence() !== null;
    }

    /**
     * Ce compte peut-il indiquer ou changer le crédit de cette société : son
     * propriétaire, ou son comptable qui a « Charges : voir, ajouter,
     * saisir et valider » ?
     */
    public function peutDeclarer(User $compte, Societe $societe): bool
    {
        return $this->estLeProprietaire($compte, $societe) || $this->peutVerifier($compte, $societe);
    }

    /**
     * Ce compte vérifie-t-il le crédit de cette société : son comptable, le
     * cabinet lié ou l'expert-comptable déclaré, qui a « Charges : voir,
     * ajouter, saisir et valider » (voir DroitsSurLesCharges) ?
     */
    public function peutVerifier(User $compte, Societe $societe): bool
    {
        // Un compte de la société n'en est jamais le comptable, hors
        // l'expert-comptable qu'elle a déclaré : ni ses droits sur les
        // charges ni la base n'ont à être lus pour le dire.
        if ($compte->societe_id === CleDuModele::de($societe) && ! $compte->estExpertComptable()) {
            return false;
        }

        return $this->droits->pour($compte, $societe)?->estComptable() ?? false;
    }

    public function estLeProprietaire(User $compte, Societe $societe): bool
    {
        return $compte->peutAgir() && $compte->estProprietaire() && $compte->societe_id === CleDuModele::de($societe);
    }

    /**
     * Les clients de ce compte d'un cabinet dont l'ancre attend sa
     * vérification, qu'il peut vérifier : ceux qui lui accordent « Charges
     * : voir, ajouter, saisir et valider », au réel normal, qui touchent
     * encore à leurs charges (ni révoqués ni clos), dont la déclaration la
     * plus récente n'est pas vérifiée. Une lecture hors de la société
     * courante, filtrée par ces clients, limitée aux colonnes nommées.
     *
     * @param  Collection<int, Societe>  $clients  les clients que le cabinet ouvre au compte
     * @return list<int> leurs identifiants
     */
    public function aVerifierParClient(User $compte, Collection $clients): array
    {
        $parClient = $this->cabinets->autorisationsParClient($compte);
        $accordes = $clients->filter(fn (Societe $client): bool => self::aLieuDansLa($client) && ! GestesSurLesCharges::touchePlusASesCharges($client) && in_array(PermissionRegistry::CHARGES_VALIDER, $parClient[CleDuModele::de($client)] ?? [], true));

        if ($accordes->isEmpty()) {
            return [];
        }

        $declarations = CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)
            ->whereIn('societe_id', $accordes->modelKeys())
            ->orderByDesc('periode')
            ->get(['id', 'societe_id', 'cadence', 'periode', 'verifie_le']);
        $aVerifier = [];

        foreach ($accordes as $client) {
            $cadence = $client->regime_tva?->cadence();

            if ($cadence === null) {
                continue;
            }

            $premiere = self::premierePeriode($client, $cadence);
            $ancre = $declarations->first(fn (CreditDeTva $credit): bool => $credit->societe_id === CleDuModele::de($client) && $credit->cadence === $cadence && $credit->periode >= $premiere);

            if ($ancre !== null && ! $ancre->estVerifie()) {
                $aVerifier[] = CleDuModele::de($client);
            }
        }

        return ValeursLues::entiers($aVerifier);
    }
}
