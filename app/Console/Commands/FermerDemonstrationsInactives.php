<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\FermerLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Scopes\MagasinScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Chaque jour (voir bootstrap/app.php), ferme les magasins de
 * démonstration qui ne servent plus (voir FermerLaDemonstrationAction) :
 * celui d'une société validée ou refusée dont la fermeture a échoué (il ne
 * vend déjà plus, voir Magasin::peutVendre()), et celui qui n'a fait
 * aucune vente ni aucun encaissement depuis trente jours, ni depuis sa
 * dernière ouverture : il ne produit plus de ticket Z sans fin, et se
 * rouvre à la demande tant que la société n'est ni validée ni refusée
 * (voir OuvrirLaDemonstrationAction). La panne d'un magasin n'arrête pas
 * les suivants.
 */
#[Signature('app:fermer-demonstrations-inactives')]
#[Description('Ferme les magasins de démonstration inactifs depuis 30 jours ou dont la société est validée')]
class FermerDemonstrationsInactives extends Command
{
    public const JOURS_D_INACTIVITE = 30;

    public function handle(FermerLaDemonstrationAction $fermer): int
    {
        $limite = CarbonImmutable::now()->subDays(self::JOURS_D_INACTIVITE);
        $fermees = 0;
        $enEchec = 0;

        Magasin::query()
            ->with('societe')
            ->where('est_demonstration', true)
            ->where('statut', StatutMagasin::Actif->value)
            ->orderBy('id')
            ->each(function (Magasin $demonstration) use ($fermer, $limite, &$fermees, &$enEchec): void {
                if (! $demonstration->societe->estValidee() && ! $demonstration->societe->estRefusee() && ! self::estInactiveDepuis($demonstration, $limite)) {
                    return;
                }

                try {
                    $fermer->handle($demonstration);
                    $fermees++;
                } catch (Throwable $erreur) {
                    report($erreur);
                    $enEchec++;
                }
            });

        $this->info("{$fermees} magasin(s) de démonstration fermé(s).");

        return $enEchec > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Aucune vente ni aucun encaissement depuis la limite, ni d'ouverture.
     * Lectures filtrées explicitement par ce magasin.
     */
    private static function estInactiveDepuis(Magasin $demonstration, CarbonImmutable $limite): bool
    {
        $ouverture = PeriodeActivite::query()->where('magasin_id', $demonstration->getKey())->whereNull('fin')->value('debut');
        $derniereOperation = EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->where('magasin_id', $demonstration->getKey())
            ->whereIn('chaine', [ChaineFiscale::Vente->value, ChaineFiscale::Encaissement->value])
            ->max('horodatage');

        return collect([$ouverture, $derniereOperation])->filter()->every(fn ($instant): bool => CarbonImmutable::parse($instant)->lessThan($limite));
    }
}
