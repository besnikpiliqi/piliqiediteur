<?php

namespace Tests\Unit;

use App\Domain\CaisseSecurisee\Support\ErreursOpenSsl;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'OpenSSL dit d'une opération de clé qui échoue, sans base ni
 * application. L'échec de PHP sous Windows tant que la variable
 * OPENSSL_CONF ne désigne pas openssl.cnf se reproduit sur tout système :
 * une génération de clé à qui l'on donne un fichier de configuration
 * absent échoue de la même façon. La famille du système se passe à
 * detail() : chaque cas se vérifie quel que soit celui de la machine de
 * test.
 */
class ErreursOpenSslTest extends TestCase
{
    private const CONFIGURATION_ABSENTE = __DIR__.'/../Fixtures/CaisseSecurisee/aucun-openssl.cnf';

    private function genererSansConfiguration(): void
    {
        $this->assertFileDoesNotExist(self::CONFIGURATION_ABSENTE);
        $this->assertFalse(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => self::CONFIGURATION_ABSENTE]));
    }

    /**
     * Les erreurs restées d'une opération précédente n'y figurent pas,
     * celles d'une même erreur répétée n'y figurent qu'une fois, et la
     * file en sort vide ; hors de Windows, aucune marche à suivre ne s'y
     * ajoute.
     */
    public function test_le_detail_ne_rend_que_les_erreurs_de_l_operation_chacune_une_fois(): void
    {
        openssl_pkey_get_private('pas une clé');
        $erreurPrecedente = openssl_error_string();
        ErreursOpenSsl::oublier();
        $this->genererSansConfiguration();
        $detailDUnEchec = ErreursOpenSsl::detail('Linux');
        openssl_pkey_get_private('pas une clé');

        ErreursOpenSsl::oublier();
        $this->genererSansConfiguration();
        $this->genererSansConfiguration();
        $detail = ErreursOpenSsl::detail('Linux');

        $this->assertIsString($erreurPrecedente);
        $this->assertStringStartsWith('Erreurs d\'OpenSSL : error:', $detail);
        $this->assertStringEndsWith('.', $detail);
        $this->assertStringNotContainsString($erreurPrecedente, $detail);
        $this->assertSame($detailDUnEchec, $detail);
        $this->assertStringNotContainsString('OPENSSL_CONF', $detail);
        $this->assertFalse(openssl_error_string());
    }

    public function test_sous_windows_le_detail_dit_de_regler_openssl_conf_sur_le_fichier_openssl_cnf_de_php(): void
    {
        ErreursOpenSsl::oublier();
        $this->genererSansConfiguration();

        $detail = ErreursOpenSsl::detail('Windows');

        $this->assertStringStartsWith('Erreurs d\'OpenSSL : error:', $detail);
        $this->assertStringEndsWith('. Sous Windows, PHP ne trouve pas le fichier openssl.cnf tant que la variable d\'environnement OPENSSL_CONF ne le désigne pas : '
            .'réglez-la sur le fichier openssl.cnf de PHP (par exemple, dans une invite de commandes : setx OPENSSL_CONF "C:\xampp\php\extras\ssl\openssl.cnf"), '
            .'puis fermez et rouvrez le terminal, l\'éditeur de code qui l\'ouvre et le XAMPP Control Panel, qui ne voient la variable qu\'à leur lancement, et redémarrez Apache s\'il sert l\'application. '
            .'Si Apache tourne comme service Windows, réglez-la pour tout le système (setx /M OPENSSL_CONF "C:\xampp\php\extras\ssl\openssl.cnf", dans une invite de commandes lancée en administrateur), puis redémarrez Windows : un service du compte Système local, comme celui qu\'installe XAMPP, ne voit ni une variable d\'utilisateur ni une variable du système réglée après le démarrage. '
            .'Relancez ensuite php artisan caisse:diagnostiquer.', $detail);
    }

    public function test_sans_erreur_d_openssl_le_detail_le_dit(): void
    {
        ErreursOpenSsl::oublier();

        $detail = ErreursOpenSsl::detail('Linux');

        $this->assertSame('OpenSSL n\'a laissé aucun message d\'erreur.', $detail);
    }
}
