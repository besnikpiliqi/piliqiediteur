<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * La procédure de l'éditeur qui retire, sur pièces, un ticket Z que rien
 * ne signe (editeur:magasin:retirer-ticket-z) : écrit directement dans la
 * base, sans la clôture de sa journée ni l'événement 50 qui le signe, il
 * arrête la clôture du magasin (voir TicketZNonSigneException).
 * Procédure grave, lancée par deux opérateurs distincts.
 *
 * Sous le verrou du registre du magasin : le ticket Z de ce numéro, relu
 * dans le magasin, doit n'avoir aucun événement 50 qui le signe au
 * journal des événements ; un ticket Z signé ne se retire jamais. Les
 * tickets et les avoirs qu'on y avait rattachés s'en détachent, puis il
 * est supprimé, seule suppression que la base laisse passer sur la table
 * des tickets Z (voir GardesDesClotures). Le retrait s'inscrit au journal
 * des événements du magasin (JET 270 ticket_z_non_signe) et au journal
 * d'audit de la société, avec l'intervention de l'éditeur. Ensuite, hors
 * de cette transaction, les journées en attente du magasin se clôturent
 * au nom du système, comme à la clôture automatique (voir
 * CloturerLesJourneesAction) : le vrai ticket Z de la journée est produit,
 * et les documents détachés y entrent. Rien d'autre ne bouge : aucun
 * document émis, aucun paiement, aucun enregistrement du registre.
 */
class RetirerUnTicketZNonSigneAction
{
    public const MESSAGE_DEUX_OPERATEURS = 'Le retrait d\'un ticket Z non signé exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_INTROUVABLE = 'Aucun ticket Z de ce numéro dans ce magasin.';

    public const MESSAGE_SIGNE = 'Ce ticket Z est signé par la clôture de sa journée au journal des événements : il ne se retire jamais.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
        private readonly CloturerLesJourneesAction $cloturerLesJournees,
    ) {}

    /**
     * @return array{retire: array{numero: string, journee: string, documents_detaches: int}, tickets_z: list<string>, cloture_arretee: string|null} le ticket Z retiré, les numéros des tickets Z produits ensuite, et pourquoi la clôture s'est arrêtée, s'il le faut
     *
     * @throws LogicException quand le ticket Z ne se retire pas
     */
    public function handle(Magasin $magasin, string $numero, InterventionDeLEditeur $editeur): array
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new LogicException(self::MESSAGE_DEUX_OPERATEURS);
        }

        return app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => app(TenantContext::class)->runAs($magasin, function () use ($magasin, $numero, $editeur): array {
            $retire = $this->operateur->pendantLeSysteme(fn (): array => $this->registre->sousVerrou($magasin, fn (): array => $this->retirer($magasin, $numero, $editeur)));
            $calendrier = $magasin->calendrier();
            $ticketsZ = [];
            $clotureArretee = null;

            try {
                $ticketsZ = $this->cloturerLesJournees->parLeSysteme($magasin, Calendrier::ajouterJours($calendrier->aujourdhui(), -1))
                    ->map(fn (ClotureCaisse $cloture): string => (string) $cloture->numero)
                    ->values()
                    ->all();
            } catch (Throwable $refus) {
                report($refus);
                $clotureArretee = $refus->getMessage();
            }

            return ['retire' => $retire, 'tickets_z' => $ticketsZ, 'cloture_arretee' => $clotureArretee];
        }));
    }

    /**
     * @return array{numero: string, journee: string, documents_detaches: int}
     *
     * @throws LogicException
     */
    private function retirer(Magasin $magasin, string $numero, InterventionDeLEditeur $editeur): array
    {
        $ticketZ = ClotureCaisse::query()->where('numero', $numero)->first()
            ?? throw new LogicException(self::MESSAGE_INTROUVABLE);

        if (EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::CloturePeriode->value)
            ->where('document_type', $ticketZ->getMorphClass())
            ->where('document_id', $ticketZ->getKey())
            ->exists()) {
            throw new LogicException(self::MESSAGE_SIGNE);
        }

        $documentsDetaches = Facture::query()->where('cloture_caisse_id', $ticketZ->getKey())->update(['cloture_caisse_id' => null])
            + Avoir::query()->where('cloture_caisse_id', $ticketZ->getKey())->update(['cloture_caisse_id' => null]);
        DB::table('clotures_caisse')
            ->where('magasin_id', $magasin->getKey())
            ->where('id', $ticketZ->getKey())
            ->delete();

        $retire = ['numero' => (string) $ticketZ->numero, 'journee' => $ticketZ->date_cloture, 'documents_detaches' => $documentsDetaches];
        $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
            'parametre' => 'ticket_z_non_signe',
            'magasin' => ['id' => CleDuModele::de($magasin), 'nom' => $magasin->nom],
            'ancienne_valeur' => $retire,
            'nouvelle_valeur' => null,
        ]);
        $this->journalAudit->inscrire(ActionAuditee::TicketZNonSigneRetire, null, $magasin, $retire, null, $magasin, $editeur);

        return $retire;
    }
}
