<?php

namespace App\Filament\App\Resources\FactureAchatResource\Pages;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RevientALaListe;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * La saisie d'une facture d'achat, avec la date et les pièces du
 * fournisseur (voir CreerFactureAchatAction) : les fichiers déposés
 * s'effacent du dossier temporaire des envois une fois la facture
 * enregistrée ; refusée, la saisie les garde pour être corrigée.
 */
class CreateFactureAchat extends CreateRecord
{
    use GereLesExceptionsMetier, RevientALaListe;

    protected static string $resource = FactureAchatResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $factureAchat = static::executerOuInterrompre(function () use ($data) {
            $fournisseur = Fournisseur::query()->whereKey(ValeursLues::cle($data['fournisseur_id']))->firstOrFail();

            return app(CreerFactureAchatAction::class)->handle(CompteConnecte::exiger(), $fournisseur, array_values(ValeursLues::lignes($data['lignes'])), [
                'numero_fournisseur' => $data['numero_fournisseur'] ?? null,
                'date_facture' => $data['date_facture'] ?? null,
                'escompte_pourcentage' => $data['escompte_pourcentage'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ], FactureAchatResource::montantsDuFournisseur($data), PieceDeposee::lire($data['pieces'] ?? []));
        });
        PieceDeposee::effacerLesEnvois($data['pieces'] ?? []);

        return $factureAchat;
    }
}
