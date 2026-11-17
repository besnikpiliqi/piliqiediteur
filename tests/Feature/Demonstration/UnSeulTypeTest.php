<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\DemonstrationTestCase;

/**
 * Chaque société ne voit que la démonstration de son type (lot
 * DEMO-COPIE, choix 30, N27) : dans la copie de chaque type, « Choisissez
 * qui vous êtes », le bandeau « Vous êtes : » et son menu, la page « E-mails
 * qui seraient partis », les pages d'attente et la page du lien d'une
 * invitation d'un cabinet ne nomment jamais un autre type d'activité, ni
 * la société d'une copie d'un autre type, ni une autre démonstration. La carte et l'e-mail du lien, sur le site réel : voir
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
     * Dans la copie d'un cabinet, le lien d'une invitation capturée dans
     * « E-mails qui seraient partis » mène à une page qui ne nomme aucun
     * type d'activité ; ni l'acceptation ni le refus ne s'y font : aucune
     * société ne se crée, personne ne se connecte, l'invitation reste
     * ouverte (choix 27 et 30).
     */
    public function test_le_lien_d_une_invitation_du_cabinet_ne_nomme_aucun_type_et_n_ouvre_aucune_societe(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Cabinet);
        $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;
        $html = app(OuvertureDUneCopie::class)->pendant($copie, function () use ($motDePasse): string {
            $cabinet = Societe::query()->where('type_activite', TypeDActivite::Cabinet->value)->sole();
            app(InviterUneEntrepriseAction::class)->handle($cabinet->proprietaire()->firstOrFail(), $cabinet, '000000141', 'dirigeant@entreprise-invitee.exemple.test', $motDePasse);
            app(TenantContext::class)->clear();

            return (string) CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('destinataires', 'like', '%dirigeant@entreprise-invitee.exemple.test%')->sole()->html;
        });
        $this->assertSame(1, preg_match('#/invitation-cabinet/([A-Za-z0-9]{64})#', $html, $lien), 'L\'e-mail capturé porte le lien de l\'invitation.');
        $adresse = '/invitation-cabinet/'.$lien[1];
        $societesAvant = app(OuvertureDUneCopie::class)->pendant($copie, fn (): int => Societe::query()->count());
        $temoin = $this->entrerParLeLien($secret);

        $page = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get($adresse);
        $page->assertOk()->assertSee('L\'entreprise invitée recevrait ce lien', false)->assertDontSee('<form', false);

        foreach (TypeDActivite::cases() as $type) {
            $page->assertDontSee($type->getLabel());
        }

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post($adresse, [
            'password' => 'Un-mot-de-passe-1', 'password_confirmation' => 'Un-mot-de-passe-1', 'type_activite' => TypeDActivite::Commerce->value, 'choix_definitif' => '1',
        ])->assertForbidden()->assertDontSee(TypeDActivite::Commerce->getLabel());
        $this->assertGuest();
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post($adresse.'/refus')->assertForbidden();

        [$societesApres, $etat] = app(OuvertureDUneCopie::class)->pendant($copie, fn (): array => [
            Societe::query()->count(),
            InvitationCabinet::query()->withoutGlobalScopes()->where('siren', '000000141')->sole()->etat,
        ]);
        $this->assertSame($societesAvant, $societesApres, 'Aucune société ne se crée dans la copie du cabinet.');
        $this->assertSame(EtatInvitation::Ouverte, $etat, 'L\'invitation reste ouverte.');
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
