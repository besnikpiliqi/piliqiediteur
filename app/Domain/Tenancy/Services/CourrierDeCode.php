<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Support\QrCode;
use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;

/**
 * Le courrier recommandé à code, mis en page par le logiciel (une page A4
 * en portrait, rendue par dompdf) avant que le prestataire ne l'imprime
 * (voir ServiceEnvoiRecommande) : l'expéditeur ; le destinataire, au nom
 * du représentant légal, à l'adresse officielle du siège (voir
 * DestinataireDuCourrier), à la place de la fenêtre d'une enveloppe ; la
 * mention de la remise au destinataire en personne ; l'objet ; le SIREN ;
 * le code, son expiration et son usage unique ; l'adresse courte de la page
 * où le saisir et un QR code qui l'ouvre avec le SIREN déjà rempli, sans
 * jamais porter le code (voir QrCode) ; ce que fait quelqu'un qui n'est
 * pas à l'origine de l'inscription ; et l'adresse de l'éditeur. Rien de ce
 * que le compte a saisi n'y figure. Le code en clair n'existe que dans ce
 * PDF, que le prestataire reçoit et que le logiciel ne garde pas.
 */
class CourrierDeCode
{
    /**
     * Le PDF du courrier, tel que le prestataire l'imprime.
     */
    public function pdf(DestinataireDuCourrier $destinataire, string $siren, MotifCode $motif, string $code, CarbonInterface $expireLe): string
    {
        return Pdf::loadHTML($this->html($destinataire, $siren, $motif, $code, $expireLe))->setPaper('a4', 'portrait')->output();
    }

    public function html(DestinataireDuCourrier $destinataire, string $siren, MotifCode $motif, string $code, CarbonInterface $expireLe): string
    {
        return view('courriers.code', [
            'destinataire' => $destinataire,
            'siren' => $siren,
            'motif' => $motif,
            'code' => $code,
            'expireLe' => $expireLe->copy()->setTimezone(config('app.timezone'))->format('d/m/Y'),
            'date' => now()->setTimezone(config('app.timezone'))->format('d/m/Y'),
            'adresseCourte' => self::adresseCourte(),
            'qrCode' => QrCode::uriDeDonnees(self::adresseDeLaPage($siren)),
            'application' => config('app.name'),
            'editeur' => config('editeur.email'),
        ])->render();
    }

    /**
     * L'adresse de la page du code, avec ce SIREN déjà rempli, telle que
     * le QR code la porte : construite depuis APP_URL, jamais depuis une
     * requête.
     */
    public static function adresseDeLaPage(string $siren): string
    {
        return rtrim((string) config('app.url'), '/').route('code.afficher', ['siren' => $siren], absolute: false);
    }

    /**
     * L'adresse de la page du code telle que le courrier l'imprime, pour
     * qui la tape : sans le protocole ni le SIREN (« piliqi.fr/code »).
     */
    public static function adresseCourte(): string
    {
        return (string) preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/').route('code.afficher', absolute: false));
    }
}
