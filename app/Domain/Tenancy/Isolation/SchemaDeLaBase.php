<?php

namespace App\Domain\Tenancy\Isolation;

use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\ConnectionInterface;

/**
 * Le schéma réel de la base, lu dans le catalogue de PostgreSQL (schéma
 * public), jamais dans les migrations ni dans le code : ses tables et leurs
 * colonnes, ses clés étrangères (colonnes de la table et colonnes visées, dans
 * l'ordre de la clé), ses déclencheurs (actifs ou désactivés) et le réglage de
 * leurs fonctions. Une table nouvelle est ainsi vérifiée sans qu'on pense à
 * l'ajouter (voir VerificationDeLIsolation). Il ne lit aucune ligne.
 */
final class SchemaDeLaBase
{
    /** @var array<string, list<string>>|null */
    private ?array $colonnes = null;

    /** @var list<array{nom: string, table: string, colonnes: list<string>, visee: string, colonnes_visees: list<string>}>|null */
    private ?array $cles = null;

    public function __construct(private readonly ConnectionInterface $base) {}

    /**
     * Les colonnes de chaque table, par nom de table, dans l'ordre du nom.
     *
     * @return array<string, list<string>>
     */
    public function colonnes(): array
    {
        if ($this->colonnes !== null) {
            return $this->colonnes;
        }

        $colonnes = [];

        foreach ($this->base->select(<<<'SQL'
            select c.table_name as table_, c.column_name as colonne
              from information_schema.columns c
              join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
             where c.table_schema = 'public' and t.table_type = 'BASE TABLE'
             order by c.table_name, c.ordinal_position
            SQL) as $ligne) {
            $champs = self::champsDe($ligne);
            $colonnes[ValeursLues::texte(ValeursLues::champ($champs, 'table_'))][] = ValeursLues::texte(ValeursLues::champ($champs, 'colonne'));
        }

        ksort($colonnes);

        return $this->colonnes = $colonnes;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->colonnes());
    }

    public function aLaColonne(string $table, string $colonne): bool
    {
        return in_array($colonne, $this->colonnes()[$table] ?? [], true);
    }

    /**
     * Les clés étrangères du schéma public, par table puis par nom : chaque
     * colonne de la clé avec la colonne qu'elle vise, dans l'ordre de la clé.
     *
     * @return list<array{nom: string, table: string, colonnes: list<string>, visee: string, colonnes_visees: list<string>}>
     */
    public function clesEtrangeres(): array
    {
        if ($this->cles !== null) {
            return $this->cles;
        }

        $cles = [];

        foreach ($this->base->select(<<<'SQL'
            select con.conname as nom,
                   src.relname as table_,
                   dst.relname as visee,
                   array_to_string(array(select a.attname from unnest(con.conkey) with ordinality k(num, rang) join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.num order by k.rang), ',') as colonnes,
                   array_to_string(array(select a.attname from unnest(con.confkey) with ordinality k(num, rang) join pg_attribute a on a.attrelid = con.confrelid and a.attnum = k.num order by k.rang), ',') as colonnes_visees
              from pg_constraint con
              join pg_class src on src.oid = con.conrelid
              join pg_class dst on dst.oid = con.confrelid
             where con.contype = 'f' and con.connamespace = 'public'::regnamespace
             order by src.relname, con.conname
            SQL) as $ligne) {
            $champs = self::champsDe($ligne);
            $cles[] = [
                'nom' => ValeursLues::texte(ValeursLues::champ($champs, 'nom')),
                'table' => ValeursLues::texte(ValeursLues::champ($champs, 'table_')),
                'colonnes' => explode(',', ValeursLues::texte(ValeursLues::champ($champs, 'colonnes'))),
                'visee' => ValeursLues::texte(ValeursLues::champ($champs, 'visee')),
                'colonnes_visees' => explode(',', ValeursLues::texte(ValeursLues::champ($champs, 'colonnes_visees'))),
            ];
        }

        return $this->cles = $cles;
    }

    /**
     * Les déclencheurs posés par l'application (pas ceux des contraintes) :
     * leur table, leur nom, leur fonction, s'ils sont actifs (tgenabled
     * différent de « D ») et le réglage de leur fonction (« search_path=… »).
     *
     * @return list<array{table: string, nom: string, fonction: string, actif: bool, reglages: string}>
     */
    public function declencheurs(): array
    {
        $declencheurs = [];

        foreach ($this->base->select(<<<'SQL'
            select c.relname as table_, t.tgname as nom, p.proname as fonction, t.tgenabled <> 'D' as actif,
                   coalesce(array_to_string(p.proconfig, '|'), '') as reglages
              from pg_trigger t
              join pg_class c on c.oid = t.tgrelid
              join pg_proc p on p.oid = t.tgfoid
             where not t.tgisinternal and c.relnamespace = 'public'::regnamespace
             order by c.relname, t.tgname
            SQL) as $ligne) {
            $champs = self::champsDe($ligne);
            $declencheurs[] = [
                'table' => ValeursLues::texte(ValeursLues::champ($champs, 'table_')),
                'nom' => ValeursLues::texte(ValeursLues::champ($champs, 'nom')),
                'fonction' => ValeursLues::texte(ValeursLues::champ($champs, 'fonction')),
                'actif' => ValeursLues::champ($champs, 'actif') === true,
                'reglages' => ValeursLues::texte(ValeursLues::champ($champs, 'reglages')),
            ];
        }

        return $declencheurs;
    }

    /**
     * Les champs d'une ligne lue par DB::select(), par nom de colonne.
     *
     * @return array<string, mixed>
     */
    public static function champsDe(mixed $ligne): array
    {
        return ValeursLues::champs(is_object($ligne) ? get_object_vars($ligne) : $ligne);
    }
}
