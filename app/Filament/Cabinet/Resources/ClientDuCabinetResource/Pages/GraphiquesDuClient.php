<?php

namespace App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Enums\LectureDeLaTva;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\MessagesDuCreditDeTva;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Le tableau de bord d'un client lié, dans l'espace du cabinet (décision
 * de l'éditeur du 14/11/2026) : ses chiffres clés et ses graphiques, sur
 * les magasins que le client ouvre au cabinet, selon les
 * consultations qu'il lui accorde (voir GrilleDesGraphiques et
 * PerimetreDesGraphiques::duClient()). Le lien se lit parmi ceux que le
 * cabinet ouvre au compte (voir ClientDuCabinetResource) : un lien d'un
 * autre cabinet, d'un client non confié ou d'un cabinet qui n'est plus
 * vérifié donne 404. Un client qui n'a encore aucun magasin (une
 * société invitée par le cabinet naît sans magasin) n'a pas de graphiques
 * : la page le dit, sans monter la grille (voir
 * PerimetreDesGraphiques::voitLesGraphiquesDuClient()). Les préférences
 * du compte valent pour tous ses clients. Au-dessus, le message de la TVA
 * du client (voir messageDeLaTva()).
 *
 * @phpstan-import-type MessageDeLaTva from MessagesDuCreditDeTva
 */
class GraphiquesDuClient extends Page
{
    use InteractsWithRecord;

    public const SANS_MAGASIN = 'Ce client n\'a encore aucun magasin : ses chiffres clés et ses graphiques s\'afficheront avec son premier magasin.';

    protected static string $resource = ClientDuCabinetResource::class;

    protected static string $view = 'filament.cabinet.resources.graphiques-du-client';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Tableau de bord de '.(ClientDuCabinetResource::client($this->lien())->raison_sociale ?? 'ce client');
    }

    public function lien(): LienCabinet
    {
        return ValeursLues::instance($this->getRecord(), LienCabinet::class);
    }

    public function voitLesGraphiques(): bool
    {
        return app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuClient(CompteConnecte::exiger(), $this->lien());
    }

    /**
     * Le message de la TVA du client, du côté du comptable (lot TVA27, voir
     * MessagesDuCreditDeTva::message()) : son régime à choisir, ou où en
     * est son crédit de TVA, avec son montant pour le compte du cabinet qui
     * peut le vérifier ou voit la TVA de tout le client avec son report,
     * sans lui pour celui qui n'en voit que la carte de la TVA ; le bouton
     * mène au bloc du crédit de la page des charges du client.
     *
     * @return MessageDeLaTva|null
     */
    public function messageDeLaTva(): ?array
    {
        $compte = CompteConnecte::exiger();
        $lien = $this->lien();
        $client = ClientDuCabinetResource::client($lien);

        return app(MessagesDuCreditDeTva::class)->message(
            $client,
            $compte,
            true,
            app(DroitsSurLesCharges::class)->pour($compte, $client) === null ? null : ClientDuCabinetResource::getUrl('charges', ['record' => $lien]).'#credit-de-tva',
            fn (): LectureDeLaTva => app(PerimetreDesGraphiques::class)->lectureDeLaTvaDuClient($compte, $lien),
        );
    }
}
