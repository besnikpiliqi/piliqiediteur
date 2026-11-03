<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Les instants s'enregistrent, se relisent et se comparent en UTC, dans la
 * base comme en PHP, et les jours restent des jours : aux deux passages
 * de l'heure qui se répète le 25 octobre 2026 (de 2 h à 3 h à Paris,
 * 00:00 à 02:00 UTC), chaque instant garde le sien. Filament affiche un
 * instant à l'heure du magasin actif, un jour tel quel.
 */
class HorodatageEnUtcTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function instantsDuPremierPassage(): array
    {
        return [
            'attribut casté' => ['attribut'],
            'horodatage d\'Eloquent' => ['eloquent'],
            'jeton Mot de passe oublié' => ['jeton'],
        ];
    }

    /**
     * Un instant enregistré pendant le premier passage de l'heure répétée
     * (00:30 UTC, 2 h 30 à l'heure d'été) se relit exactement à cet
     * instant, et jamais une heure plus tard au second passage.
     */
    #[DataProvider('instantsDuPremierPassage')]
    public function test_un_instant_enregistre_au_premier_passage_de_l_heure_repetee_se_relit_au_meme_instant(string $cas): void
    {
        $premierPassage = self::enUtc('2026-10-25 00:30:00');
        $this->travelTo($premierPassage);

        $relu = match ($cas) {
            'attribut' => $this->demandeInscription($premierPassage->setTimezone(Calendrier::deParis()->fuseau()))->fresh()->expire_le,
            'eloquent' => Societe::factory()->create()->fresh()->created_at,
            'jeton' => $this->jetonMotDePasseOublie(),
        };

        $this->assertSame($premierPassage->getTimestamp(), $relu->getTimestamp());
    }

    /**
     * L'horloge figée au premier passage le reste : now() rend cet
     * instant, et non le second passage de la même heure murale.
     */
    public function test_l_horloge_figee_au_premier_passage_de_l_heure_repetee_garde_son_instant(): void
    {
        $premierPassage = self::enUtc('2026-10-25 00:30:00');
        $this->travelTo($premierPassage);

        $this->assertSame([$premierPassage->getTimestamp(), $premierPassage->getTimestamp()], [now()->getTimestamp(), CarbonImmutable::now()->getTimestamp()]);
    }

    /**
     * La base compare deux instants des deux passages dans leur ordre
     * réel : 00:50 UTC est avant 01:00 UTC, 01:10 UTC après, alors que
     * leurs heures murales de Paris (2 h 50, 2 h 10) disent l'inverse.
     */
    public function test_la_base_compare_les_instants_des_deux_passages_dans_leur_ordre(): void
    {
        $avant = $this->demandeInscription(self::enUtc('2026-10-25 00:50:00'));
        $this->demandeInscription(self::enUtc('2026-10-25 01:10:00'));

        $this->assertSame([$avant->id], DemandeInscription::query()->where('expire_le', '<', self::enUtc('2026-10-25 01:00:00'))->pluck('id')->all());
    }

    /**
     * Un instant s'écrit en UTC quel que soit le fuseau de la date donnée.
     */
    public function test_un_instant_s_ecrit_en_utc_quel_que_soit_le_fuseau_de_la_date_donnee(): void
    {
        $deParis = $this->demandeInscription(Calendrier::deParis()->heureMurale('2026-10-09 10:00'));
        $aPlusCinq = $this->demandeInscription(new DateTimeImmutable('2026-10-09T13:00:00+05:00'));

        $enBase = Instant::enBase(self::enUtc('2026-10-09 08:00:00'));

        $this->assertSame([$enBase, $enBase], DemandeInscription::query()->whereKey([$deParis->id, $aPlusCinq->id])->orderBy('id')->pluck('expire_le')->map(fn (CarbonImmutable $instant): string => Instant::enBase($instant))->all());
        $this->assertSame([$enBase, $enBase], DB::table('demandes_inscription')->orderBy('id')->pluck('expire_le')->all());
    }

    public function test_une_chaine_est_refusee_pour_un_instant(): void
    {
        $this->assertThrows(fn () => (new DemandeInscription)->forceFill(['expire_le' => '2026-10-09T08:00:00Z']), InvalidArgumentException::class, "L'instant expire_le s'écrit avec une date qui porte son fuseau, jamais avec une chaîne.");
    }

    /**
     * Un jour civil s'écrit et se relit tel quel, « AAAA-MM-JJ », sur les
     * deux moteurs ; un objet date, qui porte une heure et un fuseau, est
     * refusé.
     */
    public function test_un_jour_civil_s_ecrit_et_se_relit_tel_quel_et_refuse_un_objet_date(): void
    {
        $paiement = new Paiement;
        $paiement->forceFill(['recu_le' => '2026-10-25']);

        $this->assertSame(['2026-10-25', '2026-10-25'], [$paiement->recu_le, $paiement->getAttributes()['recu_le']]);
        $this->assertThrows(fn () => $paiement->forceFill(['recu_le' => Calendrier::deParis()->heureMurale('2026-10-25 10:00')]), InvalidArgumentException::class, "Le jour recu_le s'écrit « AAAA-MM-JJ », jamais avec une date qui porte une heure.");
        $this->assertThrows(fn () => $paiement->forceFill(['recu_le' => '2026-10-25T00']), InvalidArgumentException::class);
        $this->assertThrows(fn () => $paiement->forceFill(['recu_le' => '2026-02-30']), InvalidArgumentException::class);
    }

    /**
     * Une date liée à une requête se compare en UTC, qu'elle passe par le
     * constructeur de requêtes, par DB::table() ou par une requête brute,
     * quel que soit son fuseau.
     */
    public function test_une_date_liee_a_une_requete_est_comparee_en_utc(): void
    {
        $demande = $this->demandeInscription(self::enUtc('2026-10-25 00:50:00'));
        $borneAParis = self::enUtc('2026-10-25 01:00:00')->setTimezone(Calendrier::deParis()->fuseau());
        DB::table('demandes_inscription')->insert(['email' => 'directe@exemple.fr', 'jeton_hache' => str_repeat('b', 64), 'donnees' => '[]', 'expire_le' => self::enUtc('2026-10-25 01:10:00')->setTimezone(new DateTimeZone('+05:00')), 'created_at' => now()]);

        $this->assertSame([$demande->id], DemandeInscription::query()->where('expire_le', '<', $borneAParis)->pluck('id')->all());
        $this->assertSame([$demande->id], DB::table('demandes_inscription')->where('expire_le', '<', $borneAParis)->pluck('id')->all());
        $this->assertSame([$demande->id], array_map(static fn (object $ligne): int => (int) $ligne->id, DB::select('select id from demandes_inscription where expire_le < ?', [$borneAParis])));
        $this->assertSame(Instant::enBase(self::enUtc('2026-10-25 01:10:00')), DB::table('demandes_inscription')->where('email', 'directe@exemple.fr')->value('expire_le'));
    }

    /**
     * Un agrégat sur une colonne d'instant se relit en UTC, par withCasts()
     * ou Instant::depuisLaBase().
     */
    public function test_un_agregat_sur_un_instant_se_relit_en_utc(): void
    {
        $this->demandeInscription(self::enUtc('2026-10-25 00:50:00'));
        $this->demandeInscription(self::enUtc('2026-10-25 01:10:00'));

        $this->assertSame(self::enUtc('2026-10-25 01:10:00')->getTimestamp(), Instant::depuisLaBase((string) DemandeInscription::query()->max('expire_le'))->getTimestamp());
        $this->assertSame(self::enUtc('2026-10-25 00:50:00')->getTimestamp(), DemandeInscription::query()->selectRaw('min(expire_le) as premiere')->withCasts(['premiere' => Instant::class])->first()->premiere->getTimestamp());
    }

    public function test_la_session_postgresql_est_en_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('La session de base n\'a de fuseau que sous PostgreSQL.');
        }

        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }

    /**
     * L'application refuse de démarrer à un autre fuseau que l'UTC, que ce
     * soit sa configuration ou le fuseau par défaut de PHP.
     */
    public function test_l_application_refuse_un_fuseau_autre_que_utc(): void
    {
        Calendrier::exigerUnFuseauUtc();
        config(['app.timezone' => Calendrier::deParis()->fuseau()]);

        $this->assertThrows(fn () => Calendrier::exigerUnFuseauUtc(), LogicException::class);

        config(['app.timezone' => 'UTC']);

        try {
            date_default_timezone_set(Calendrier::deParis()->fuseau());
            $this->assertThrows(fn () => Calendrier::exigerUnFuseauUtc(), LogicException::class);
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    /**
     * Pour chaque table d'un modèle de l'application, chaque colonne
     * d'instant a le cast Instant, sauf les horodatages que gère Eloquent :
     * created_at et updated_at ne sont jamais castés, et deleted_at n'a que
     * le cast « datetime » qu'ajoute lui-même le trait SoftDeletes, lu et
     * écrit en UTC comme toute l'application ; chaque colonne de jour a le
     * cast JourCivil ; et aucune colonne d'heure d'aucune table, celles de
     * Laravel et les tables de liaison comprises, n'a de valeur par défaut.
     */
    public function test_les_colonnes_d_horodatage_ont_leur_cast_et_aucune_valeur_par_defaut(): void
    {
        $this->assertSame([], $this->ecartsDuSchema());
    }

    /**
     * La garde du schéma voit une colonne d'instant qui perd son cast : un
     * modèle VerificationSociete sans celui de expire_le est signalé.
     */
    public function test_la_garde_du_schema_signale_une_colonne_d_instant_sans_cast(): void
    {
        $sansCast = new class extends VerificationSociete
        {
            /**
             * @return array<string, mixed>
             */
            public function getCasts(): array
            {
                return array_diff_key(parent::getCasts(), ['expire_le' => true]);
            }
        };

        $this->assertSame(['verifications_societe.expire_le : cast aucun, attendu '.Instant::class], $this->ecartsDuSchema(['verifications_societe' => $sansCast]));
    }

    /**
     * Les écarts entre le schéma et les casts des modèles de l'application,
     * avec, pour une table, le modèle qui remplace le sien.
     *
     * @param  array<string, Model>  $remplacements
     * @return list<string>
     */
    private function ecartsDuSchema(array $remplacements = []): array
    {
        $modeles = array_replace($this->modelesDeLApplication(), $remplacements);
        $ecarts = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $modele = $modeles[$table] ?? null;

            foreach (Schema::getColumns($table) as $colonne) {
                $type = strtolower($colonne['type_name']);
                $estUnInstant = str_contains($type, 'timestamp') || $type === 'datetime';
                $estUnJour = $type === 'date';

                if (($estUnInstant || $estUnJour) && $colonne['default'] !== null) {
                    $ecarts[] = "{$table}.{$colonne['name']} : valeur par défaut {$colonne['default']}";
                }

                if ($modele === null || (! $estUnInstant && ! $estUnJour)) {
                    continue;
                }

                $attendu = match (true) {
                    $estUnJour => JourCivil::class,
                    $modele->usesTimestamps() && in_array($colonne['name'], [$modele->getCreatedAtColumn(), $modele->getUpdatedAtColumn()], true) => null,
                    in_array(SoftDeletes::class, class_uses_recursive($modele), true) && $colonne['name'] === $modele->getDeletedAtColumn() => 'datetime',
                    default => Instant::class,
                };
                $cast = $modele->getCasts()[$colonne['name']] ?? null;

                if ($cast !== $attendu) {
                    $ecarts[] = "{$table}.{$colonne['name']} : cast ".($cast ?? 'aucun').', attendu '.($attendu ?? 'aucun');
                }
            }
        }

        return $ecarts;
    }

    /**
     * Les colonnes et les fiches de Filament affichent un instant à l'heure
     * de Paris.
     */
    public function test_les_colonnes_et_les_fiches_affichent_les_instants_a_l_heure_de_paris(): void
    {
        $this->assertSame([Calendrier::deParis()->fuseau(), Calendrier::deParis()->fuseau()], [TextColumn::make('envoye_le')->getTimezone(), TextEntry::make('envoye_le')->getTimezone()]);
    }

    /**
     * Dans le panneau d'un magasin de la Martinique, les colonnes et les
     * fiches de Filament affichent un instant à l'heure de la Martinique,
     * sans mention ; dans l'espace de la société, à l'heure de Paris : le
     * réglage suit le magasin actif, relu à chaque rendu.
     */
    public function test_le_panneau_d_un_magasin_d_outre_mer_affiche_ses_instants_a_son_heure(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $magasin = $this->ouvrirUneSocieteSituee(Territoire::Martinique);
        $ticket = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => '8.5', 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $fuseaux = static fn (): array => [TextColumn::make('envoye_le')->getTimezone(), TextEntry::make('envoye_le')->getTimezone()];

        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));
        $this->assertSame(array_fill(0, 2, Calendrier::duTerritoire(Territoire::Martinique)->fuseau()), $fuseaux());
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('16/11/2026')
            ->assertDontSee('17/11/2026');

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $this->assertSame(array_fill(0, 2, Calendrier::deParis()->fuseau()), $fuseaux());
    }

    /**
     * Un jour n'a pas de fuseau : l'échéance d'une facture, la journée
     * d'une clôture et la date d'un règlement s'affichent tels quels dans
     * le panneau d'un magasin de la Martinique, en liste comme sur la
     * fiche. Relu comme un instant de minuit UTC puis converti à l'heure
     * de la Martinique, le 16 décembre s'afficherait 15/12/2026.
     */
    public function test_un_jour_s_affiche_tel_quel_dans_le_panneau_d_un_magasin_de_la_martinique(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee(Territoire::Martinique));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => '8.5', 'suivi_stock' => false]);
        $client = Client::factory()->professionnel()->create(['delai_paiement_jours' => 30]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
        $paiement = app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '10.00', 'recu_le' => '2026-11-16'], [['facture_id' => $facture->id, 'montant' => '10.00']]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-11-16');
        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));

        $this->assertSame(['2026-12-16', '2026-11-16', '2026-11-16'], [$facture->date_echeance, $paiement->recu_le, $cloture->date_cloture]);
        Livewire::test(ListFactures::class)->assertTableColumnFormattedStateSet('date_echeance', '16/12/2026', $facture);
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])->assertSee('16/12/2026')->assertDontSee('15/12/2026');
        Livewire::test(ListClotureCaisses::class)->assertTableColumnFormattedStateSet('date_cloture', '16/11/2026', $cloture);
        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])->assertSee('16/11/2026')->assertDontSee('15/11/2026');
        Livewire::test(ListPaiements::class)->assertTableColumnFormattedStateSet('recu_le', '16/11/2026', $paiement);
    }

    private function demandeInscription(\DateTimeInterface $expireLe): DemandeInscription
    {
        return tap((new DemandeInscription)->forceFill([
            'email' => fake()->unique()->safeEmail(),
            'jeton_hache' => DemandeInscription::empreinte(fake()->unique()->uuid()),
            'donnees' => [],
            'expire_le' => $expireLe,
            'created_at' => now(),
        ]))->save();
    }

    private function jetonMotDePasseOublie(): CarbonImmutable
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        Password::broker()->createToken($proprietaire->refresh());

        return Instant::depuisLaBase((string) DB::table('password_reset_tokens')->where('email', 'patron@boutique.test')->value('created_at'));
    }

    /**
     * Une instance de chaque modèle de app/, par sa table.
     *
     * @return array<string, Model>
     */
    private function modelesDeLApplication(): array
    {
        $modeles = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

            if (class_exists($classe) && is_subclass_of($classe, Model::class) && ! (new ReflectionClass($classe))->isAbstract()) {
                $modele = new $classe;
                $modeles[$modele->getTable()] ??= $modele;
            }
        }

        return $modeles;
    }
}
