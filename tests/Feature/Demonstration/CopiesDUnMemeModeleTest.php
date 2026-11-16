<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\ConstruireUnModele;
use App\Domain\Demonstration\Contracts\SemisDUnModele;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\DemonstrationTestCase;

/**
 * Les précisions de l'éditeur du 16/11/2026 (lot DEMO-COPIE, fin du
 * cahier) : chaque société a sa propre copie, et deux copies du même
 * modèle n'écrivent chacune que dans sa base (une facture émise dans l'une
 * n'existe pas dans l'autre) ; la nuit, seuls les modèles sont refaits,
 * jamais une copie : la reconstruction laisse chaque copie existante
 * intacte (mêmes lignes, même empreinte du registre), même quand le modèle
 * d'où elle vient est supprimé.
 */
class CopiesDUnMemeModeleTest extends DemonstrationTestCase
{
    public function test_deux_copies_du_meme_modele_n_ecrivent_chacune_que_dans_sa_base(): void
    {
        $this->travelTo(self::aParis('2026-11-20 15:00:00'));
        ['copie' => $premiere] = $this->copieDeTest(TypeDActivite::Commerce);
        ['copie' => $seconde] = $this->copieDeTest(TypeDActivite::Commerce);
        $this->assertSame($premiere->modele_id, $seconde->modele_id, 'Les deux copies partent du même modèle.');
        $this->assertNotSame($premiere->nom_de_la_base, $seconde->nom_de_la_base);
        $secondeAvant = $this->etatDeLaCopie($seconde);

        $facture = $this->vendreDansLaCopie($premiere, 'Les Halles');

        $this->assertContains($facture->numero, $this->etatDeLaCopie($premiere)['factures']);
        $this->assertNotContains($facture->numero, $this->etatDeLaCopie($seconde)['factures']);
        $this->assertSame($secondeAvant, $this->etatDeLaCopie($seconde), 'Rien de ce que la première copie écrit n\'arrive dans la seconde.');
    }

    public function test_la_reconstruction_des_modeles_laisse_chaque_copie_existante_intacte(): void
    {
        $this->travelTo(self::aParis('2026-11-20 15:00:00'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Commerce);
        $this->vendreDansLaCopie($copie, 'Les Halles');
        $modeleDOrigine = Modele::on('demo_admin')->whereKey($copie->modele_id)->sole();
        $copieAvant = Copie::on('demo_admin')->whereKey($copie->id)->sole()->getAttributes();
        $etatAvant = $this->etatDeLaCopie($copie);
        $this->app->instance(SemisDUnModele::class, new class implements SemisDUnModele
        {
            public function semer(TypeDActivite $type, string $motDePasse): void {}
        });

        // Deux nuits : la seconde construction supprime le modèle d'où
        // vient la copie (« précédent » depuis la première).
        $this->travelTo(self::aParis('2026-11-21 01:10:00')->addMinutes(self::jeton()));
        app(ConstruireUnModele::class)->construire(TypeDActivite::Commerce);
        $this->travelTo(self::aParis('2026-11-22 01:10:00')->addMinutes(self::jeton()));
        app(ConstruireUnModele::class)->construire(TypeDActivite::Commerce);

        $bases = app(BasesDeLaDemonstration::class);
        $this->assertSame(EtatDUnModele::Supprime, $modeleDOrigine->refresh()->etat);
        $this->assertFalse($bases->existe((string) $modeleDOrigine->nom_de_la_base));
        $this->assertTrue($bases->existe((string) $copie->nom_de_la_base), 'La copie garde sa base.');
        $this->assertSame($copieAvant, Copie::on('demo_admin')->whereKey($copie->id)->sole()->getAttributes(), 'La ligne de la copie au registre ne change pas.');
        $this->assertSame($etatAvant, $this->etatDeLaCopie($copie), 'La copie garde les mêmes lignes et la même empreinte de son registre fiscal.');
    }

    /**
     * Ce que contient la base de cette copie : le nombre de lignes de
     * chaque table, les numéros de ses factures et l'empreinte des
     * signatures de son registre fiscal, dans l'ordre.
     *
     * @return array{lignes: array<string, int>, factures: list<string>, registre: string}
     */
    private function etatDeLaCopie(Copie $copie): array
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, function (): array {
            $lignes = [];

            foreach (DB::table('pg_tables')->where('schemaname', 'public')->orderBy('tablename')->pluck('tablename') as $table) {
                $lignes[(string) $table] = DB::table((string) $table)->count();
            }

            $etat = [
                'lignes' => $lignes,
                'factures' => DB::table('factures')->whereNotNull('numero')->orderBy('id')->pluck('numero')->map(fn (mixed $numero): string => (string) $numero)->values()->all(),
                'registre' => hash('sha256', DB::table('enregistrements_fiscaux')->orderBy('id')->pluck('signature')->implode("\n")),
            ];
            DB::disconnect();

            return $etat;
        });
    }

    /**
     * Un ticket de 12,00 € TTC émis dans ce magasin de la copie, par son
     * responsable de test, comme le ferait la caisse.
     */
    private function vendreDansLaCopie(Copie $copie, string $nomDuMagasin): Facture
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, function () use ($nomDuMagasin): Facture {
            $magasin = Magasin::query()->withoutGlobalScopes()->where('nom', $nomDuMagasin)->sole();
            app(TenantContext::class)->set($magasin);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ]));
            app(TenantContext::class)->clear();
            DB::disconnect();

            return $facture;
        });
    }
}
