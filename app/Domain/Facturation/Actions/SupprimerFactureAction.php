<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Supprime un brouillon de facture ou de ticket : une vente abandonnée
 * avant validation, inscrite au journal des événements (JET 320, voir
 * Facture::delete()). Une facture émise ne se supprime jamais : elle se
 * corrige par un avoir.
 *
 * Sous le verrou du registre fiscal du magasin, premier verrou de sa
 * transaction, que prennent aussi la fermeture du magasin (voir
 * FermerMagasinAction) et l'émission (voir EmettreFactureAction) : la
 * ligne du magasin se relit sous un verrou partagé (un magasin suspendu,
 * fermé ou cédé, ou dont la société est en lecture seule, ne supprime
 * plus rien, voir Magasin::verifierQuIlEstOuvert()), puis son auteur sous
 * le verrou de sa ligne (voir LecteurDesDroits), puis le brouillon sous
 * le sien. Une fermeture, une émission ou une autre suppression
 * simultanée passe donc tout entière avant la suppression, qui est alors
 * refusée, ou après elle : aucune vente abandonnée ne s'inscrit après la
 * fermeture du magasin, ni deux fois pour un même brouillon. L'auteur
 * relu doit pouvoir supprimer le brouillon relu (voir
 * FacturePolicy::delete()) ; le journal des événements inscrit
 * l'abandon à son nom (voir OperateurCourant).
 */
class SupprimerFactureAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture): void
    {
        $magasin = app(TenantContext::class)->magasin();

        $this->registre->sousVerrou($magasin, function () use ($auteur, $facture, $magasin): void {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'deleteAny', $magasin, Facture::class);

            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Facture::REFUS_INTROUVABLE);

            if ($facture->statut !== StatutFacture::Brouillon) {
                throw new LogicException(Facture::REFUS_SUPPRESSION);
            }

            $this->lecteur->exiger($compte, 'delete', $magasin, $facture);

            $this->operateur->pendant($compte, fn (): ?bool => $facture->delete());
        });
    }
}
