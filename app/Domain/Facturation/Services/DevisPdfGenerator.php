<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Support\ValeurRequise;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Même présentation que la facture (voir FacturePdfGenerator), depuis les
 * lignes et les totaux enregistrés du devis, jamais depuis l'état courant
 * du catalogue, sur la même page A4 numérotée (voir MiseEnPageA4), avec
 * le même tableau des lignes (voir TableauDesLignes). Un devis n'est pas
 * signé au registre fiscal : il imprime l'émetteur figé à son dernier
 * envoi, la société et le magasin d'aujourd'hui tant qu'il est un
 * brouillon (voir Devis::emetteur()) ; ses mentions de dépannage à
 * domicile (voir DepannageADomicile), avec, signé chez le client, le droit
 * de rétractation et son formulaire (voir DroitDeRetractation) ; et son
 * cadre « Bon pour accord », rempli une fois accepté en ligne (modèle D1
 * choisi par l'éditeur le 14/11/2026).
 */
class DevisPdfGenerator
{
    public function __construct(private readonly MiseEnPageA4 $miseEnPage) {}

    /**
     * @throws VenteNonAutoriseeException
     */
    public function generer(Devis $devis): PdfDocument
    {
        return $this->miseEnPage->pdf($this->html($devis));
    }

    /**
     * La page que le PDF imprime, rien avant la validation de la société
     * (voir Magasin::verifierQuIlPeutImprimer()).
     *
     * @throws VenteNonAutoriseeException
     */
    public function html(Devis $devis): string
    {
        $devis->magasin->verifierQuIlPeutImprimer();
        $devis->loadMissing(['client', 'lignes', 'magasin', 'derniereReponse']);

        $emetteur = $devis->emetteur();
        $depannage = DepannageADomicile::duDevis($devis);

        return view('devis.pdf', [
            'devis' => $devis,
            'emetteur' => $emetteur,
            'pied' => PiedDePage::pour($devis, $emetteur),
            'tableau' => TableauDesLignes::pour($devis),
            'depannage' => $depannage,
            'acceptation' => self::acceptationEnLigne($devis),
            'retractation' => $depannage !== null && $depannage->signeChezLeClient ? new DroitDeRetractation($emetteur) : null,
        ])->render();
    }

    /**
     * Ce que le cadre « Bon pour accord » imprime de l'acceptation en
     * ligne de la version que le devis imprime (voir
     * Devis::reponseALaVersionEnvoyee()) : sa date et son heure à
     * l'heure du magasin, le nom tapé par le client, la référence de
     * l'acceptation et les seize premiers caractères de l'empreinte du
     * devis accepté, par groupes de quatre (modèle D1) ; null sans elle,
     * le cadre restant vide pour une signature à la main.
     *
     * @return array{quand: string, heure: string, signataire: string, reference: string, empreinte: string}|null
     */
    public static function acceptationEnLigne(Devis $devis): ?array
    {
        $reponse = $devis->reponseALaVersionEnvoyee();

        if ($reponse === null || $reponse->reponse !== ReponseAuDevis::Acceptation) {
            return null;
        }

        $calendrier = $devis->magasin->calendrier();

        return [
            'quand' => $calendrier->afficher($reponse->created_at, 'd/m/Y à H:i'),
            'heure' => $calendrier->libelleDeLHeure(),
            'signataire' => ValeurRequise::de($reponse->signataire, 'le nom tapé par le client qui a accepté'),
            'reference' => ValeurRequise::de($reponse->reference, 'la référence de l\'acceptation'),
            'empreinte' => implode(' ', str_split(substr($reponse->empreinte_du_devis, 0, 16), 4)),
        ];
    }

    public function nomFichier(Devis $devis): string
    {
        $identifiant = $devis->numero ?? "brouillon-{$devis->id}";

        return "devis-{$identifiant}.pdf";
    }
}
