<?php

namespace Tests\Feature\Demonstration;

use App\Http\Middleware\OuvrirLaCopie;
use Illuminate\Routing\Route;
use Tests\DemonstrationTestCase;

/**
 * Les portes du site de démonstration (lot DEMO-COPIE, A.7, C.6.1 et
 * C.15, N2) : sans témoin valable, chaque route de chaque panneau, de
 * chaque page publique et de l'API répond la page introuvable française,
 * la même qu'au site réel, sans dire qu'une démonstration existe ; seules
 * les adresses d'entrée, celles des messages et de l'état du site, et
 * robots.txt répondent. Les pages fermées (connexion, « Mot de passe
 * oublié », inscription, code du courrier) et l'en-tête noindex sont
 * vérifiés par EntreeParLeLienTest et SiteDePiliqiTest.
 */
class PortesFermeesTest extends DemonstrationTestCase
{
    public function test_sans_temoin_chaque_route_de_l_application_repond_introuvable(): void
    {
        $ouvertes = [];
        $essais = 0;

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $adresse = trim((string) preg_replace(['/\{[^}]+\?\}/', '/\{[^}]+\}/'], ['', 'introuvable'], $route->uri()), '/');

            if (in_array(trim($route->uri(), '/'), OuvrirLaCopie::ADRESSES_SANS_COPIE, true)) {
                continue;
            }

            foreach (array_intersect($route->methods(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) as $methode) {
                $reponse = $this->call($methode, '/'.$adresse);
                $essais++;

                if ($reponse->status() !== 404 || ! str_contains((string) $reponse->getContent(), 'Page introuvable')) {
                    $ouvertes[] = $methode.' /'.$adresse.' ('.$reponse->status().')';
                }
            }
        }

        $this->assertGreaterThan(100, $essais, 'Le balayage passe par toutes les routes des trois panneaux et des pages publiques.');
        $this->assertSame([], $ouvertes, 'Sans témoin, ces routes répondent autre chose que la page introuvable.');
    }
}
