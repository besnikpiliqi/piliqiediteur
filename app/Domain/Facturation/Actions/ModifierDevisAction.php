<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Modifie un brouillon, ou un devis envoyé ou accepté pas encore
 * transformé en facture (voir Devis::estModifiable()), lignes comprises.
 * Un devis envoyé ou accepté repasse en brouillon : le client ne s'est
 * engagé que sur la version qu'il a reçue, son acceptation est effacée, et
 * la nouvelle version n'a pas encore été émise. Il garde son numéro, que
 * son prochain envoi reprend, et la date de son dernier envoi, que ce
 * prochain envoi remplace (voir EmettreDevisAction) : même remis en main
 * propre, sans e-mail, il garde la trace de sa remise au client. Ses
 * e-mails restent dans son historique (voir EnvoiDocument), et celui qui
 * n'est pas encore parti ne part plus (voir EnvoyerDocumentJob).
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne modifie plus rien, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis le devis sous le sien : une
 * fermeture du magasin, qui verrouille cette ligne (voir
 * FermerMagasinAction), passe tout entière avant ou après la
 * modification. Un devis supprimé, converti, refusé ou expiré entre-temps
 * est refusé ; l'auteur relu doit pouvoir modifier le devis relu (voir
 * DevisPolicy::update()), et la modification se fait à son nom (voir
 * OperateurCourant).
 *
 * Comme CreerDevisAction, elle reçoit le client déjà résolu par une
 * requête limitée au magasin, jamais un client_id brut venu du formulaire,
 * et note de nouveau son type, le régime de TVA du magasin et la nature de
 * l'opération, avec lesquels les lignes se recalculent. Une remise ou un
 * prix dérogatoire que son auteur introduit exige qu'il soit autorisé à
 * accorder des remises et fixer les prix ; une ligne reprise telle quelle
 * garde l'accord déjà donné (R2, voir DerogationsDePrix).
 */
class ModifierDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly DerogationsDePrix $derogations,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException quand les conditions de paiement sont trop longues pour le pied du PDF (voir PiedDePage)
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis, Client $client, array $lignes, array $attributs = []): Devis
    {
        PiedDePage::verifierLesConditionsDePaiement($attributs['conditions_paiement'] ?? null);

        return DB::transaction(function () use ($auteur, $devis, $client, $lignes, $attributs) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::DEVIS_UPDATE, $magasin);

            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);

            if (! $devis->estModifiable()) {
                throw new LogicException(match (true) {
                    $devis->factures()->exists() => 'Ce devis ne peut plus être modifié : il a déjà été transformé en facture.',
                    $devis->estExpire() => 'Ce devis ne peut plus être modifié : un devis expiré reste tel quel, établissez-en un nouveau.',
                    default => 'Ce devis ne peut plus être modifié : un devis refusé reste tel quel, établissez-en un nouveau.',
                });
            }

            $this->lecteur->exiger($compte, 'update', $magasin, $devis);

            return $this->operateur->pendant($compte, fn (): Devis => $this->modifier($compte, $magasin, $devis, $client, $lignes, $attributs));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function modifier(User $compte, Magasin $magasin, Devis $devis, Client $client, array $lignes, array $attributs): Devis
    {
        $contexte = ContexteDeVente::pourEnregistrer($client, $magasin, $devis->fill($attributs)->noterSaNatureDOperation($attributs));
        $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
        $this->derogations->exiger($compte, $devis->lignes->map(fn (LigneDevis $ligne): array => $ligne->attributesToArray())->all(), $document->lignes, $contexte);
        $devis->forceFill([
            'client_id' => $client->getKey(),
            ...$contexte->colonnes(),
            'statut' => StatutDevis::Brouillon,
        ])->save();
        $devis->lignes()->delete();

        foreach ($document->lignes as $ligne) {
            $devis->lignes()->create($ligne);
        }

        $devis->forceFill($document->totaux)->save();

        return $devis->fresh('lignes');
    }
}
