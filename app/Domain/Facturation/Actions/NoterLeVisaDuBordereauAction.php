<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\VisaDetaxe;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Note la date du visa du bordereau de détaxe (BVE) qui fait foi sur un
 * ticket détaxé, le jour où le magasin l'apprend (décision de l'éditeur du
 * 01/11/2026, Q-AF13) : le ticket n'attend plus son visa dans l'« À faire »
 * du magasin, et sa TVA pourra se régulariser (ce que fera la
 * comptabilité, étape 5). Le ticket, tel qu'il a été émis et signé, ne
 * change pas : le visa s'écrit à côté, une fois par bordereau (voir
 * VisaDetaxe). Un bordereau noté ensuite pour corriger une faute attend
 * son propre visa.
 *
 * Par qui note les bordereaux de détaxe (voir FacturePolicy::justifier()),
 * même dans un magasin en lecture seule : les preuves continuent jusqu'au
 * bout (Q-AF12). Le jour du visa, au calendrier du magasin, n'est ni avant
 * le jour du ticket ni après aujourd'hui.
 *
 * Dans une transaction, son auteur se relit sous le verrou de sa ligne
 * (voir LecteurDesDroits), puis le ticket sous le sien, dans le magasin
 * actif (MagasinScope) : celui d'un autre magasin est introuvable, et deux
 * visas du même bordereau ne passent pas l'un à côté de l'autre (la base
 * refuse aussi le second). Le visa note le nom figé de son auteur et
 * s'inscrit au journal d'audit de la société, dans la chaîne du magasin.
 */
class NoterLeVisaDuBordereauAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  string  $jourDuVisa  AAAA-MM-JJ
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Facture $ticket, string $jourDuVisa): VisaDetaxe
    {
        $jourDuVisa = Calendrier::jourValide(trim($jourDuVisa))
            ?? throw new InvalidArgumentException('La date du visa n\'est pas une date valide.');

        return DB::transaction(function () use ($auteur, $ticket, $jourDuVisa): VisaDetaxe {
            $magasin = app(TenantContext::class)->magasin();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::FACTURE_JUSTIFIER, $magasin);
            $ticket = Facture::query()->whereKey($ticket->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'justifier', $magasin, $ticket);
            $bordereau = $ticket->bordereauDetaxe()->with('visa')->first();

            $refus = self::refusPour($ticket, $bordereau);

            if ($refus !== null) {
                throw new LogicException($refus);
            }

            $bordereau = ValeurRequise::de($bordereau, 'le bordereau de détaxe du ticket');
            $calendrier = $magasin->calendrier();
            $jourDuTicket = $calendrier->jourDe(ValeurRequise::de($ticket->date_emission, 'la date d\'émission du ticket'));

            if ($jourDuVisa < $jourDuTicket) {
                throw new InvalidArgumentException('Le visa ne peut pas précéder le ticket, du '.Calendrier::afficherJour($jourDuTicket, 'd/m/Y').'.');
            }

            if ($jourDuVisa > $calendrier->aujourdhui()) {
                throw new InvalidArgumentException('La date du visa ne peut pas être dans le futur.');
            }

            $visa = VisaDetaxe::forceCreate([
                'bordereau_detaxe_id' => $bordereau->getKey(),
                'date_visa' => $jourDuVisa,
                'note_par' => $compte->getKey(),
            ]);

            $this->journalAudit->inscrire(ActionAuditee::VisaDeDetaxeNote, $compte, $magasin, null, [
                'ticket' => $ticket->numero,
                'bordereau' => $bordereau->numero,
                'date_visa' => $jourDuVisa,
            ], $ticket);

            return $visa;
        });
    }

    /**
     * Pourquoi le visa de ce bordereau, celui qui fait foi sur ce ticket,
     * ne se note pas, null quand il se note : ce que l'action refuse, et ce
     * qui retire son bouton de la fiche. Un ticket qui ne garde plus aucun
     * bien taxé, tout ayant été rendu, n'attend aucun visa (voir
     * Facture::scopeVisaANoter()).
     */
    public static function refusPour(Facture $ticket, ?BordereauDetaxe $bordereau): ?string
    {
        return match (true) {
            $bordereau === null => 'Le visa se note sur un ticket détaxé : notez d\'abord le n° de son bordereau de détaxe.',
            $bordereau->visa !== null => "Le visa du bordereau n° {$bordereau->numero} est déjà noté, au ".Calendrier::afficherJour(ValeurRequise::de($bordereau->visa->date_visa, 'le jour du visa'), 'd/m/Y').'.',
            ! $ticket->lignes()->creditables()->biensTaxes()->exists() => 'Ce ticket ne garde aucun bien taxé, tous ses articles ayant été rendus : aucune marchandise ne sort de l\'Union, et aucun visa n\'est attendu.',
            default => null,
        };
    }
}
