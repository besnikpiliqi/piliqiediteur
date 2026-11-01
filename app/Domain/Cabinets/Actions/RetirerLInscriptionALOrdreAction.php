<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Pages\VerificationDuCabinet;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La procédure de l'éditeur qui constate la radiation d'un cabinet
 * d'expertise comptable du tableau de l'Ordre (editeur:cabinet:retirer-ordre),
 * en deux temps, pour garder l'ordre des verrous des liens : d'abord, une
 * transaction verrouille sa ligne de vérification, pose la radiation et
 * l'inscrit à son journal d'audit, sans prendre d'autre verrou ; le
 * cabinet n'est dès lors plus vérifié, et toute acceptation de lien, qui
 * tient cette ligne sous un verrou partagé, l'attend puis est refusée ;
 * ensuite, ses liens actifs, relus, prennent fin chacun dans sa propre
 * transaction (voir MettreFinAuLienAction::parLEditeur()), le propriétaire
 * de chaque client prévenu. Le propriétaire du cabinet est prévenu.
 */
class RetirerLInscriptionALOrdreAction
{
    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
        private readonly AccesDesCabinets $acces,
        private readonly MettreFinAuLienAction $mettreFin,
    ) {}

    /**
     * @throws LogicException
     */
    public function handle(Societe $cabinet, InterventionDeLEditeur $editeur, string $motif): int
    {
        ConstaterLInscriptionALOrdreAction::exigerUnCabinetOuvert($cabinet);
        $motif = trim($motif);

        if ($motif === '' || mb_strlen($motif) > 255) {
            throw new LogicException('Donnez le motif du constat (255 caractères au plus) : le propriétaire du cabinet le lira.');
        }

        $this->operateur->pendantLeSysteme(fn () => app(TenantContext::class)->runAsSociete($cabinet, fn () => DB::transaction(function () use ($cabinet, $editeur, $motif): void {
            $verification = $cabinet->cabinet()->lockForUpdate()->firstOrFail();

            if ($verification->ordre_retire_le !== null) {
                throw new LogicException('La radiation de ce cabinet est déjà constatée.');
            }

            $verification->forceFill(['ordre_retire_le' => now()])->save();
            $this->journalAudit->inscrire(ActionAuditee::OrdreRetire, null, null, null, ['motif' => $motif], $cabinet, $editeur);
        })));

        $liens = $this->acces->liensActifsDuCabinet($cabinet);

        foreach ($liens as $lien) {
            $this->mettreFin->parLEditeur($lien, MotifDeFinDuLien::CabinetPlusVerifie, $editeur);
        }

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            'Radiation de l\'Ordre constatée',
            "L'éditeur a constaté la radiation de votre cabinet du tableau de l'Ordre : {$motif} Tous vos liens avec vos clients ont pris fin.",
            VerificationDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir la vérification',
            AvisDuCabinet::REFUS,
            parCourriel: true,
        ));

        return $liens->count();
    }
}
