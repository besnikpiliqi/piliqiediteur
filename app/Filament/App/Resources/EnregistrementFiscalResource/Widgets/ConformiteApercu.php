<?php

namespace App\Filament\App\Resources\EnregistrementFiscalResource\Widgets;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\ModuleFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit sous Resources/EnregistrementFiscalResource/Widgets, et non sous
 * Filament/App/Widgets (rendu sur le tableau de bord) : il n'a de sens
 * que sur la caisse sécurisée, attaché par
 * ListEnregistrementsFiscaux::getHeaderWidgets().
 *
 * La conformité du module en un coup d'œil : sa version et la référence
 * légale, l'empreinte de la clé qui signe, les grands totaux perpétuels
 * réel et absolu tels que les signe le dernier enregistrement de la chaîne
 * des ventes du magasin, et la dernière vérification de l'intégrité du
 * registre, telle que l'inscrit son opération de contrôle (JET 280, voir
 * VerifierIntegriteAction).
 */
class ConformiteApercu extends BaseWidget
{
    protected function getStats(): array
    {
        $derniereVente = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Vente->value)
            ->orderByDesc('sequence')
            ->first();
        $grandsTotaux = $derniereVente?->contenuSigne()['gt'] ?? null;
        $origine = $derniereVente === null
            ? 'Aucune vente enregistrée'
            : sprintf(
                'Signé par la vente n° %d du %s',
                $derniereVente->sequence,
                $derniereVente->horodatage->setTimezone(config('app.timezone'))->format('d/m/Y à H:i:s'),
            );

        return [
            Stat::make('Module fiscal', 'Version '.ModuleFiscal::VERSION)
                ->description(ModuleFiscal::REFERENCE_LEGALE)
                ->color('gray'),
            $this->cleDeSignature(),
            Stat::make('Grand total perpétuel réel', self::enEuros($grandsTotaux['perpetuel_reel'] ?? 0))
                ->description($origine)
                ->color('success'),
            Stat::make('Grand total perpétuel absolu', self::enEuros($grandsTotaux['perpetuel_absolu'] ?? 0))
                ->description($origine)
                ->color('success'),
            $this->derniereVerification(),
        ];
    }

    /**
     * Le résultat de la dernière vérification de l'intégrité du registre,
     * et quand elle a eu lieu.
     */
    private function derniereVerification(): Stat
    {
        $controle = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::OperationDeControle->value)
            ->orderByDesc('sequence')
            ->first();

        if ($controle === null) {
            return Stat::make('Dernière vérification', 'Jamais')
                ->description('Aucune vérification de l\'intégrité du registre')
                ->color('warning');
        }

        $details = $controle->contenuSigne()['donnees']['details'] ?? [];
        $anomalies = (int) ($details['nombre_anomalies'] ?? 0);
        $quand = sprintf(
            'Vérification %s du %s',
            ($details['verification'] ?? null) === 'complete' ? 'complète' : 'incrémentale',
            $controle->horodatage->setTimezone(config('app.timezone'))->format('d/m/Y à H:i:s'),
        );

        return $anomalies === 0
            ? Stat::make('Dernière vérification', 'Conforme')->description($quand)->color('success')
            : Stat::make('Dernière vérification', "{$anomalies} anomalie(s)")->description($quand)->color('danger');
    }

    /**
     * L'empreinte (cle_id) de la clé qui signe, ou pourquoi aucune clé ne
     * signe : sans elle, aucune opération fiscale n'est acceptée.
     */
    private function cleDeSignature(): Stat
    {
        try {
            $cleId = app(SignataireFiscal::class)->cleId();
        } catch (ModuleFiscalNonConfigureException $exception) {
            return Stat::make('Clé de signature', 'Non configurée')
                ->description($exception->getMessage())
                ->color('danger');
        }

        return Stat::make('Clé de signature', mb_substr($cleId, 0, 16).'…')
            ->description('Empreinte SHA-256 : '.$cleId)
            ->color('success');
    }

    private static function enEuros(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', ' ').' €';
    }
}
