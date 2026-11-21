<?php

namespace Tests\Feature\Courriels;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
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
     * qui part par e-mail (une méthode toMail(), la sienne ou celle dont
     * elle hérite) du code de l'application figure dans la liste du
     * groupe, et la liste ne nomme que ceux-là. Les classes se lisent par
     * réflexion, quelle que soit la façon de les déclarer (finale, fille
     * d'une notification commune) ; une classe abstraite n'envoie rien
     * elle-même.
     */
    public function test_chaque_e_mail_du_code_figure_dans_le_groupe_courriels_reels(): void
    {
        $courriels = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            $classe = 'App\\'.str_replace('/', '\\', Str::beforeLast($fichier->getRelativePathname(), '.php'));

            if (! class_exists($classe) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            if (is_subclass_of($classe, Mailable::class) || (is_subclass_of($classe, Notification::class) && method_exists($classe, 'toMail'))) {
                $courriels[] = $classe;
            }
        }

        sort($courriels);
        $liste = CourrielsReelsTest::COURRIELS;
        sort($liste);

        $this->assertSame($courriels, $liste);
    }
}
