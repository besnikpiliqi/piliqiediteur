<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Une fiche dont certaines colonnes sont des paramètres ayant un impact
 * sur la conformité (art. 286 I-3° bis du CGI) : les réglages fiscaux et
 * les identifiants d'une société (régime de TVA, option pour les débits,
 * mois de fin d'exercice, SIREN, n° de TVA), le taux de TVA d'un produit.
 * Chaque modification de l'un d'eux s'inscrit au journal des événements
 * de chaque magasin qu'il concerne (JET 270, voir magasinsDuJournal()),
 * avec son ancienne et sa nouvelle valeur, dans la même transaction que la
 * modification : l'une ne s'enregistre jamais sans l'autre.
 *
 * Quand un paramètre change, save() s'exécute sous le verrou des registres
 * de tous ces magasins, premiers verrous de sa transaction, pris par
 * identifiant croissant (voir RegistreFiscal::sousVerrouDesMagasins()) :
 * appelé hors transaction, il ouvre la sienne et verrouille les registres
 * avant toute écriture ; appelé dans la transaction d'une action, celle-ci
 * doit les avoir verrouillés dès son début (voir ConstructeurLigneAchat),
 * sans quoi la modification est refusée. La ligne de la fiche se relit
 * ensuite, verrouillée : l'ancienne valeur inscrite est celle que la
 * modification remplace réellement, même si la fiche en mémoire a été
 * chargée avant qu'une autre modification ne l'emporte ; une valeur déjà
 * en base n'est pas un changement. Fermé par défaut : si l'un des
 * registres ne peut pas inscrire l'événement, la modification est
 * refusée avec lui, dans aucun magasin.
 *
 * Seules les écritures qui passent par save() sont journalisées : un
 * update() en masse, DB::table() ou une requête brute n'y passent pas, et
 * aucun code ne modifie ces paramètres ainsi.
 */
trait JournaliseSesParametresDeConformite
{
    public function save(array $options = []): bool
    {
        $parametresModifies = array_values(array_filter(
            static::parametresDeConformite(),
            fn (string $parametre): bool => $this->isDirty($parametre),
        ));

        if (! $this->exists || $parametresModifies === []) {
            return parent::save($options);
        }

        $magasins = $this->magasinsDuJournal();

        return app(RegistreFiscal::class)->sousVerrouDesMagasins($magasins, function () use ($options, $parametresModifies, $magasins): bool {
            $enBase = $this->newQueryWithoutScope(SoftDeletingScope::class)
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail($parametresModifies);

            if (! parent::save($options)) {
                return false;
            }

            foreach ($parametresModifies as $parametre) {
                $ancienneValeur = self::valeurDansLeJournal($enBase->getAttribute($parametre));
                $nouvelleValeur = self::valeurDansLeJournal($this->getAttribute($parametre));

                if ($ancienneValeur === $nouvelleValeur) {
                    continue;
                }

                foreach ($magasins as $magasin) {
                    app(TenantContext::class)->runAs($magasin, fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, [
                        'parametre' => $parametre,
                        ...$this->identiteDansLeJournal(),
                        'ancienne_valeur' => $ancienneValeur,
                        'nouvelle_valeur' => $nouvelleValeur,
                    ], $this->documentDuJournal()));
                }
            }

            return true;
        });
    }

    /**
     * Les colonnes de la fiche qui sont des paramètres ayant un impact sur
     * la conformité.
     *
     * @return list<string>
     */
    abstract public static function parametresDeConformite(): array;

    /**
     * Les magasins dont ces paramètres règlent la conformité, et dont le
     * journal des événements reçoit chaque modification.
     *
     * @return Collection<int, Magasin>
     */
    abstract protected function magasinsDuJournal(): Collection;

    /**
     * Ce que l'événement retient de la fiche en plus du paramètre, figé
     * au moment de la modification : son identité, quand ce n'est pas la
     * société elle-même.
     *
     * @return array<string, mixed>
     */
    protected function identiteDansLeJournal(): array
    {
        return [];
    }

    /**
     * Le document que référence l'événement : une fiche du magasin actif,
     * ou aucun.
     */
    protected function documentDuJournal(): ?Model
    {
        return null;
    }

    /**
     * Une valeur telle que le registre la signe : une énumération par sa
     * valeur, un montant ou un taux décimal par sa chaîne (cast decimal),
     * jamais en nombre à virgule flottante.
     */
    private static function valeurDansLeJournal(mixed $valeur): string|int|bool|null
    {
        return match (true) {
            $valeur instanceof BackedEnum => $valeur->value,
            $valeur === null, is_bool($valeur), is_int($valeur), is_string($valeur) => $valeur,
            default => (string) $valeur,
        };
    }
}
