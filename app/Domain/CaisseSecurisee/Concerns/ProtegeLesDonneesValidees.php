<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Une donnée fiscale validée ne se modifie ni ne se supprime (art. 286
 * I-3° bis du CGI) : le modèle refuse, avant toute requête, d'en modifier
 * une colonne qui n'est pas restée modifiable, de la supprimer, ou de lui
 * ajouter une ligne, et lève DonneeFiscaleInalterableException.
 *
 * Le modèle dit quand sa donnée est validée (estValidee()), d'après la
 * base et non d'après sa copie en mémoire, qui peut dater d'avant la
 * validation ; ce qui reste alors modifiable
 * (colonnesModifiablesUneFoisValidee(), rien par défaut) ; et la règle
 * que rappelle un refus (regleDInalterabilite()).
 *
 * Seules les écritures qui passent par le modèle sont arrêtées ici : un
 * update() ou un delete() en masse, DB::table() ou une requête brute ne
 * déclenchent aucun événement Eloquent. Sur les tables où l'on ne fait
 * qu'ajouter (paiements, imputations, registre fiscal), les déclencheurs
 * SQL les refusent (voir DeclencheursDeProtection::ajoutSeul()) ; sur les
 * factures, les avoirs et leurs lignes, dont le brouillon reste
 * modifiable, le contenu signé au registre fiscal à l'émission en révèle
 * les effets.
 */
trait ProtegeLesDonneesValidees
{
    public static function bootProtegeLesDonneesValidees(): void
    {
        static::creating(static function (Model $donnee): void {
            if ($donnee->estValidee()) {
                throw DonneeFiscaleInalterableException::ajout($donnee->regleDInalterabilite());
            }
        });

        static::updating(static function (Model $donnee): void {
            if (! $donnee->estValidee()) {
                return;
            }

            $refusees = array_values(array_diff(array_keys($donnee->getDirty()), $donnee->colonnesModifiablesUneFoisValidee()));

            if ($refusees !== []) {
                throw DonneeFiscaleInalterableException::modification($donnee->regleDInalterabilite(), $refusees);
            }
        });

        static::deleting(static function (Model $donnee): void {
            if ($donnee->estValidee()) {
                throw DonneeFiscaleInalterableException::suppression($donnee->regleDInalterabilite());
            }
        });
    }

    /**
     * La donnée est-elle validée en base ? Pour une donnée pas encore
     * enregistrée, l'ajout lui-même serait-il une modification d'une
     * donnée validée (une ligne ajoutée à un document émis) ?
     */
    abstract public function estValidee(): bool;

    /**
     * Ce qui reste permis sur la donnée validée, ou pourquoi plus rien ne
     * l'est : le début du message de refus.
     */
    abstract protected function regleDInalterabilite(): string;

    /**
     * Les colonnes qui restent modifiables une fois la donnée validée.
     *
     * @return list<string>
     */
    protected function colonnesModifiablesUneFoisValidee(): array
    {
        return [];
    }

    /**
     * L'un des documents $identifiants du modèle $modele, dans le magasin
     * de la donnée, est-il numéroté, donc émis, en base ? Lu sans
     * MagasinScope, filtré explicitement par magasin : la garde ne dépend
     * pas du magasin actif.
     *
     * @param  class-string<Model>  $modele
     * @param  list<int|string|null>  $identifiants
     */
    protected function documentNumeroteEnBase(string $modele, array $identifiants): bool
    {
        $identifiants = array_values(array_unique(array_filter($identifiants, static fn (int|string|null $identifiant): bool => $identifiant !== null)));

        return $identifiants !== [] && $modele::withoutGlobalScopes()
            ->whereKey($identifiants)
            ->where('magasin_id', $this->exists ? $this->getRawOriginal('magasin_id') : app(TenantContext::class)->id())
            ->whereNotNull('numero')
            ->exists();
    }
}
