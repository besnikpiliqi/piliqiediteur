<?php

namespace App\Providers;

use App\Domain\Tenancy\Support\Reglage;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\ClamAv;
use App\Integrations\Antivirus\Contracts\Antivirus;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Le câblage de l'antivirus des fichiers reçus (décision de l'éditeur du
 * 07/11/2026, lot FI), selon son pilote (ANTIVIRUS_PILOTE,
 * config/services.php) : « clamav », le démon clamd du serveur, joint par
 * sa socket (CLAMAV_SOCKET) ou, à défaut, par son hôte et son port
 * (CLAMAV_HOTE, CLAMAV_PORT), en CLAMAV_DELAI secondes au plus ; ou
 * « factice », pour les tests, la démonstration et le développement, sans
 * aucune requête, que caisse:diagnostiquer refuse en production (voir
 * pilote()).
 */
class AntivirusServiceProvider extends ServiceProvider
{
    public const CLAMAV = 'clamav';

    public const FACTICE = 'factice';

    public function register(): void
    {
        $this->app->singleton(Antivirus::class, fn (): Antivirus => match (self::pilote()) {
            self::CLAMAV => new ClamAv(self::adresseDeClamd(), Reglage::entier('services.antivirus.clamav.delai')),
            self::FACTICE => new AntivirusFactice,
        });
    }

    /**
     * Le pilote configuré.
     *
     * @return self::CLAMAV|self::FACTICE
     *
     * @throws RuntimeException quand ce pilote n'existe pas
     */
    public static function pilote(): string
    {
        $pilote = config('services.antivirus.pilote');

        return in_array($pilote, [self::CLAMAV, self::FACTICE], true)
            ? $pilote
            : throw new RuntimeException('Pilote inconnu pour l\'antivirus : « '.ValeurSimple::enTexte($pilote).' » ('.self::CLAMAV.' ou '.self::FACTICE.').');
    }

    /**
     * L'adresse de clamd : sa socket Unix si elle est configurée, sinon son
     * hôte et son port.
     */
    private static function adresseDeClamd(): string
    {
        $socket = Reglage::texteOuNul('services.antivirus.clamav.socket');

        return $socket !== null && $socket !== ''
            ? 'unix://'.$socket
            : 'tcp://'.Reglage::texte('services.antivirus.clamav.hote').':'.Reglage::entier('services.antivirus.clamav.port');
    }
}
