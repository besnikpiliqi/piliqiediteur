<?php

namespace Tests\Feature\Filament;

use App\Filament\Societe\Resources\MembreResource;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'adresse de la fiche d'une ressource, dans chacun des trois panneaux
 * (magasin, espace de la société, espace d'un cabinet), porte la clé de
 * son modèle : une clé que la base ne peut pas lire (un mot, zéro, un
 * nombre négatif, un nombre trop grand pour la colonne) répond 404, jamais
 * 500, sans interroger la base (constat de la relecture du lot ER-PRO :
 * la fiche d'un membre, d'une entrée du journal d'audit, d'un
 * collaborateur et toutes celles du magasin répondaient 500 ; voir
 * RefuseUneCleIllisible). Chaque ressource qui a une fiche est essayée,
 * pour qu'une fiche ajoutée plus tard le soit aussi.
 */
class FichesAUneCleIllisibleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les clés qu'aucune colonne d'identifiant ou d'uuid ne lit.
     */
    private const CLES_ILLISIBLES = ['abc', '0', '-1', '99999999999999999999'];

    private const CLE_DE_GABARIT = 987654321;

    public function test_chaque_fiche_du_magasin_a_une_cle_illisible_repond_404(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $this->assertSame(13, $this->essayerChaqueFiche('app'));
    }

    public function test_chaque_fiche_de_l_espace_de_la_societe_a_une_cle_illisible_repond_404(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $this->assertSame(3, $this->essayerChaqueFiche('societe'));
    }

    public function test_chaque_fiche_de_l_espace_du_cabinet_a_une_cle_illisible_repond_404(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        $this->assertSame(3, $this->essayerChaqueFiche('cabinet'));
    }

    /**
     * La fiche d'un compte existant s'ouvre toujours : la clé lisible
     * passe le contrôle, et un compte d'une autre société reste
     * introuvable (404).
     */
    public function test_une_cle_lisible_ouvre_la_fiche_et_celle_d_une_autre_societe_reste_introuvable(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $this->get(MembreResource::getUrl('view', ['record' => $proprietaire]))->assertOk();
        $this->get(MembreResource::getUrl('view', ['record' => $this->proprietaireDe($ailleurs)]))->assertNotFound();
    }

    /**
     * Chaque ressource du panneau qui a une fiche (vue ou modification),
     * appelée avec chaque clé illisible : 404. Rend le nombre de
     * ressources essayées.
     */
    private function essayerChaqueFiche(string $panneau): int
    {
        $essayees = 0;

        foreach (Filament::getPanel($panneau)->getResources() as $ressource) {
            $page = $this->pageDeLaFiche($ressource);

            if ($page === null) {
                continue;
            }

            $gabarit = $ressource::getUrl($page, ['record' => self::CLE_DE_GABARIT], panel: $panneau, tenant: Filament::getTenant());

            foreach (self::CLES_ILLISIBLES as $cle) {
                $reponse = $this->get(str_replace((string) self::CLE_DE_GABARIT, $cle, $gabarit));
                $this->assertSame(404, $reponse->status(), "{$ressource} avec la clé « {$cle} » : {$reponse->status()}");
            }

            $essayees++;
        }

        return $essayees;
    }

    /**
     * @param  class-string<resource>  $ressource
     */
    private function pageDeLaFiche(string $ressource): ?string
    {
        foreach (['view', 'edit'] as $page) {
            if (array_key_exists($page, $ressource::getPages())) {
                return $page;
            }
        }

        return null;
    }
}
