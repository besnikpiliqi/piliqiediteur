<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Services\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
 * save() ouvre lui-même cette transaction quand un paramètre change, et le
 * registre du magasin se verrouille avant que la fiche ne s'écrive, dans
 * l'ordre des verrous de RegistreFiscal. Fermé par défaut : si le registre
 * ne peut pas inscrire l'événement, la modification est refusée avec lui.
 *
 * Seules les écritures qui passent par le modèle sont journalisées : un
 * update() en masse, DB::table() ou une requête brute ne déclenchent aucun
 * événement Eloquent, et aucun code ne modifie ces paramètres ainsi.
 */
trait JournaliseSesParametresDeConformite
{
    public static function bootJournaliseSesParametresDeConformite(): void
    {
        static::updating(static function (Model $fiche): void {
            $fiche->journaliserSesParametresModifies();
        });
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty($this->parametresDeConformite())) {
            return parent::save($options);
        }

        return DB::transaction(fn (): bool => parent::save($options));
    }

    /**
     * Les colonnes de la fiche qui sont des paramètres ayant un impact sur
     * la conformité.
     *
     * @return list<string>
     */
    abstract protected function parametresDeConformite(): array;

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

    private function journaliserSesParametresModifies(): void
    {
        $parametresModifies = array_values(array_filter(
            $this->parametresDeConformite(),
            fn (string $parametre): bool => $this->isDirty($parametre),
        ));

        if ($parametresModifies === []) {
            return;
        }

        if ($this->magasinIdDesParametres() !== app(TenantContext::class)->id()) {
            throw new LogicException('Un paramètre ayant un impact sur la conformité ne se modifie que dans son propre magasin, dont le journal des événements note la modification.');
        }

        foreach ($parametresModifies as $parametre) {
            app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => $parametre,
                ...$this->identiteDansLeJournal(),
                'ancienne_valeur' => self::valeurDansLeJournal($this->getOriginal($parametre)),
                'nouvelle_valeur' => self::valeurDansLeJournal($this->getAttribute($parametre)),
            ], $this->documentDuJournal());
        }
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
