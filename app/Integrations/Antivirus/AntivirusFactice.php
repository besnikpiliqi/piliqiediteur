<?php

namespace App\Integrations\Antivirus;

use App\Integrations\Antivirus\Contracts\Antivirus;

/**
 * L'antivirus factice des tests, de la démonstration et du développement
 * (pilote « factice ») : il ne reconnaît que le fichier de test EICAR, que
 * tout antivirus reconnaît aussi, partout dans le contenu, et ne fait
 * aucune requête. Jamais en production : caisse:diagnostiquer y échoue
 * (voir AntivirusServiceProvider).
 */
final class AntivirusFactice implements Antivirus
{
    /**
     * Le nom de la menace qu'il trouve, celui que ClamAV donne au fichier
     * de test EICAR.
     */
    public const MENACE = 'Eicar-Test-Signature';

    /**
     * Le fichier de test EICAR, écrit en base64 : un antivirus du poste qui
     * lirait le code de PILIQI n'y trouve pas la chaîne elle-même.
     */
    private const EICAR_EN_BASE64 = 'WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=';

    /**
     * Le fichier de test EICAR, que tout antivirus reconnaît sans danger.
     */
    public static function fichierDeTest(): string
    {
        return (string) base64_decode(self::EICAR_EN_BASE64, true);
    }

    public function analyser(string $contenu): ResultatDeLAntivirus
    {
        return str_contains($contenu, self::fichierDeTest()) ? ResultatDeLAntivirus::menace(self::MENACE) : ResultatDeLAntivirus::sain();
    }

    public function description(): string
    {
        return 'antivirus factice, qui ne reconnaît que le fichier de test EICAR';
    }
}
