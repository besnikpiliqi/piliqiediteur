<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * « Télécharger » sur la page « Télécharger les documents » d'un magasin,
 * de l'espace de la société ou de l'espace du cabinet (décision de
 * l'éditeur du 03/11/2026, lot EX) : enregistre la demande, dans la société
 * dont elle lit les documents, et la confie à la file d'attente, qui
 * prépare le fichier et prévient la personne quand il est prêt (voir
 * PreparerLeTelechargementDesDocumentsJob).
 *
 * La demande désigne des magasins de cette société, chacun où la personne
 * télécharge (voir DocumentsDeLaPeriode::peutTelechargerDans(), relu sous
 * le verrou du compte) parmi ceux que la page propose ; une période d'un
 * jour au moins, dans l'ordre ; au moins un type ; l'origine des achats et,
 * sur demande, leur seule marque « pièce du fournisseur manquante » ; au
 * moins un document choisi. Les documents choisis se relisent à la
 * préparation, parmi ceux que la personne voit alors : une clé qui ne
 * désigne rien n'apporte rien.
 *
 * Un compte demande au plus dix téléchargements par heure, toutes sociétés
 * confondues : sous le verrou de la ligne du compte, ses demandes de
 * l'heure se comptent, si bien que deux demandes simultanées ne dépassent
 * jamais ce nombre. La société se verrouille d'abord, comme avant toute
 * entrée de son journal d'audit (voir
 * Societe::verrouillerAvantSonJournalDAudit()) : la demande, qui la
 * référence, ne s'interbloque jamais avec la saisie du code du courrier,
 * qui verrouille la société puis son propriétaire.
 */
class DemanderLeTelechargementDesDocumentsAction
{
    public const REFUS_PERIODE = 'Choisissez une période : son premier jour, puis son dernier, au plus tard le même.';

    public const REFUS_TYPES = 'Choisissez au moins un type de document.';

    public const REFUS_SELECTION = 'Choisissez au moins un document à télécharger.';

    public const REFUS_MAGASINS = 'Vous ne téléchargez pas les documents de ce magasin.';

    public const REFUS_PAR_HEURE = 'Vous avez déjà demandé dix téléchargements dans l\'heure : réessayez un peu plus tard.';

    public function __construct(
        private readonly TenantContext $contexte,
    ) {}

    /**
     * @param  iterable<array-key, Magasin>  $magasins  les magasins de la société choisis, chacun déjà proposé à la personne
     * @param  list<TypeDeDocumentTelechargeable>  $types
     * @param  list<string>  $selection  les clés des documents choisis (voir DocumentTrouve::cle())
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, iterable $magasins, string $du, string $au, array $types, OrigineDesAchats $origine, FormatDuTelechargement $format, array $selection, bool $pieceManquanteSeulement = false): TelechargementDeDocuments
    {
        $magasins = collect($magasins)->values();

        if (Calendrier::jourValide($du) === null || Calendrier::jourValide($au) === null || $du > $au) {
            throw new InvalidArgumentException(self::REFUS_PERIODE);
        }

        if ($types === []) {
            throw new InvalidArgumentException(self::REFUS_TYPES);
        }

        $selection = array_values(array_unique(array_filter($selection, 'is_string')));

        if ($selection === []) {
            throw new InvalidArgumentException(self::REFUS_SELECTION);
        }

        if ($magasins->isEmpty() || $magasins->contains(fn (Magasin $magasin): bool => (int) $magasin->societe_id !== CleDuModele::de($societe))) {
            throw new AuthorizationException(self::REFUS_MAGASINS);
        }

        $telechargement = DB::transaction(function () use ($auteur, $societe, $magasins, $du, $au, $types, $origine, $format, $selection, $pieceManquanteSeulement): TelechargementDeDocuments {
            Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
            $compte = User::query()->whereKey($auteur->getKey())->lock('for no key update')->firstOrFail();
            $documents = app(DocumentsDeLaPeriode::class);

            if (! $compte->peutAgir() || $magasins->contains(fn (Magasin $magasin): bool => ! $documents->peutTelechargerDans($compte, $magasin))) {
                throw new AuthorizationException(self::REFUS_MAGASINS);
            }

            if (TelechargementDeDocuments::demandesDepuis($compte, now()->subHour())->count() >= TelechargementDeDocuments::PAR_HEURE_AU_PLUS) {
                throw new LogicException(self::REFUS_PAR_HEURE);
            }

            return $this->contexte->runAsSociete($societe, function () use ($compte, $magasins, $du, $au, $types, $origine, $format, $selection, $pieceManquanteSeulement): TelechargementDeDocuments {
                $telechargement = (new TelechargementDeDocuments)->forceFill([
                    'demande_par' => $compte->getKey(),
                    'magasins' => $magasins->map(fn (Magasin $magasin): int => CleDuModele::de($magasin))->all(),
                    'du' => $du,
                    'au' => $au,
                    'types' => array_map(fn (TypeDeDocumentTelechargeable $type): string => $type->value, $types),
                    'origine' => $origine,
                    'piece_manquante_seulement' => $pieceManquanteSeulement,
                    'format' => $format,
                    'selection' => $selection,
                    'statut' => StatutDuTelechargement::EnPreparation,
                    'expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::days(TelechargementDeDocuments::JOURS_DE_VALIDITE)),
                ]);
                $telechargement->save();

                return $telechargement;
            });
        });

        PreparerLeTelechargementDesDocumentsJob::dispatch(CleDuModele::de($telechargement));

        return $telechargement;
    }
}
