<?php

namespace App\Http\Controllers;

use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Exceptions\LienDuDevisInvalideException;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
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
 *   qu'il a été envoyé (le magasin qui l'envoie, son numéro, sa validité,
 *   ses lignes, ses totaux, son PDF), rien d'autre du magasin, de la
 *   société ni d'un autre client, et les deux réponses possibles ;
 * - le PDF (GET) est celui du devis envoyé, en pièce jointe ;
 * - accepter (POST) demande le nom tapé et « Bon pour accord » coché,
 *   refuser (POST) un motif facultatif (voir RepondreAuDevisEnLigneAction) ;
 *   la page dit ensuite la réponse enregistrée, et le lien ne vaut plus.
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
        return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $this->page($jeton, $devis, [], null, null));
    }

    public function pdf(string $jeton, DevisPdfGenerator $generateur): ReponseHttp
    {
        return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => self::protegee(response()->streamDownload(
            fn () => print ($generateur->generer($devis)->output()),
            $generateur->nomFichier($devis),
            EnTetesDesFichiers::fichierProduit('application/pdf'),
        )));
    }

    public function accepter(Request $requete, string $jeton, RepondreAuDevisEnLigneAction $repondre): ReponseHttp
    {
        $signataire = ValeurSimple::enTexte($requete->input('signataire'));

        return $this->repondre($jeton, fn (): ReponseDuDevis => $repondre->accepter($jeton, $signataire, $requete->boolean('bon_pour_accord')), $signataire, null);
    }

    public function refuser(Request $requete, string $jeton, RepondreAuDevisEnLigneAction $repondre): ReponseHttp
    {
        $motif = ValeurSimple::enTexte($requete->input('motif'));

        return $this->repondre($jeton, fn (): ReponseDuDevis => $repondre->refuser($jeton, $motif), null, $motif);
    }

    /**
     * La réponse enregistrée, la page qui la dit ; une saisie refusée
     * revient au formulaire avec ce qui était tapé ; un lien qui ne vaut
     * plus donne la page neutre.
     *
     * @param  Closure(): ReponseDuDevis  $reponse
     */
    private function repondre(string $jeton, Closure $reponse, ?string $signataire, ?string $motif): ReponseHttp
    {
        try {
            $enregistree = $reponse();
        } catch (LienDuDevisInvalideException) {
            return self::invalide();
        } catch (ValidationException $refus) {
            return $this->dansLeMagasinDuLien($jeton, fn (LienDuDevis $lien, Devis $devis): ReponseHttp => $this->page($jeton, $devis, array_values($refus->validator->errors()->all()), $signataire, $motif, 422));
        }

        $devis = Devis::query()->withoutGlobalScopes()->whereKey($enregistree->devis_id)->firstOrFail(['id', 'magasin_id', 'numero']);
        $magasin = Magasin::query()->findOrFail($devis->magasin_id);

        return self::protegee(response()->view('devis.reponse-enregistree', [
            'numero' => $devis->numero,
            'acceptation' => $enregistree->reponse === ReponseAuDevis::Acceptation,
            'quand' => $magasin->calendrier()->afficher($enregistree->created_at, 'd/m/Y à H:i'),
            'heure' => $magasin->calendrier()->libelleDeLHeure(),
        ]));
    }

    /**
     * Ouvre le magasin du lien, le temps de lire son devis, quand le lien
     * vaut encore ; la page neutre sinon.
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
            $devis = Devis::query()->whereKey($lien->devis_id)->with(['client', 'lignes'])->first();

            if ($devis === null) {
                return self::invalide();
            }

            $devis->setRelation('magasin', $magasin);

            return $lien->estValable($devis) ? $suite($lien, $devis) : self::invalide();
        });
    }

    /**
     * @param  list<string>  $erreurs
     */
    private function page(string $jeton, Devis $devis, array $erreurs, ?string $signataire, ?string $motif, int $statut = 200): ReponseHttp
    {
        $calculateur = app(CalculateurLigne::class);
        $horsTaxe = $devis->estPourUnProfessionnel() || ContexteDeVente::documentRetireLaTvaDuPrix($devis);
        $lignes = $devis->lignes->map(fn ($ligne): array => [
            'reference' => $ligne->reference,
            'description' => $ligne->description,
            'quantite' => $ligne->quantite,
            'prix_unitaire' => $horsTaxe
                ? $calculateur->prixUnitaireHtAfficheEnEuros($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix))
                : $calculateur->prixUnitaireAffiche($calculateur->enMillioniemes($ligne->prix_unitaire_ttc)),
            'remise' => $calculateur->enCentiemes($ligne->remise_pourcentage) === 0 ? null : CalculateurLigne::tauxEnTexte($calculateur->enCentiemes($ligne->remise_pourcentage)),
            'total' => $calculateur->enEuros($calculateur->enCentiemes($horsTaxe ? $ligne->montant_ht : $ligne->montant_ttc)),
        ])->all();

        return self::protegee(response()->view('devis.reponse', [
            'emetteur' => $devis->emetteur(),
            'devis' => $devis,
            'client' => $devis->client?->nom,
            'lignes' => $lignes,
            'horsTaxe' => $horsTaxe,
            'totalHt' => $calculateur->enEuros($calculateur->enCentiemes($devis->total_ht)),
            'totalTva' => $calculateur->enEuros($calculateur->enCentiemes($devis->total_tva)),
            'totalTtc' => $calculateur->enEuros($calculateur->enCentiemes($devis->total_ttc)),
            'validite' => $devis->date_validite,
            'erreurs' => $erreurs,
            'signataire' => $signataire,
            'motif' => $motif,
            'adresseDuPdf' => route('devis.reponse.pdf', ['jeton' => $jeton]),
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
