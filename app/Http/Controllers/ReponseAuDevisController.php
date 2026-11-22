<?php

namespace App\Http\Controllers;

use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Exceptions\LienDuDevisInvalideException;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Services\DepannageADomicile;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\DroitDeRetractation;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Facturation\Services\TableauDesLignes;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/**
 * La page du lien secret par lequel le client répond à un devis envoyé
 * par e-mail, sans compte (voir LienDuDevis) :
 * - ouvrir le lien (GET) n'écrit rien : la page montre ce seul devis, tel
 *   qu'il a été envoyé, avec tout ce que son PDF imprime (voir
 *   devis/document), sans PDF (demande de l'éditeur du 14/11/2026), rien
 *   d'autre du magasin, de la société ni d'un autre client, et les deux
 *   réponses possibles ; pour
 *   un particulier, son droit de rétractation de quatorze jours, avec le
 *   formulaire type, et la case qui demande que la prestation commence
 *   avant la fin du délai (T86, voir DroitDeRetractation) ;
 * - accepter (POST) demande le nom tapé et « Bon pour accord » coché,
 *   refuser (POST) un motif facultatif (voir RepondreAuDevisEnLigneAction) ;
 *   la page dit ensuite la réponse enregistrée, et le lien ne vaut plus
 *   pour répondre ;
 * - après une acceptation, le lien montre encore sa confirmation (« Devis
 *   accepté le … par … ») et le PDF du devis signé (GET), en pièce jointe,
 *   tant que le devis garde cette acceptation (voir
 *   LienDuDevis::confirmeLAcceptation()) ; après un refus, rien de plus.
 * Un jeton mal formé (une adresse tronquée ou altérée, lue sans aucune
 * recherche, voir LienDuDevis::parSonJeton()) ou inconnu, un lien retiré,
 * remplacé ou déjà servi, un devis expiré, modifié ou déjà répondu ne
 * donnent que « Ce lien n'est plus valable. » (410), sans rien dire de
 * plus. Chaque page est marquée noindex (balise et en-tête X-Robots-Tag),
 * ne transmet pas son adresse (Referrer-Policy: no-referrer) et ne mène
 * nulle part ailleurs dans PILIQI ; les essais sont limités par adresse
 * IP, la page de l'attente étant la même page neutre (voir
 * tropDEssais()).
 */
class ReponseAuDevisController
{
    public const TROP_D_ESSAIS = 'Trop d\'essais depuis cette adresse : réessayez dans une minute.';

    public function afficher(string $jeton): ReponseHttp
    {
        return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => match (true) {
            $lien->estValable($devis) => $this->page($jeton, $devis, [], null, null),
            $lien->confirmeLAcceptation($devis) => $this->confirmation($jeton, $devis),
            default => self::invalide(),
        });
    }

    /**
     * Le PDF du devis signé, seulement par le lien qui l'a accepté (voir
     * LienDuDevis::confirmeLAcceptation()) : avant sa réponse, le client lit
     * le devis sur la page où il signe, sans PDF (demande de l'éditeur du
     * 14/11/2026).
     */
    public function pdf(string $jeton, DevisPdfGenerator $generateur): ReponseHttp
    {
        return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $lien->confirmeLAcceptation($devis)
            ? self::protegee(response()->streamDownload(
                fn () => print ($generateur->generer($devis)->output()),
                $generateur->nomFichier($devis),
                EnTetesDesFichiers::fichierProduit('application/pdf'),
            ))
            : self::invalide());
    }

    public function accepter(Request $requete, string $jeton, RepondreAuDevisEnLigneAction $repondre): ReponseHttp
    {
        $signataire = ValeurSimple::enTexte($requete->input('signataire'));

        $debutAnticipe = $requete->boolean('debut_avant_la_fin_du_delai');

        return $this->repondre($jeton, fn (): ReponseDuDevis => $repondre->accepter($jeton, $signataire, $requete->boolean('bon_pour_accord'), $debutAnticipe), $signataire, null, $debutAnticipe);
    }

    public function refuser(Request $requete, string $jeton, RepondreAuDevisEnLigneAction $repondre): ReponseHttp
    {
        $motif = ValeurSimple::enTexte($requete->input('motif'));

        return $this->repondre($jeton, fn (): ReponseDuDevis => $repondre->refuser($jeton, $motif), null, $motif, false);
    }

    /**
     * La réponse enregistrée, la page qui la dit ; une saisie refusée
     * revient au formulaire avec ce qui était tapé ou coché, la demande de
     * début anticipé comprise. Un lien qui ne vaut plus pour répondre
     * montre encore la confirmation de l'acceptation qu'il a enregistrée
     * (voir LienDuDevis::confirmeLAcceptation()) : le second envoi d'un
     * double clic sur « Accepter le devis », ou d'un formulaire renvoyé par
     * le navigateur, ne fait pas croire au client que son acceptation a
     * échoué ; sinon, la page neutre.
     *
     * @param  Closure(): ReponseDuDevis  $reponse
     */
    private function repondre(string $jeton, Closure $reponse, ?string $signataire, ?string $motif, bool $debutAnticipe): ReponseHttp
    {
        try {
            $enregistree = $reponse();
        } catch (LienDuDevisInvalideException) {
            return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $this->confirmationOuInvalide($jeton, $lien, $devis));
        } catch (ValidationException $refus) {
            return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $lien->estValable($devis)
                ? $this->page($jeton, $devis, array_values($refus->validator->errors()->all()), $signataire, $motif, $debutAnticipe, 422)
                : $this->confirmationOuInvalide($jeton, $lien, $devis));
        }

        if ($enregistree->reponse === ReponseAuDevis::Acceptation) {
            return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $this->confirmationOuInvalide($jeton, $lien, $devis));
        }

        $devis = Devis::query()->withoutGlobalScopes()->whereKey($enregistree->devis_id)->where('magasin_id', $enregistree->magasin_id)->firstOrFail(['id', 'magasin_id', 'numero']);
        $magasin = Magasin::query()->findOrFail($devis->magasin_id);

        return self::protegee(response()->view('devis.reponse-enregistree', [
            'numero' => $devis->numero,
            'acceptation' => null,
            'quand' => $magasin->calendrier()->afficher($enregistree->created_at, 'd/m/Y à H:i'),
            'heure' => $magasin->calendrier()->libelleDeLHeure(),
            'adresseDuPdf' => null,
            'retractation' => null,
            'debutAnticipe' => null,
        ]));
    }

    /**
     * La confirmation de l'acceptation que ce lien a enregistrée, tant que
     * le devis la garde, sinon la page neutre.
     */
    private function confirmationOuInvalide(string $jeton, LienDuDevis $lien, Devis $devis): ReponseHttp
    {
        return $lien->confirmeLAcceptation($devis) ? $this->confirmation($jeton, $devis) : self::invalide();
    }

    /**
     * La confirmation d'une acceptation, que montre le lien qui l'a
     * enregistrée (voir LienDuDevis::confirmeLAcceptation()) : « Devis
     * accepté le … par … », le lien du PDF signé et, pour un particulier,
     * son droit de rétractation, le formulaire et sa réponse à la demande
     * de commencer la prestation avant la fin du délai (T86).
     */
    private function confirmation(string $jeton, Devis $devis): ReponseHttp
    {
        $reponse = ValeurRequise::de($devis->reponseALaVersionEnvoyee(), 'l\'acceptation du devis');
        $calendrier = $devis->magasin->calendrier();
        $particulier = ! $devis->estPourUnProfessionnel();

        return self::protegee(response()->view('devis.reponse-enregistree', [
            'numero' => $devis->numero,
            'acceptation' => ValeurRequise::de($reponse->signataire, 'le nom tapé par le client qui a accepté'),
            'quand' => $calendrier->afficher($reponse->created_at, 'd/m/Y à H:i'),
            'heure' => $calendrier->libelleDeLHeure(),
            'adresseDuPdf' => route('devis.reponse.pdf', ['jeton' => $jeton]),
            'retractation' => $particulier ? new DroitDeRetractation($devis->emetteur()) : null,
            'debutAnticipe' => $particulier ? (bool) $reponse->debut_avant_la_fin_du_delai : null,
        ]));
    }

    /**
     * Ouvre le magasin du lien, le temps de lire son devis, et laisse la
     * suite dire ce que le lien montre encore (voir LienDuDevis::estValable()
     * et confirmeLAcceptation()) ; la page neutre pour un lien inconnu ou un
     * devis introuvable.
     *
     * @param  Closure(LienDuDevis, Devis): ReponseHttp  $suite
     */
    private function dansLeMagasinDuLien(string $jeton, Closure $suite): ReponseHttp
    {
        $lien = LienDuDevis::parSonJeton($jeton);

        if ($lien === null) {
            return self::invalide();
        }

        $magasin = Magasin::query()->findOrFail($lien->magasin_id);

        return app(TenantContext::class)->runAs($magasin, function () use ($lien, $magasin, $suite): ReponseHttp {
            $devis = Devis::query()->whereKey($lien->devis_id)->with(['client', 'lignes', 'derniereReponse'])->first();

            if ($devis === null) {
                return self::invalide();
            }

            $devis->setRelation('magasin', $magasin);

            return $suite($lien, $devis);
        });
    }

    /**
     * La page du devis valable : tout ce que son PDF imprime, depuis les
     * mêmes données (voir devis/document), sans le PU HT exact, que seul le
     * PDF écrit ; puis les deux réponses.
     *
     * @param  list<string>  $erreurs
     */
    private function page(string $jeton, Devis $devis, array $erreurs, ?string $signataire, ?string $motif, bool $debutAnticipe = false, int $statut = 200): ReponseHttp
    {
        $emetteur = $devis->emetteur();

        return self::protegee(response()->view('devis.reponse', [
            'emetteur' => $emetteur,
            'devis' => $devis,
            'pied' => PiedDePage::pour($devis, $emetteur),
            'tableau' => TableauDesLignes::pour($devis),
            'erreurs' => $erreurs,
            'signataire' => $signataire,
            'motif' => $motif,
            'debutAnticipe' => $debutAnticipe,
            'depannage' => DepannageADomicile::duDevis($devis),
            'retractation' => $devis->estPourUnProfessionnel() ? null : new DroitDeRetractation($emetteur),
            'actionAccepter' => route('devis.reponse.accepter', ['jeton' => $jeton]),
            'actionRefuser' => route('devis.reponse.refuser', ['jeton' => $jeton]),
            'signataireMaximum' => RepondreAuDevisEnLigneAction::SIGNATAIRE_MAXIMUM,
            'motifMaximum' => RepondreAuDevisEnLigneAction::MOTIF_MAXIMUM,
        ], $statut));
    }

    private static function invalide(): Response
    {
        return self::protegee(response()->view('devis.reponse-invalide', ['message' => LienDuDevis::PLUS_VALABLE], 410));
    }

    /**
     * Trop d'essais depuis cette adresse IP (voir
     * AppServiceProvider::limiterLesEssaisDuLienDUnDevis()) : la page
     * neutre du lien, en français, avec ses en-têtes et le délai de
     * l'attente (Retry-After).
     *
     * @param  array<string, int|string>  $entetes
     */
    public static function tropDEssais(array $entetes): Response
    {
        return self::protegee(response()->view('devis.reponse-invalide', ['message' => self::TROP_D_ESSAIS], 429, $entetes));
    }

    /**
     * Les en-têtes de toute page du lien : ni indexée, ni transmise aux
     * liens qui en partent, ni gardée en cache.
     *
     * @template TReponse of ReponseHttp
     *
     * @param  TReponse  $reponse
     * @return TReponse
     */
    private static function protegee(ReponseHttp $reponse): ReponseHttp
    {
        $reponse->headers->set('Referrer-Policy', 'no-referrer');
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
