<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Tests\DemonstrationTestCase;

/**
 * Chaque société ne voit que la démonstration de son type (lot
 * DEMO-COPIE, choix 30, N27) : dans la copie de chaque type, « Choisissez
 * qui vous êtes », le bandeau « Vous êtes : » et son menu, la page « E-mails
 * qui seraient partis » et les pages d'attente ne nomment jamais un autre
 * type d'activité, ni la société d'une copie d'un autre type, ni une autre
 * démonstration. La carte et l'e-mail du lien, sur le site réel : voir
 * QuiDemandeTest et EnvoiDuLienTest.
 */
class UnSeulTypeTest extends DemonstrationTestCase
{
    public function test_les_pages_de_chaque_copie_ne_parlent_que_de_son_type(): void
    {
        $copies = [];

        foreach (TypeDActivite::cases() as $type) {
            $copies[$type->value] = $this->copieDeTest($type);
        }

        $societes = [];

        foreach ($copies as $type => ['copie' => $copie]) {
            $societes[$type] = app(OuvertureDUneCopie::class)->pendant($copie, fn (): array => Societe::query()->withoutGlobalScopes()->pluck('raison_sociale')->all());
        }

        foreach ($copies as $type => ['copie' => $copie, 'secret' => $secret]) {
            $interdits = [];

            foreach (TypeDActivite::cases() as $autre) {
                if ($autre->value !== $type) {
                    $interdits[] = $autre->getLabel();
                    array_push($interdits, ...array_diff($societes[$autre->value], $societes[$type]));
                }
            }

            $this->assertNotEmpty($interdits);

            foreach ($this->pagesDeLaCopie($copie, $secret) as $page => $texte) {
                foreach ($interdits as $interdit) {
                    $this->assertStringNotContainsString($interdit, $texte, "La copie « {$type} » nomme « {$interdit} » sur {$page}.");
                }

                $this->assertStringNotContainsString('autre démonstration', $texte, "La copie « {$type} » parle d'une autre démonstration sur {$page}.");
            }
        }
    }

    /**
     * Le texte de chaque page de la démonstration que voit le visiteur de
     * cette copie.
     *
     * @return array<string, string>
     */
    private function pagesDeLaCopie(Copie $copie, string $secret): array
    {
        $temoin = $this->entrerParLeLien($secret);
        $proprietaire = app(OuvertureDUneCopie::class)->pendant($copie, fn (): User => User::query()->where('role_societe', RoleSociete::Proprietaire->value)->whereHas('societe', fn ($societe) => $societe->whereNotNull('id'))->orderBy('id')->firstOrFail());
        $pages = ['« Choisissez qui vous êtes »' => (string) $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertOk()->getContent()];

        $choix = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $proprietaire->getKey()]);
        $accueil = (string) $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->followingRedirects()->get((string) $choix->headers->get('Location'))->assertOk()->getContent();
        $debut = strpos($accueil, 'data-bandeau-de-la-demonstration');
        $this->assertNotFalse($debut, 'Le bandeau est en haut de la page d\'accueil du compte.');
        $pages['le bandeau et son menu'] = substr($accueil, $debut, (int) strpos($accueil, 'Quitter', $debut) - $debut);
        $pages['« E-mails qui seraient partis »'] = (string) $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/demonstration/courriels')->assertOk()->getContent();

        foreach (['se_prepare', 'en_attente_d_une_place', 'mise_a_jour', 'a_l_heure', 'terminee', 'indisponible'] as $cas) {
            $pages["la page d'attente « {$cas} »"] = view('demonstration.attente', ['cas' => $cas])->render();
        }

        return $pages;
    }
}
