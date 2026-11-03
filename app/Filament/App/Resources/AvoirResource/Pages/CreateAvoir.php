<?php

namespace App\Filament\App\Resources\AvoirResource\Pages;

use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Crée et émet l'avoir d'un seul geste, comme l'action rapide de
 * FactureResource (voir EnregistrerAvoirAction) : aucun brouillon d'avoir
 * n'existe jamais dans l'interface, pas même après un refus.
 *
 * L'avoir part du numéro d'un ticket ou d'une facture (voir AvoirResource),
 * jamais d'un client choisi : le client envoyé à l'émission est celui du
 * premier document, relu par le serveur (voir
 * AvoirResource::clientDuPremierDocument()). Un vendeur ne crédite que ses
 * ventes (B16, voir AvoirResource::autoriserLesLignesACrediter()).
 */
class CreateAvoir extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = AvoirResource::class;

    /**
     * Ouverte depuis la fiche d'un ticket ou d'une facture (voir
     * ViewFacture), avec son numéro dans « document », la page le cherche
     * aussitôt, comme s'il avait été saisi : la même recherche, avec les
     * mêmes refus, affichés sous le champ.
     */
    protected function afterFill(): void
    {
        $numero = request()->query('document');

        if (! is_string($numero) || blank($numero)) {
            return;
        }

        try {
            $this->data['lignes'] = AvoirResource::lignesAvecLeDocument($numero, []);
        } catch (InvalidArgumentException $refus) {
            $this->data['recherche'] = $numero;
            $this->addError('data.recherche', $refus->getMessage());
        }
    }

    /**
     * @throws AuthorizationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        AvoirResource::autoriserLesLignesACrediter($data['lignes']);

        return static::executerOuInterrompre(fn () => app(EnregistrerAvoirAction::class)->handle(auth()->user(),
            AvoirResource::clientDuPremierDocument($data['lignes']),
            $data['lignes'],
            $data['motif'],
            $data['jeton'],
        ));
    }
}
