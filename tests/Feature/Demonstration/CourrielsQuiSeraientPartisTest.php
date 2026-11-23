<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Demonstration\Courriels\TransportDeLaDemonstration;
use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Http\Controllers\Demonstration\CourrielsDeLaDemonstrationController;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\DemonstrationTestCase;

/**
 * « E-mails qui seraient partis » (lot DEMO-COPIE, C.9, N22) : aucun e-mail
 * ne part du site de démonstration ; le transport « demonstration » écrit
 * chacun dans la copie, dans la société d'où il part, et la page de la
 * copie ne montre à tout compte de la copie que ceux de la société de la
 * copie, jamais ceux d'une autre société de la même base ni d'une autre
 * copie. Le HTML se lit dans un cadre isolé, les pièces se téléchargent.
 */
class CourrielsQuiSeraientPartisTest extends DemonstrationTestCase
{
    public function test_aucun_transport_reseau_et_chaque_e_mail_s_ecrit_dans_la_societe_d_ou_il_part(): void
    {
        $this->assertInstanceOf(TransportDeLaDemonstration::class, Mail::mailer('demonstration')->getSymfonyTransport());
        $this->assertSame('demonstration', config('mail.default'));
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Commerce);

        $courriels = $this->dansLaCopie($copie, fn (): array => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->get(['societe_id', 'objet', 'destinataires'])->toArray());

        $this->assertCount(1, $courriels, 'L\'avis par e-mail du modèle est dans la copie.');
        $this->assertStringContainsString('Première connexion', $courriels[0]['objet']);
        $societe = $this->dansLaCopie($copie, fn (): int => (int) Societe::query()->where('siren', '000000109')->value('id'));
        $this->assertSame($societe, $courriels[0]['societe_id'], 'L\'avis d\'une notification se range dans la société du compte qui le reçoit.');
    }

    public function test_tout_compte_de_la_copie_voit_les_e_mails_de_sa_societe_dans_un_cadre_isole(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $this->envoyerDansLaCopie($copie, '000000109', 'Votre facture FAC-HAL-2026-000001', '<html><head><title>Facture</title></head><body><p>Bonjour</p><a href="https://localhost/devis/reponse">Répondre</a></body></html>', 'FAC-HAL-2026-000001.pdf');

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/demonstration/courriels')->assertRedirect('/choisir');

        foreach (['Inès Garcia', 'Camille Laurent'] as $nom) {
            $this->changerPour($copie, $temoin, $nom);
            $liste = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/demonstration/courriels');
            $liste->assertOk()->assertSee('Votre facture FAC-HAL-2026-000001')->assertSee('client@exemple.test')->assertSee('data-bandeau-de-la-demonstration', false);
        }

        $id = $this->dansLaCopie($copie, fn (): int => (int) CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'Votre facture FAC-HAL-2026-000001')->value('id'));
        $fiche = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get("/demonstration/courriels/{$id}");
        $fiche->assertOk();
        $html = $fiche->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('/<iframe[^>]*sandbox="([^"]*)"[^>]*srcdoc="([^"]*)"/', $html, $cadre), 'Le message se lit dans un cadre srcdoc isolé.');
        $this->assertSame(CourrielsDeLaDemonstrationController::CADRE_ISOLE, $cadre[1]);
        $this->assertSame('allow-popups allow-popups-to-escape-sandbox', $cadre[1]);
        $this->assertStringNotContainsString('allow-scripts', $cadre[1]);
        $this->assertStringNotContainsString('allow-same-origin', $cadre[1]);
        $this->assertStringContainsString('&lt;head&gt;&lt;base target=&quot;_blank&quot;&gt;', $cadre[2], 'Un lien du message s\'ouvre dans un nouvel onglet.');

        $piece = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get("/demonstration/courriels/{$id}/pieces/0");
        $piece->assertOk();
        $this->assertSame('attachment; filename="FAC-HAL-2026-000001.pdf"', $piece->headers->get('Content-Disposition'), 'Une pièce se télécharge, jamais affichée.');
        $this->assertSame('application/octet-stream', $piece->headers->get('Content-Type'));
        $this->assertSame('%PDF-1.7 pièce de la démonstration', $piece->getContent());
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get("/demonstration/courriels/{$id}/pieces/7")->assertNotFound();
    }

    /**
     * Un e-mail fabrique ses liens avec l'adresse du site au moment de
     * l'envoi (APP_URL pour un travail de la file, et pour le semis qui a
     * construit la base modèle) : rangée telle quelle, elle ne mènerait nulle
     * part sur le port d'un poste ou le domaine d'une copie (demande de
     * l'éditeur du 20/11/2026). L'adresse du site s'écrit donc comme un
     * repère, remplacé à l'affichage de la fiche et du texte par l'adresse
     * de la requête en cours ; un lien vers un autre site, ou vers une
     * autre adresse qui commence comme celle du site, reste entier.
     */
    public function test_les_liens_d_un_e_mail_capture_suivent_le_site_qui_sert_la_copie(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $racine = rtrim(url('/'), '/');
        $jeton = str_repeat('a', 64);
        $this->envoyerDansLaCopie($copie, '000000109', 'Votre devis DEV-HAL-2026-000001', '<html><head></head><body><a href="'.$racine.'/devis/'.$jeton.'">Répondre</a> <a href="'.$racine.'0/autre">Autre</a> <a href="https://www.service-public.fr/">Service public</a></body></html>');

        $range = $this->dansLaCopie($copie, fn (): array => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'Votre devis DEV-HAL-2026-000001')->get(['id', 'html'])->toArray());
        $html = (string) $range[0]['html'];
        $this->assertStringContainsString('href="{{SITE}}/devis/'.$jeton.'"', $html, 'L\'adresse du site se range comme un repère.');
        $this->assertStringNotContainsString($racine.'/devis', $html);
        $this->assertStringContainsString('href="'.$racine.'0/autre"', $html, 'Le début d\'une autre adresse n\'est pas l\'adresse du site.');
        $this->assertStringContainsString('href="https://www.service-public.fr/"', $html);

        $temoin = $this->entrerParLeLien($secret);
        $this->changerPour($copie, $temoin, 'Camille Laurent');
        URL::forceRootUrl('http://copie-de-l-editeur.exemple.test:8001');
        $fiche = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/demonstration/courriels/'.$range[0]['id']);

        $fiche->assertOk();
        $this->assertSame(1, preg_match('/srcdoc="([^"]*)"/', (string) $fiche->getContent(), $cadre));
        $affiche = html_entity_decode($cadre[1]);
        $this->assertStringContainsString('href="http://copie-de-l-editeur.exemple.test:8001/devis/'.$jeton.'"', $affiche, 'Le lien ouvre la page sur le site qui sert la copie.');
        $this->assertStringNotContainsString('{{SITE}}', $affiche);
        $this->assertStringNotContainsString($racine.'/devis', $affiche);
        $this->assertStringContainsString('href="'.$racine.'0/autre"', $affiche);
    }

    /**
     * Le lien du titre du gabarit des e-mails est lu dans APP_URL, les autres
     * liens sont construits depuis l'adresse de la requête : quand elles
     * diffèrent (le semis d'un modèle, une copie servie sur son domaine), les
     * deux se rangent comme un repère, et le début d'une autre adresse reste
     * entier (relecture du lot écrans : le titre d'un « Devis accepté en
     * ligne » d'une base modèle gardait l'adresse du site de construction).
     */
    public function test_le_lien_du_titre_qui_porte_l_adresse_d_app_url_se_range_aussi_comme_un_repere(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Commerce);
        $appUrl = rtrim(ValeurSimple::enTexte(config('app.url')), '/');
        URL::forceRootUrl('http://requete.exemple.test:8003');
        $this->assertNotSame($appUrl, rtrim(url('/'), '/'), 'L\'adresse de la requête diffère d\'APP_URL.');
        $this->envoyerDansLaCopie($copie, '000000109', 'Devis accepté en ligne', '<html><body><a href="'.$appUrl.'" style="x">Titre</a> <a href="http://requete.exemple.test:8003/app/devis/1">Voir</a> <a href="'.$appUrl.'0/autre">Autre</a></body></html>');

        $html = (string) $this->dansLaCopie($copie, fn (): mixed => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'Devis accepté en ligne')->value('html'));

        $this->assertStringContainsString('<a href="{{SITE}}" style="x">', $html, 'Le lien du titre, lu dans APP_URL, se range comme un repère.');
        $this->assertStringContainsString('<a href="{{SITE}}/app/devis/1">', $html, 'Un lien construit depuis la requête aussi.');
        $this->assertStringContainsString('href="'.$appUrl.'0/autre"', $html, 'Le début d\'une autre adresse n\'est pas l\'adresse du site.');
        $this->assertStringNotContainsString('requete.exemple.test', $html);
    }

    public function test_jamais_les_e_mails_d_une_autre_societe_de_la_meme_base_ni_d_une_autre_copie(): void
    {
        ['copie' => $cabinet, 'secret' => $secretDuCabinet] = $this->copieDeTest(TypeDActivite::Cabinet);
        ['copie' => $commerce, 'secret' => $secretDuCommerce] = $this->copieDeTest(TypeDActivite::Commerce);
        $this->envoyerDansLaCopie($cabinet, '000000125', 'Message du client du cabinet', '<p>Client</p>');
        $this->envoyerDansLaCopie($commerce, '000000109', 'Message des Halles', '<p>Halles</p>');
        $temoinDuCabinet = $this->entrerParLeLien($secretDuCabinet);
        $temoinDuCommerce = $this->entrerParLeLien($secretDuCommerce);

        $this->changerPour($cabinet, $temoinDuCabinet, 'Paul Lefèvre');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDuCabinet)->get('/demonstration/courriels')
            ->assertOk()
            ->assertDontSee('Message du client du cabinet')
            ->assertDontSee('Message des Halles');
        $idDuClient = $this->dansLaCopie($cabinet, fn (): int => (int) CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'Message du client du cabinet')->value('id'));
        $this->assertGreaterThan(0, $idDuClient);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDuCabinet)->get("/demonstration/courriels/{$idDuClient}")->assertNotFound();

        $this->changerPour($commerce, $temoinDuCommerce, 'Inès Garcia');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoinDuCommerce)->get('/demonstration/courriels')
            ->assertOk()
            ->assertSee('Message des Halles')
            ->assertDontSee('Message du client du cabinet');
    }

    public function test_une_piece_de_plus_de_dix_mo_est_gardee_sans_son_contenu(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Services);
        $this->envoyerDansLaCopie($copie, '000000117', 'Gros envoi', '<p>Gros</p>', 'gros.pdf', str_repeat('A', TransportDeLaDemonstration::TAILLE_MAXIMALE_D_UNE_PIECE + 1));

        $pieces = $this->dansLaCopie($copie, fn (): array => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('objet', 'Gros envoi')->sole()->pieces_jointes);

        $this->assertSame([['nom' => 'gros.pdf', 'type' => 'application/pdf', 'taille' => TransportDeLaDemonstration::TAILLE_MAXIMALE_D_UNE_PIECE + 1, 'contenu' => null]], $pieces);
    }

    public function test_le_lien_du_mot_de_passe_d_un_expert_comptable_declare_dans_la_copie_parait_sur_la_page(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;

        $societe = $this->dansLaCopie($copie, function () use ($motDePasse): int {
            $societe = Societe::query()->where('siren', '000000109')->sole();
            $proprietaire = $societe->proprietaire()->firstOrFail();
            Auth::guard('web')->setUser($proprietaire);
            app(DeclarerSonExpertComptableAction::class)->handle($proprietaire, $societe, [
                'siren' => FauxRepertoireSirene::SIREN_CABINET,
                'name' => 'Nadia Benali',
                'email' => 'nadia.benali@cabinet.exemple.test',
                'email_confirmation' => 'nadia.benali@cabinet.exemple.test',
                'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($societe->type_activite)),
                'remplacer' => false,
            ], $motDePasse);
            app(TenantContext::class)->clear();

            return (int) $societe->getKey();
        });

        $courriel = $this->dansLaCopie($copie, fn (): ?CourrielDeLaDemonstration => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('destinataires', 'like', '%nadia.benali@cabinet.exemple.test%')->first());
        $this->assertNotNull($courriel, 'L\'e-mail du lien du mot de passe de l\'expert-comptable déclaré ne part pas : il s\'écrit dans « E-mails qui seraient partis ».');
        $this->assertSame($societe, (int) $courriel->societe_id);
        $temoin = $this->entrerParLeLien($secret);
        $this->changerPour($copie, $temoin, 'Inès Garcia');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/demonstration/courriels')->assertOk()->assertSee('nadia.benali@cabinet.exemple.test');
    }

    public function test_l_invitation_d_un_cabinet_envoyee_hors_de_tout_contexte_et_sans_compte_connecte_s_ecrit_chez_le_cabinet(): void
    {
        ['copie' => $copie] = $this->copieDeTest(TypeDActivite::Cabinet);
        $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;

        $cabinet = $this->dansLaCopie($copie, function () use ($motDePasse): int {
            $cabinet = Societe::query()->where('type_activite', TypeDActivite::Cabinet->value)->sole();
            $this->assertFalse(Auth::check());
            $this->assertFalse(app(TenantContext::class)->aUneSociete());
            app(InviterUneEntrepriseAction::class)->handle($cabinet->proprietaire()->firstOrFail(), $cabinet, '000000141', 'dirigeant@entreprise-invitee.exemple.test', $motDePasse);
            app(TenantContext::class)->clear();

            return (int) $cabinet->getKey();
        });

        $courriel = $this->dansLaCopie($copie, fn (): ?CourrielDeLaDemonstration => CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('destinataires', 'like', '%dirigeant@entreprise-invitee.exemple.test%')->first());
        $this->assertNotNull($courriel, 'L\'invitation, envoyée après sa transaction, hors de tout contexte et sans compte connecté (comme le semis du modèle « Cabinet »), s\'écrit dans « E-mails qui seraient partis ».');
        $this->assertSame($cabinet, (int) $courriel->societe_id, 'Elle s\'écrit dans la société du cabinet qui invite.');
        $this->assertStringContainsString('vous invite sur', $courriel->objet);
    }

    public function test_les_limites_d_envoi_du_reel_decident_dans_chaque_copie(): void
    {
        ['copie' => $premiere] = $this->copieDeTest(TypeDActivite::Services);
        ['copie' => $seconde] = $this->copieDeTest(TypeDActivite::Services);
        $limite = function (Copie $copie, int $envois): ?string {
            return $this->dansLaCopie($copie, function () use ($envois): ?string {
                $compte = User::query()->where('name', 'Claire Dubois')->sole();
                $debit = app(DebitDesEmails::class);

                for ($envoi = 0; $envoi < $envois; $envoi++) {
                    $debit->compter($compte);
                }

                try {
                    $debit->verifier($compte);

                    return null;
                } catch (LogicException $refus) {
                    return $refus->getMessage();
                }
            });
        };

        $this->assertStringStartsWith('Trop d\'e-mails envoyés en une heure', (string) $limite($premiere, DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE), 'Le 61e e-mail de l\'heure est refusé dans la copie, comme au réel.');
        $this->assertNull($limite($seconde, DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE - 1), 'Le même compte d\'une autre copie a ses propres limites.');
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

    /**
     * Un e-mail envoyé dans la copie, depuis le contexte de la société de
     * ce SIREN, par le vrai transport de la démonstration.
     */
    private function envoyerDansLaCopie(Copie $copie, string $siren, string $objet, string $html, ?string $piece = null, string $contenu = '%PDF-1.7 pièce de la démonstration'): void
    {
        $this->dansLaCopie($copie, function () use ($siren, $objet, $html, $piece, $contenu): void {
            $societe = Societe::query()->where('siren', $siren)->sole();
            app(TenantContext::class)->runAsSociete($societe, function () use ($objet, $html, $piece, $contenu): void {
                Mail::to('client@exemple.test')->send(new class($objet, $html, $piece, $contenu) extends Mailable
                {
                    public function __construct(private readonly string $objet, private readonly string $corps, private readonly ?string $piece, private readonly string $contenu) {}

                    public function build(): self
                    {
                        $courriel = $this->subject($this->objet)->html($this->corps);

                        return $this->piece === null ? $courriel : $courriel->attachData($this->contenu, $this->piece, ['mime' => 'application/pdf']);
                    }
                });
            });
            app(TenantContext::class)->clear();
        });
    }

    private function changerPour(Copie $copie, string $temoin, string $nom): void
    {
        $compte = $this->dansLaCopie($copie, fn (): int => (int) User::query()->where('name', $nom)->value('id'));
        $this->assertGreaterThan(0, $compte, "{$nom} est un compte de la copie.");
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $compte])->assertRedirect();
    }
}
