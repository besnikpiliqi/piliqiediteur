<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\CategorieAudit;
use Illuminate\Database\Eloquent\Model;

/**
 * La tête d'une chaîne du journal d'audit, celle d'un magasin ou de la
 * société dans une catégorie : la séquence et l'empreinte de sa dernière
 * entrée, et le point de reprise une fois son début effacé à expiration.
 * Ses seuls écrivains : JournalAudit::inscrire(), qui la verrouille en
 * dernier, et app:purger-journal-audit.
 *
 * Sans scope global : elle se lit toujours par sa société, explicitement.
 */
class TeteJournalAudit extends Model
{
    protected $table = 'tetes_journal_audit';

    protected $fillable = [];

    protected $casts = [
        'categorie' => CategorieAudit::class,
        'sequence' => 'integer',
        'reprise_sequence' => 'integer',
    ];
}
