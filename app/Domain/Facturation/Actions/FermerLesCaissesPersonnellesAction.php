<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/**
 * Ferme d'office, jusqu'à une borne, les caisses personnelles du magasin
 * restées ouvertes (R5) : une caisse personnelle ne bloque jamais la
 * clôture de la journée. Appelée après la clôture (manuelle, voir
 * ListClotureCaisses, la borne étant alors l'instant de la clôture ; ou
 * automatique, voir ClotureAutomatiqueCaisse), et passé le minuit du
 * magasin pour toute caisse restée ouverte sa veille, même après une
 * clôture manuelle (la borne étant alors la fin de sa veille). Chaque relevé porte la mention
 * « Fermée automatiquement » (voir ClotureCaissePersonnelle).
 *
 * Toujours après la transaction de la clôture, jamais dedans, et une
 * transaction par vendeur (voir FermerSaCaisseAction::fermerDOffice()) :
 * l'échec d'une fermeture, signalé au journal de l'application
 * (report()), n'annule ni la clôture de la journée ni la fermeture des
 * autres caisses, et sa caisse se ferme au passage horaire suivant de la
 * clôture automatique. Ces fermetures sont celles du système, jamais de la personne
 * connectée (voir OperateurCourant).
 */
class FermerLesCaissesPersonnellesAction
{
    public function __construct(
        private readonly VerificateurCaissesPersonnelles $verificateur,
        private readonly FermerSaCaisseAction $fermerSaCaisse,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * Ferme les caisses personnelles du magasin, qui doit être le magasin
     * actif, ouvertes jusqu'à la journée de la borne, dans son calendrier :
     * chacune
     * compte ses ventes émises jusqu'à la borne et ses encaissements reçus
     * jusqu'à sa journée.
     *
     * @return Collection<int, ClotureCaissePersonnelle> les relevés notés, par vendeur
     *
     * @throws LogicException
     */
    public function handle(Magasin $magasin, CarbonInterface $jusquAu): Collection
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new LogicException('Les caisses personnelles d\'un magasin ne se ferment que depuis ce magasin.');
        }

        $borne = CarbonImmutable::instance($jusquAu);

        return $this->operateur->pendantLeSysteme(fn (): Collection => $this->verificateur->vendeursAvecCaisseOuverte($magasin->calendrier()->jourDe($borne))
            ->map(function (User $vendeur) use ($borne): ?ClotureCaissePersonnelle {
                try {
                    return $this->fermerSaCaisse->fermerDOffice($vendeur, $borne);
                } catch (Throwable $exception) {
                    report($exception);

                    return null;
                }
            })
            ->filter()
            ->values());
    }
}
