<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\DestinatairesDesAlertes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Relance à la main la transmission d'une facture que la plateforme
 * agréée n'a pas reçue après toutes les tentatives automatiques (voir
 * SubmitFactureToPdpJob::failed()), en plus de la relance de chaque heure
 * (voir app:relancer-transmissions-pdp) : la même transmission, sous la
 * même référence de dépôt, que la plateforme retrouve si elle l'a déjà
 * reçue. Permise au propriétaire et aux administrateurs qui suivent la
 * plateforme agréée (voir AutorisationsSociete::PLATEFORME_SUIVRE) dans un
 * magasin de leur portée, s'ils voient la facture : ce sont aussi ceux
 * qui sont prévenus de l'échec (décision de l'éditeur, Q-AUD3). Jamais un
 * membre du magasin ni un compte d'un cabinet lié.
 */
class RelancerLaTransmissionAction
{
    public const REFUS_RIEN_A_RELANCER = "Cette facture n'a aucune transmission en échec à relancer.";

    /**
     * Peut-il relancer la transmission de cette facture, dans le magasin
     * actif, qui doit être le sien ?
     */
    public static function permiseA(User $compte, Facture $facture): bool
    {
        $magasin = $facture->magasin;
        $contexte = app(TenantContext::class);

        return $contexte->has()
            && $contexte->id() === $magasin->getKey()
            && Gate::forUser($compte)->allows(AutorisationsSociete::PLATEFORME_SUIVRE)
            && $compte->administreLeMagasin($magasin)
            && Gate::forUser($compte)->allows('view', $facture);
    }

    /**
     * Les comptes prévenus d'une transmission de cette facture qui n'a pas
     * abouti : ceux qui peuvent la relancer (voir permiseA()), dans le
     * contexte de son magasin.
     *
     * @return Collection<int, User>
     */
    public static function comptesPrevenus(Facture $facture): Collection
    {
        return app(DestinatairesDesAlertes::class)
            ->autorisesDeSocieteDans($facture->magasin, AutorisationsSociete::PLATEFORME_SUIVRE)
            ->filter(fn (User $compte): bool => self::permiseA($compte, $facture))
            ->values();
    }

    /**
     * @throws AuthorizationException quand l'auteur ne peut pas la relancer
     * @throws LogicException quand la dernière transmission de la facture n'est pas en échec technique
     */
    public function handle(User $auteur, Facture $facture): void
    {
        if (! self::permiseA($auteur, $facture)) {
            throw new AuthorizationException;
        }

        $derniere = $facture->transmissionsPdp()->where('direction', 'outbound')->latest('id')->first();

        if ($derniere?->statut !== StatutTransmissionPdp::EchecTechnique) {
            throw new LogicException(self::REFUS_RIEN_A_RELANCER);
        }

        SubmitFactureToPdpJob::dispatch($facture->magasin_id, $facture->id);
    }
}
