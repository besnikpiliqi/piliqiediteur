<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Note sur le ticket d'un voyageur résidant hors de l'Union le n° du
 * bordereau de vente à l'exportation (BVE) émis pour lui dans PABLO (I3,
 * art. 262 I 2° du CGI) : le ticket reste à la TVA française, tel qu'il a
 * été émis et signé, et sa TVA ne se régularise qu'après le visa du
 * bordereau à la sortie de l'Union -- ce que fera la comptabilité.
 *
 * Seul un ticket de caisse émis, vendu en France à la TVA française, se
 * détaxe : ni une facture à un professionnel, ni une vente déjà exonérée
 * par sa nature, ni un ticket vendu en franchise en base, qui n'a facturé
 * aucune TVA à rembourser. La franchise se lit sur le ticket, tel qu'il a
 * été émis, jamais sur le régime actuel du magasin. La détaxe ne
 * rembourse que la TVA de biens emportés dans les bagages (art. 262 I 2°
 * du CGI) : le ticket doit garder au moins une ligne de bien taxé
 * qu'aucun avoir émis n'a entièrement reprise (voir
 * LigneFacture::scopeBiensTaxes()) ; un ticket de services seuls, de
 * biens à 0 % ou entièrement rendu ne se détaxe pas. Des biens taxés
 * gardés pour 100,00 € TTC ou moins se détaxent quand même, les achats
 * d'un voyageur pouvant se regrouper sur trois jours consécutifs : le
 * bordereau noté le signale (voir BordereauDetaxe::avertissement()).
 *
 * Le n° s'enregistre sans espaces autour, en majuscules : de 4 à 30
 * lettres, chiffres ou tirets. Corriger une faute note un nouveau n°, le
 * dernier noté faisant foi ; noter de nouveau celui qui fait déjà foi est
 * refusé.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne note plus rien, voir Magasin::verifierQuIlEstOuvert()
 * ; une fermeture du magasin, qui la verrouille, voir
 * FermerMagasinAction, passe tout entière avant ou après), puis son
 * auteur sous le verrou de sa ligne (voir LecteurDesDroits), puis le
 * ticket sous le sien, dans le magasin actif (MagasinScope) : celui d'un
 * autre magasin est introuvable. L'auteur relu doit pouvoir justifier le
 * ticket relu (voir FacturePolicy::justifier()) ; le bordereau note son
 * nom figé.
 */
class NoterBordereauDetaxeAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Facture $facture, string $numero): BordereauDetaxe
    {
        $numero = Str::upper(trim($numero));

        if (preg_match('/^[A-Z0-9-]{4,30}$/D', $numero) !== 1) {
            throw new InvalidArgumentException('Le n° du bordereau de détaxe compte de 4 à 30 lettres, chiffres ou tirets.');
        }

        return DB::transaction(function () use ($auteur, $facture, $numero): BordereauDetaxe {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::FACTURE_JUSTIFIER, $magasin);
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'justifier', $magasin, $facture);

            if (($refus = self::refusPour($facture)) !== null) {
                throw new LogicException($refus);
            }

            if ($facture->bordereauDetaxe?->numero === $numero) {
                throw new LogicException("Le bordereau n° {$numero} est déjà celui de ce ticket.");
            }

            return BordereauDetaxe::forceCreate([
                'facture_id' => $facture->getKey(),
                'numero' => $numero,
                'note_par' => $compte->getKey(),
            ])->setRelation('facture', $facture);
        });
    }

    /**
     * Pourquoi cette vente ne se détaxe pas, null quand elle se détaxe :
     * ce que l'action refuse, et ce qui retire son bouton de la fiche.
     */
    public static function refusPour(Facture $facture): ?string
    {
        return match (true) {
            $facture->statut === StatutFacture::Brouillon, $facture->serie() !== TypeDocument::TicketCaisse => 'Le bordereau de détaxe se note sur un ticket de caisse émis : la détaxe rembourse un voyageur, jamais un professionnel.',
            $facture->nature_operation !== NatureOperation::VenteEnFrance => "Ce ticket, de nature « {$facture->nature_operation->getLabel()} », ne porte aucune TVA française : la détaxe ne s'y applique pas.",
            $facture->franchise_en_base => 'Ce ticket a été vendu en franchise en base de TVA : sans TVA facturée, la détaxe n\'a rien à rembourser.',
            ! $facture->lignes()->creditables()->biensTaxes()->exists() => 'Ce ticket ne garde aucun bien taxé à la TVA française, ni les services ni les articles rendus ne comptant : la détaxe ne s\'y applique pas.',
            default => null,
        };
    }
}
