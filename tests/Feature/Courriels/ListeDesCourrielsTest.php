<?php

namespace Tests\Feature\Courriels;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Le groupe courriels-reels (voir CourrielsReelsTest), lancé à la demande,
 * envoie chaque e-mail de PILIQI à la boîte de test : sa liste se dresse
 * depuis le code, et un e-mail ajouté sans y figurer fait échouer la suite.
 */
class ListeDesCourrielsTest extends TestCase
{
    /**
     * Chaque e-mail (une classe qui étend Mailable) et chaque notification
     * qui part par e-mail (toMail()) du code de l'application figure dans
     * la liste du groupe, et la liste ne nomme que ceux-là.
     */
    public function test_chaque_e_mail_du_code_figure_dans_le_groupe_courriels_reels(): void
    {
        $courriels = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            $code = $fichier->getContents();

            if (preg_match('/^class (\w+) extends (Mailable|Notification)\b/m', $code, $classe) !== 1 || ($classe[2] === 'Notification' && ! str_contains($code, 'function toMail('))) {
                continue;
            }

            preg_match('/^namespace ([\w\\\\]+);/m', $code, $espace);
            $courriels[] = "{$espace[1]}\\{$classe[1]}";
        }

        sort($courriels);
        $liste = CourrielsReelsTest::COURRIELS;
        sort($liste);

        $this->assertSame($courriels, $liste);
    }
}
