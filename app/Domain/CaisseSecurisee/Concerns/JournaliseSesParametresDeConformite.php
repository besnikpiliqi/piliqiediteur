<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Services\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

/**
 * Une fiche dont certaines colonnes sont des paramètres ayant un impact
 * sur la conformité (art. 286 I-3° bis du CGI) : le régime de TVA du
 * magasin, son option pour les débits, le taux de TVA d'un produit. Chaque
 * modification de l'un d'eux s'inscrit au journal des événements du
 * magasin (JET 270), avec son ancienne et sa nouvelle valeur, dans la même
 * transaction que la modification : l'une ne s'enregistre jamais sans
 * l'autre.
 *
 * Quand un paramètre change, save() s'exécute sous le verrou du registre
 * du magasin, premier verrou de sa transaction (voir
 * RegistreFiscal::sousVerrou()) : appelé hors transaction, il ouvre la
 * sienne et verrouille le registre avant toute écriture ; appelé dans la
 * transaction d'une action, celle-ci doit avoir verrouillé le registre dès
 * son début (voir ConstructeurLigneAchat), sans quoi la modification est
 * refusée. L'ancienne valeur inscrite est celle de la fiche en base, relue
 * sous ce verrou : celle que la modification remplace réellement, même si
 * la fiche en mémoire a été chargée avant qu'une autre modification ne
 * l'emporte ; une valeur déjà en base n'est pas un changement. Fermé par
 * défaut : si le registre ne peut pas inscrire l'événement, la
 * modification est refusée avec lui.
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

        $magasinActif = app(TenantContext::class)->magasin();

        if ($this->magasinIdDesParametres() !== $magasinActif->getKey()) {
            throw new LogicException('Un paramètre ayant un impact sur la conformité ne se modifie que dans son propre magasin, dont le journal des événements note la modification.');
        }

        return app(RegistreFiscal::class)->sousVerrou($magasinActif, function () use ($options, $parametresModifies): bool {
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

                app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, [
                    'parametre' => $parametre,
                    ...$this->identiteDansLeJournal(),
                    'ancienne_valeur' => $ancienneValeur,
                    'nouvelle_valeur' => $nouvelleValeur,
                ], $this->documentDuJournal());
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
     * Le magasin dont ces paramètres règlent la conformité : il doit être
     * le magasin actif, dont le journal reçoit l'événement.
     */
    abstract protected function magasinIdDesParametres(): int;

    /**
     * Ce que l'événement retient de la fiche en plus du paramètre, figé
     * au moment de la modification : son identité, quand ce n'est pas le
     * magasin lui-même.
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
