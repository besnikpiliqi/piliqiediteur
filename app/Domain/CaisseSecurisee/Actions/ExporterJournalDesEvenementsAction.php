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
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
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
 * (voir sansFormule()).
 */
class ExporterJournalDesEvenementsAction
{
    /**
     * Les colonnes du fichier, dans l'ordre.
     */
    public const COLONNES = [
        'Séquence',
        'Horodatage (heure de Paris)',
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

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * Inscrit l'export au journal, au nom de son auteur relu en base, qui
     * doit pouvoir consulter la caisse sécurisée du magasin actif (voir
     * LecteurDesDroits), puis rend ce qui écrit le fichier dans le flux
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
        $exportateur = $this->lecteur->exiger($auteur, PermissionRegistry::CAISSE_SECURISEE_CONSULTER, $magasin);

        $this->operateur->pendant($exportateur, fn () => DB::transaction(fn () => $this->registre->journaliser(CodeEvenement::ExportDeDonnees, [
            'export' => 'journal_des_evenements',
            'format' => 'csv',
            'du' => $du,
            'au' => $au,
        ])));

        $magasinId = $magasin->getKey();
        $avecLesTraces = $exportateur->estProprietaireDuMagasin($magasin);

        return static function ($flux) use ($magasinId, $du, $au, $avecLesTraces): void {
            fwrite($flux, "\u{FEFF}");
            self::ecrireUneLigne($flux, self::COLONNES);

            // Le fichier s'écrit après la réponse de l'action : les
            // requêtes sont filtrées par magasin elles-mêmes, sans dépendre
            // du magasin actif à ce moment-là.
            EnregistrementFiscal::query()
                ->withoutGlobalScope(MagasinScope::class)
                ->where('magasin_id', $magasinId)
                ->where('chaine', ChaineFiscale::Evenement->value)
                ->horodatesDuJourAuJour($du, $au)
                ->when($avecLesTraces, fn (Builder $evenements): Builder => $evenements->with(['trace' => fn (HasOne $trace) => $trace->withoutGlobalScope(MagasinScope::class)->where('magasin_id', $magasinId)]))
                ->orderBy('sequence')
                ->lazy(500)
                ->each(fn (EnregistrementFiscal $evenement) => self::ecrireUneLigne($flux, self::ligne($evenement, $avecLesTraces)));
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
    private static function ligne(EnregistrementFiscal $evenement, bool $avecLaTrace): array
    {
        $donnees = $evenement->contenuSigne()['donnees'] ?? [];
        $details = $donnees['details'] ?? [];
        $trace = $avecLaTrace ? $evenement->trace : null;

        $lecture = [
            (string) $evenement->sequence,
            Calendrier::deParis()->afficherDansUnJournal($evenement->horodatage, 'd/m/Y H:i:s'),
            (string) ($evenement->contenuSigne()['horodatage'] ?? ''),
            (string) ($donnees['code'] ?? $evenement->type),
            (string) ($donnees['libelle'] ?? ''),
            $evenement->libelleDeLOperateur(),
            $details === [] ? '' : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (string) $trace?->ip,
            (string) $trace?->agent_utilisateur,
            (string) $evenement->libelleDuDocument(),
        ];

        return [
            ...array_map(self::sansFormule(...), $lecture),
            (string) $evenement->signature_precedente,
            $evenement->signature,
            $evenement->algorithme,
            $evenement->cle_id,
            $evenement->version_module,
            $evenement->contenu,
        ];
    }

    /**
     * Une cellule qui commence par =, +, -, @, une tabulation ou un retour
     * chariot serait évaluée comme une formule par un tableur (injection de
     * formule, jusqu'à l'exfiltration du fichier) : elle est précédée d'une
     * apostrophe, qui la fait lire comme du texte. Seules les colonnes de
     * lecture passent par ici : celles de la vérification se relisent octet
     * pour octet, et aucune ne commence ainsi (une signature DER encodée en
     * base64url commence par « M », le contenu signé par « { »).
     */
    private static function sansFormule(string $valeur): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valeur) === 1 ? "'{$valeur}" : $valeur;
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
