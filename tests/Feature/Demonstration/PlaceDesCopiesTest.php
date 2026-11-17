<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Etat\EtatDuSite;
use App\Domain\Demonstration\Exceptions\CopieEnLectureSeule;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Modele;
use App\Domain\Demonstration\Taches\PlaceDesCopies;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\DemonstrationTestCase;

/**
 * La place d'une copie (lot DEMO-COPIE, C.16, N17) : chaque heure,
 * demonstration:mesurer-les-copies mesure sa base et tout son dossier ;
 * au-delà de DEMONSTRATION_FACTEUR_DE_PLACE fois la taille de son modèle,
 * la copie passe en lecture seule, juste sous la limite non. En lecture
 * seule, la consultation reste (pages, changement de compte du bandeau),
 * chaque geste qui écrit est refusé en français, jamais en erreur
 * technique, et le bandeau le dit ; une pièce déposée qui ferait dépasser
 * la place est refusée avec le même message. Sous la place libre minimale
 * du disque des copies, aucune copie neuve ne se crée.
 */
class PlaceDesCopiesTest extends DemonstrationTestCase
{
    public function test_au_dela_de_sa_place_la_copie_passe_en_lecture_seule_juste_sous_la_limite_non(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $modele = $this->modeleAlaTailleDeLaCopie($copie);

        try {
            $this->artisan('demonstration:mesurer-les-copies')->assertSuccessful();
            $mesuree = Copie::on('demo_admin')->whereKey($copie->id)->sole();
            $this->assertSame(EtatDUneCopie::Prete, $mesuree->etat, 'Une copie à sa limite, pas au-delà, se sert toujours.');
            $this->assertLessThanOrEqual(PlaceDesCopies::limiteDe(Modele::on('demo_admin')->whereKey($modele->id)->sole()), $mesuree->taille_mesuree);

            File::ensureDirectoryExists(DossiersDeLaDemonstration::dUneCopie($copie->reference).'/telechargements');
            File::put(DossiersDeLaDemonstration::dUneCopie($copie->reference).'/telechargements/documents.zip', str_repeat('Z', 2048));
            $this->artisan('demonstration:mesurer-les-copies')->assertSuccessful();

            $this->assertSame(EtatDUneCopie::EnLectureSeule, Copie::on('demo_admin')->whereKey($copie->id)->sole()->etat, 'Un ZIP préparé compte dans sa place : la copie passe en lecture seule.');
        } finally {
            Modele::on('demo_admin')->whereKey($modele->id)->update(['taille' => $modele->getOriginal('taille')]);
        }
    }

    public function test_en_lecture_seule_la_consultation_reste_et_chaque_geste_qui_ecrit_est_refuse_en_francais(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $temoin = $this->entrerParLeLien($secret);
        $proprietaire = $this->dansLaCopie($copie, fn (): User => User::query()->where('role_societe', RoleSociete::Proprietaire->value)->sole());
        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnLectureSeule->value]);
        Route::middleware('web')->post('/essai-d-ecriture', function (): string {
            User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Nom changé'])->save();

            return 'écrit';
        });

        $choix = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $proprietaire->getKey()]);
        $choix->assertRedirect();
        $this->assertAuthenticatedAs($proprietaire);

        $accueil = (string) $choix->headers->get('Location');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->followingRedirects()->get($accueil)
            ->assertOk()
            ->assertSee(PlaceDesCopies::MESSAGE, false);

        $refus = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->from($accueil)->post('/essai-d-ecriture');
        $refus->assertRedirect($accueil);
        $session = $refus->baseResponse instanceof RedirectResponse ? $refus->baseResponse->getSession() : null;
        $this->assertSame(PlaceDesCopies::MESSAGE, data_get($session?->get('filament.notifications'), '0.title'), 'Le refus se dit en français, sur la page d\'où le geste part.');
        $this->assertNotSame('Nom changé', $this->dansLaCopie($copie, fn (): string => User::query()->orderBy('id')->firstOrFail()->name), 'Rien n\'est écrit.');
    }

    public function test_la_garde_ne_refuse_que_les_gestes_d_une_copie_en_lecture_seule(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        $this->dansLaCopie($copie, function (): void {
            $this->app->instance('request', Request::create('/geste', 'POST'));
            User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Copie servie'])->save();
            $this->assertSame('Copie servie', User::query()->orderBy('id')->firstOrFail()->name, 'Une copie servie écrit.');
        });

        Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnLectureSeule->value]);
        $copie = Copie::on('demo_admin')->whereKey($copie->id)->sole();

        $this->dansLaCopie($copie, function (): void {
            $this->app->instance('request', Request::create('/page', 'GET'));
            User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Consultation'])->save();
            $this->assertSame('Consultation', User::query()->orderBy('id')->firstOrFail()->name, 'Une page qui s\'affiche garde les traces de sa consultation.');

            $this->app->instance('request', Request::create('/geste', 'POST'));
            PlaceDesCopies::pendantUnGestePermis(fn () => User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Geste permis'])->save());
            $this->assertSame('Geste permis', User::query()->orderBy('id')->firstOrFail()->name, 'Le changement de compte du bandeau reste permis.');

            try {
                User::query()->orderBy('id')->firstOrFail()->forceFill(['name' => 'Refusé'])->save();
                $this->fail('Un geste qui écrit aurait dû être refusé.');
            } catch (CopieEnLectureSeule $refus) {
                $this->assertSame(PlaceDesCopies::MESSAGE, $refus->getMessage());
            }
        });
    }

    public function test_une_piece_qui_ferait_depasser_la_place_est_refusee_avec_le_meme_message(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $modele = $this->modeleAlaTailleDeLaCopie($copie);
        $piece = new PieceDeposee('facture.pdf', "%PDF-1.4\n%%EOF\n");

        try {
            $this->dansLaCopie($copie, function () use ($piece): void {
                try {
                    app(ControleDesFichiers::class)->controler($piece);
                    $this->fail('La pièce aurait dû être refusée.');
                } catch (FichierRefuseException $refus) {
                    $this->assertSame(RaisonDuRefus::PlaceDeLaDemonstration, $refus->raison);
                    $this->assertSame('Le fichier « facture.pdf » est refusé. '.PlaceDesCopies::MESSAGE, $refus->getMessage());
                }
            });

            $this->assertFalse(PlaceDesCopies::depasseraitLaPlace(1024 * 1024), 'Hors d\'une copie, la place ne compte pas.');
        } finally {
            Modele::on('demo_admin')->whereKey($modele->id)->update(['taille' => $modele->getOriginal('taille')]);
        }
    }

    public function test_sous_la_place_libre_minimale_du_disque_aucune_copie_neuve_ne_se_cree_et_l_editeur_est_alerte(): void
    {
        $this->modeleDeTest(TypeDActivite::Services);
        config(['demonstration.place_libre_minimale' => 101]);

        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);

        $this->assertSame(EtatDUneCopie::EnAttenteDUnePlace, $copie->etat, 'Le disque des copies sous sa place libre minimale : la demande attend une place, sans base créée.');
        $this->assertNull($copie->nom_de_la_base);
        $this->assertNotEmpty(array_filter(app(EtatDuSite::class)->construire()['problemes'], static fn (string $probleme): bool => str_contains($probleme, 'plus aucune copie neuve ne se crée')), 'L\'état publié alerte l\'éditeur.');

        config(['demonstration.place_libre_minimale' => 0]);
        $this->assertSame([], array_filter(app(EtatDuSite::class)->construire()['problemes'], static fn (string $probleme): bool => str_contains($probleme, 'plus aucune copie neuve ne se crée')));
    }

    /**
     * Le modèle de la copie, mesuré comme la copie maintenant : la copie est
     * juste à sa limite (DEMONSTRATION_FACTEUR_DE_PLACE à 1), le moindre
     * octet de plus la fait dépasser. La taille d'origine se remet ensuite.
     */
    private function modeleAlaTailleDeLaCopie(Copie $copie): Modele
    {
        config(['demonstration.facteur_de_place' => 1]);
        $modele = Modele::on('demo_admin')->whereKey($copie->modele_id)->sole();
        $copie = PlaceDesCopies::tailleDeLaBase((string) $copie->nom_de_la_base) + PlaceDesCopies::tailleDuDossier(DossiersDeLaDemonstration::dUneCopie($copie->reference));
        $taille = $copie - PlaceDesCopies::tailleDuDossier(DossiersDeLaDemonstration::duModele($modele->nom_de_la_base));
        Modele::on('demo_admin')->whereKey($modele->id)->update(['taille' => $taille]);

        return $modele;
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $geste
     * @return T
     */
    private function dansLaCopie(Copie $copie, \Closure $geste): mixed
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, $geste);
    }
}
