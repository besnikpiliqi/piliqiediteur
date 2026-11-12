<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use LogicException;

/**
 * À appliquer sur tout modèle appartenant à un magasin (Produit, Client,
 * Fournisseur, Facture, ...).
 *
 * Deux garanties, jamais une seule :
 *  1. Lecture : MagasinScope filtre automatiquement toute requête.
 *  2. Écriture : magasin_id est TOUJOURS écrasé par le TenantContext actif
 *     à la création, quelle que soit la valeur envoyée par le payload. On
 *     ne fait jamais confiance à un magasin_id fourni par le client : sans
 *     ça, un payload malveillant pourrait rattacher un enregistrement à un
 *     autre magasin via mass assignment.
 *
 * magasin_id ne doit JAMAIS figurer dans $fillable des modèles qui
 * utilisent ce trait.
 *
 * Un identifiant choisi dans un formulaire se valide avec
 * regleExisteDansLeMagasin(), jamais avec une règle « exists » nue.
 *
 * Une action qui reçoit une fiche déjà chargée (client, fournisseur) la
 * relit avec relueDansLeMagasin() avant toute écriture : MagasinScope
 * filtre les requêtes, jamais un objet reçu, que son appelant (tâche,
 * commande, API) a pu charger dans un autre magasin ou dont il a pu
 * changer le magasin_id en mémoire. En base, la clé composée
 * (magasin_id, …) → (magasin_id, id) refuse de même une référence vers la
 * fiche d'un autre magasin.
 *
 * Son magasin n'est jamais nul : magasin_id ne l'est jamais dans les
 * tables de ces modèles, sa clé étrangère vise un magasin, qu'aucun
 * magasin ne s'efface (voir DeclencheursDeProtection::sansSuppression())
 * et que Magasin n'a aucun scope global.
 *
 * @property-read Magasin $magasin
 */
trait BelongsToMagasin
{
    public static function bootBelongsToMagasin(): void
    {
        static::addGlobalScope(new MagasinScope);

        static::creating(function (Model $model): void {
            $model->setAttribute('magasin_id', app(TenantContext::class)->id());
        });
    }

    /**
     * Le magasin d'une fiche se lit toujours en entier, même avant son
     * enregistrement : les droits le comparent strictement à celui du
     * magasin actif (voir MagasinPolicy).
     */
    public function initializeBelongsToMagasin(): void
    {
        $this->mergeCasts(['magasin_id' => 'integer']);
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    /**
     * La fiche reçue, relue par sa clé dans le magasin actif (MagasinScope),
     * fiches archivées comprises si le modèle s'archive : un document déjà
     * rattaché à un client archivé depuis se modifie encore (voir
     * EditFacture). Seule la fiche relue sert ensuite : ni le magasin_id
     * qu'elle porte en mémoire, ni ses autres attributs ne sont crus.
     *
     * @throws LogicException avec $refus, quand la fiche n'est pas une fiche du magasin actif
     */
    public static function relueDansLeMagasin(self $fiche, string $refus): static
    {
        $requete = static::query();

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $requete->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $requete->whereKey($fiche->getKey())->first() ?? throw new LogicException($refus);
    }

    /**
     * La règle « exists » d'un identifiant de ce modèle choisi dans un
     * formulaire : une fiche du magasin actif, et pas une fiche archivée --
     * ou, avec $parmi, l'une des fiches de cette requête (archivées
     * comprises si elle les garde, voir Client::scopeQuiPeuventRegler()).
     * La validation interroge la table sans passer par MagasinScope : une
     * règle « exists » nue accepterait l'identifiant d'une fiche d'un autre
     * magasin, et révélerait lesquels existent. Elle reste donc limitée au
     * magasin actif, même quand $parmi l'est déjà par MagasinScope.
     *
     * @param  Builder<static>|null  $parmi
     */
    public static function regleExisteDansLeMagasin(?Builder $parmi = null): Exists
    {
        $modele = static::query()->getModel();
        $regle = Rule::exists($modele->getTable(), $modele->getKeyName())
            ->where('magasin_id', app(TenantContext::class)->id());

        if ($parmi !== null) {
            return $regle->where(fn (QueryBuilder $fiches): QueryBuilder => $fiches->whereIn($modele->getKeyName(), $parmi->select($modele->getQualifiedKeyName())));
        }

        return method_exists($modele, 'getDeletedAtColumn')
            ? $regle->whereNull(ValeursLues::texte($modele->getDeletedAtColumn()))
            : $regle;
    }
}
