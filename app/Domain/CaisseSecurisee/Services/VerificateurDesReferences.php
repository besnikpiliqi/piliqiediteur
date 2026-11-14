<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Les références polymorphes du magasin que la base ne garde pas par une
 * clé (une colonne de type et une d'identifiant, sans clé étrangère),
 * contrôlées par la vérification de l'intégrité (voir
 * VerificateurIntegrite), toujours entièrement : chaque transmission à la
 * plateforme agréée, chaque envoi d'un document et chaque demande déjà
 * traitée désigne un document qui existe dans le même magasin. La
 * référence du grand livre du stock à son document, gardée par la base
 * (voir GardesDuStock), et celle d'un enregistrement du registre fiscal,
 * contrôlée avec ses documents, n'en sont pas.
 *
 * Une requête par table et par nature de document désignée, par
 * jointure filtrée sur le magasin vérifié, jamais une par ligne : ces
 * tables sont lues sans scope (DB::table()), le filtre par magasin est
 * explicite. Une nature inconnue, ou qui n'est pas un document d'un
 * magasin, est une anomalie pour chaque ligne qui la porte.
 */
final class VerificateurDesReferences
{
    /**
     * Chaque table, sa colonne de type, sa colonne d'identifiant et le nom
     * de ses lignes dans le message.
     *
     * @var array<literal-string, array{0: literal-string, 1: literal-string, 2: string}>
     */
    private const REFERENCES = [
        'transmissions_pdp' => ['transmissible_type', 'transmissible_id', 'La transmission n° %d à la plateforme agréée'],
        'envois_documents' => ['document_type', 'document_id', 'L\'envoi n° %d'],
        'actions_traitees' => ['resultat_type', 'resultat_id', 'La demande déjà traitée n° %d'],
    ];

    /**
     * @return list<AnomalieFiscale>
     */
    public function anomalies(Magasin $magasin): array
    {
        $anomalies = [];

        foreach (self::REFERENCES as $table => [$colonneDuType, $colonneDeLIdentifiant, $libelle]) {
            $natures = DB::table($table)
                ->where('magasin_id', $magasin->getKey())
                ->whereNotNull($colonneDuType)
                ->distinct()
                ->orderBy($colonneDuType)
                ->pluck($colonneDuType);

            foreach ($natures as $nature) {
                $alias = ValeursLues::texte($nature);
                $tableDuDocument = self::tableDuDocument($alias);
                $lignes = DB::table($table)
                    ->where("{$table}.magasin_id", $magasin->getKey())
                    ->where("{$table}.{$colonneDuType}", $alias)
                    ->when($tableDuDocument !== null, fn (QueryBuilder $references): QueryBuilder => $references->whereNotExists(fn (QueryBuilder $documents): QueryBuilder => $documents
                        ->selectRaw('1')
                        ->from((string) $tableDuDocument)
                        ->whereColumn("{$tableDuDocument}.id", "{$table}.{$colonneDeLIdentifiant}")
                        ->whereColumn("{$tableDuDocument}.magasin_id", "{$table}.magasin_id")))
                    ->orderBy("{$table}.id")
                    ->get(["{$table}.id", "{$table}.{$colonneDeLIdentifiant} as document_id"]);

                foreach ($lignes as $ligne) {
                    $anomalies[] = self::anomalie($table, $libelle, $alias, $tableDuDocument !== null, $ligne);
                }
            }
        }

        return $anomalies;
    }

    /**
     * La table du document de cette nature, quand c'est un document d'un
     * magasin (voir BelongsToMagasin) ; null sinon.
     */
    private static function tableDuDocument(string $alias): ?string
    {
        $classe = Relation::getMorphedModel($alias);

        if ($classe === null || ! is_subclass_of($classe, Model::class) || ! in_array(BelongsToMagasin::class, class_uses_recursive($classe), true)) {
            return null;
        }

        return (new $classe)->getTable();
    }

    private static function anomalie(string $table, string $libelle, string $alias, bool $natureConnue, stdClass $ligne): AnomalieFiscale
    {
        $identifiant = ValeurSimple::enEntier($ligne->id);
        $document = $ligne->document_id === null ? null : ValeurSimple::enEntier($ligne->document_id);
        $designe = '« '.$alias.' » #'.($document ?? '—');

        return AnomalieFiscale::defaut('reference', sprintf($libelle, $identifiant).' désigne '.$designe.($natureConnue
            ? ', qui n\'est pas un document de ce magasin.'
            : ', qui n\'est pas un document d\'un magasin.'), [
                'table' => $table,
                'id' => $identifiant,
                'document_type' => $alias,
                'document_id' => $document,
            ]);
    }
}
