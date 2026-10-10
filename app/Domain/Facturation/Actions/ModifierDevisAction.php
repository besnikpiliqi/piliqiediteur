<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Modifie un brouillon, ou un devis envoyé ou accepté pas encore
 * transformé en facture (voir Devis::estModifiable()), lignes comprises.
 * Un devis envoyé ou accepté repasse en brouillon : le client ne s'est
 * engagé que sur la version qu'il a reçue, son acceptation est effacée, et
 * la nouvelle version n'a pas encore été émise. Il garde son numéro, que
 * son prochain envoi reprend (voir EmettreDevisAction) ; ses envois
 * précédents restent dans son historique (voir EnvoiDocument).
 *
 * Comme CreerDevisAction, elle reçoit le client déjà résolu par une
 * requête limitée au magasin, jamais un client_id brut venu du formulaire,
 * et note de nouveau son type et le régime de TVA du magasin, avec
 * lesquels les lignes se recalculent.
 */
class ModifierDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws LogicException
     */
    public function handle(Devis $devis, Client $client, array $lignes, array $attributs = []): Devis
    {
        return DB::transaction(function () use ($devis, $client, $lignes, $attributs) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->estModifiable()) {
                throw new LogicException(match (true) {
                    $devis->factures()->exists() => 'Ce devis ne peut plus être modifié : il a déjà été transformé en facture.',
                    $devis->estExpire() => 'Ce devis ne peut plus être modifié : un devis expiré reste tel quel, établissez-en un nouveau.',
                    default => 'Ce devis ne peut plus être modifié : un devis refusé reste tel quel, établissez-en un nouveau.',
                });
            }

            $contexte = ContexteDeVente::pour($client, app(TenantContext::class)->magasin());
            $devis->fill($attributs)->forceFill([
                'client_id' => $client->getKey(),
                ...$contexte->colonnes(),
                'statut' => StatutDevis::Brouillon,
                'date_emission' => null,
            ])->save();
            $devis->lignes()->delete();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes, $contexte);

            foreach ($lignesConstruites as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $devis->fresh('lignes');
        });
    }
}
