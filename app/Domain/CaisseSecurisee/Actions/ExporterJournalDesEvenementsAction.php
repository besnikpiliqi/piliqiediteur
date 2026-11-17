<?php

namespace App\Domain\CaisseSecurisee\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Fichiers\CelluleDeTableur;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ResultatDePhp;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * Exporte en CSV le journal des événements techniques (JET) du magasin
 * actif sur une période, du jour $du au jour $au du calendrier, et
 * inscrit d'abord cet export au journal (JET 110) : l'export d'une période
 * qui comprend aujourd'hui contient sa propre trace.
 *
 * Une ligne par événement, dans l'ordre de la chaîne : sa séquence, son
 * horodatage (à l'heure du calendrier, avec la mention de l'heure d'été
 * ou d'hiver pendant l'heure qui se répète, puis tel qu'il est signé, en
 * UTC), son code et son libellé, son opérateur, ses détails, l'adresse IP
 * et le navigateur, le document qu'il référence, puis de quoi le vérifier
 * hors de l'application : la signature précédente, sa signature,
 * l'algorithme, la clé et la version du module, et le contenu exact qui
 * est signé.
 *
 * L'adresse IP et le navigateur ne sont pas signés : ils viennent de la
 * trace de l'événement (voir TraceEvenement), tant qu'elle n'est pas
 * effacée, et seulement quand le propriétaire du magasin exporte, seul à
 * pouvoir les lire ; ces deux colonnes restent vides sinon.
 *
 * Format : UTF-8 avec marque d'ordre des octets, séparateur
 * point-virgule, guillemets doublés (RFC 4180), pour s'ouvrir tel quel
 * dans un tableur français ; le contenu signé s'y relit octet pour octet.
 * Les colonnes de lecture reprennent des valeurs venues de l'utilisateur
 * (son nom, son navigateur) : aucune n'y est évaluée comme une formule
 * (voir CelluleDeTableur).
 */
class ExporterJournalDesEvenementsAction
{
    /**
     * Les colonnes du fichier, dans l'ordre ; la colonne lisible de
     * l'horodatage nomme l'heure du magasin (« Horodatage (heure de la
     * Martinique) », voir Calendrier::libelleDeLHeure()).
     *
     * @return list<string>
     */
    public static function colonnes(Calendrier $calendrier): array
    {
        return [
            'Séquence',
            "Horodatage ({$calendrier->libelleDeLHeure()})",
            'Horodatage signé (UTC)',
            'Code',
            'Libellé',
            'Opérateur',
            'Détails',
            'Adresse IP',
            'Navigateur',
            'Document',
            'Signature précédente',
            'Signature',
            'Algorithme',
            'Clé (cle_id)',
            'Version du module',
            'Contenu signé',
        ];
    }

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * Inscrit l'export au journal, au nom de son auteur relu en base sous le
     * verrou du registre du magasin actif, qui doit pouvoir y consulter la
     * caisse sécurisée (voir LecteurDesDroits) : un export qui croise un
     * changement de ses droits (la fin du lien d'un cabinet d'expertise
     * comptable) passe tout entier avant ou après lui ; puis rend ce qui écrit le fichier dans le flux
     * qu'on lui passe : avec l'adresse IP et le navigateur de chaque
     * événement pour le seul propriétaire de la société.
     *
     * @param  string  $du  AAAA-MM-JJ
     * @param  string  $au  AAAA-MM-JJ, pas avant $du
     * @return Closure(resource): void
     *
     * @throws AuthorizationException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, string $du, string $au): Closure
    {
        if (self::jour($au) < self::jour($du)) {
            throw new InvalidArgumentException('La période de l\'export se termine au plus tôt le jour où elle commence.');
        }

        $magasin = app(TenantContext::class)->magasin();
        $exportateur = $this->registre->sousVerrou($magasin, function () use ($auteur, $magasin, $du, $au): User {
            $exportateur = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::CAISSE_SECURISEE_CONSULTER, $magasin);

            $this->operateur->pendant($exportateur, fn () => $this->registre->journaliser(CodeEvenement::ExportDeDonnees, [
                'export' => 'journal_des_evenements',
                'format' => 'csv',
                'du' => $du,
                'au' => $au,
            ]));

            return $exportateur;
        });

        return self::ecrivain(CleDuModele::de($magasin), $magasin->calendrier(), $du, $au, $exportateur->estProprietaireDuMagasin($magasin));
    }

    /**
     * Ce qui écrit dans un flux le journal des événements de ce magasin
     * sur la période, au format de l'export, sans rien inscrire au
     * journal : handle() l'appelle après avoir inscrit l'export (JET 110),
     * et l'archive d'une copie du site de démonstration (lot DEMO-COPIE,
     * C.18), qui suit la sortie du mode d'entraînement (JET 105), y écrit
     * tout le journal de chaque magasin (sans borne), sans adresse IP ni
     * navigateur.
     *
     * @param  string|null  $du  AAAA-MM-JJ, null : depuis le premier événement
     * @param  string|null  $au  AAAA-MM-JJ, null : jusqu'au dernier
     * @return Closure(resource): void
     */
    public static function ecrivain(int $magasinId, Calendrier $calendrier, ?string $du, ?string $au, bool $avecLesTraces): Closure
    {
        return static function (mixed $flux) use ($magasinId, $calendrier, $du, $au, $avecLesTraces): void {
            $flux = ResultatDePhp::flux($flux, 'L\'ouverture du fichier de l\'export');
            fwrite($flux, "\u{FEFF}");
            self::ecrireUneLigne($flux, self::colonnes($calendrier));

            // Le fichier s'écrit après la réponse de l'action : les
            // requêtes sont filtrées par magasin elles-mêmes, sans dépendre
            // du magasin actif à ce moment-là.
            EnregistrementFiscal::query()
                ->withoutGlobalScope(MagasinScope::class)
                ->where('magasin_id', $magasinId)
                ->where('chaine', ChaineFiscale::Evenement->value)
                ->horodatesDuJourAuJour($calendrier, $du, $au)
                ->when($avecLesTraces, fn (Builder $evenements): Builder => $evenements->with(['trace' => fn (HasOne $trace) => $trace->withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasinId)]))
                ->orderBy('sequence')
                ->lazy(500)
                ->each(fn (EnregistrementFiscal $evenement) => self::ecrireUneLigne($flux, self::ligne($evenement, $calendrier, $avecLesTraces)));
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function jour(string $date): string
    {
        return Calendrier::jourValide($date)
            ?? throw new InvalidArgumentException("« {$date} » n'est pas une date de période valide (AAAA-MM-JJ attendu).");
    }

    /**
     * @param  bool  $avecLaTrace  vrai quand le propriétaire exporte : sa trace a été chargée
     * @return list<string>
     */
    private static function ligne(EnregistrementFiscal $evenement, Calendrier $calendrier, bool $avecLaTrace): array
    {
        $contenu = $evenement->contenuSigne();
        $details = ValeursLues::valeurAu($contenu, 'donnees', 'details') ?? [];
        $trace = $avecLaTrace ? $evenement->trace : null;

        $lecture = [
            (string) $evenement->sequence,
            $calendrier->afficherDansUnJournal($evenement->horodatage, 'd/m/Y H:i:s'),
            ValeurSimple::enTexte(ValeursLues::valeurAu($contenu, 'horodatage')),
            ValeurSimple::enTexte(ValeursLues::valeurAu($contenu, 'donnees', 'code') ?? $evenement->type),
            ValeurSimple::enTexte(ValeursLues::valeurAu($contenu, 'donnees', 'libelle')),
            $evenement->libelleDeLOperateur(),
            $details === [] ? '' : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (string) $trace?->ip,
            (string) $trace?->agent_utilisateur,
            (string) $evenement->libelleDuDocument(),
        ];

        return [
            ...array_map(CelluleDeTableur::texte(...), $lecture),
            (string) $evenement->signature_precedente,
            $evenement->signature,
            $evenement->algorithme,
            $evenement->cle_id,
            $evenement->version_module,
            $evenement->contenu,
        ];
    }

    /**
     * @param  resource  $flux
     * @param  list<string>  $valeurs
     */
    private static function ecrireUneLigne($flux, array $valeurs): void
    {
        fputcsv($flux, $valeurs, ';', '"', '');
    }
}
