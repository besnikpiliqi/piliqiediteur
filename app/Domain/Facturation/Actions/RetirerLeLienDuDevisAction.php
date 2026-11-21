<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le magasin retire le lien secret d'un devis (décision de l'éditeur du
 * 02/11/2026 : « le magasin peut le retirer ») : le client ne peut plus
 * y répondre en ligne ; le devis, lui, ne change pas, et un nouvel envoi
 * par e-mail lui donnera un nouveau lien.
 *
 * Dans une transaction, la ligne du magasin sous un verrou partagé, puis
 * l'auteur sous le verrou de sa ligne (voir LecteurDesDroits), qui doit
 * pouvoir envoyer ce devis (voir DevisPolicy::envoyer()), puis le devis
 * sous le sien : une réponse du client en cours (voir
 * RepondreAuDevisEnLigneAction, qui verrouille le devis) passe avant ou
 * après le retrait, jamais pendant. Le retrait garde l'instant et le nom
 * figé de son auteur, et s'inscrit au journal d'audit.
 */
class RetirerLeLienDuDevisAction
{
    public const REFUS_SANS_LIEN = 'Ce devis n\'a aucun lien de réponse qui vaut encore : rien à retirer.';

    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly JournalAudit $journal,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): LienDuDevis
    {
        return DB::transaction(function () use ($auteur, $devis): LienDuDevis {
            $magasin = app(TenantContext::class)->magasin();
            $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::DEVIS_ENVOYER, $magasin);

            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);
            $devis->setRelation('magasin', $magasinRelu);
            $this->lecteur->exiger($compte, 'envoyer', $magasin, $devis);

            $lien = $devis->lienValable() ?? throw new LogicException(self::REFUS_SANS_LIEN);
            $lien->forceFill(['retire_le' => CarbonImmutable::now(), 'retire_par' => $compte->getKey()])->save();

            $this->journal->inscrire(ActionAuditee::LienDuDevisRetire, $compte, $magasinRelu, null, ['devis' => $devis->numero], $devis);

            return $lien;
        });
    }
}
