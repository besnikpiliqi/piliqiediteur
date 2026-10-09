<?php

namespace App\Domain\CaisseSecurisee\Actions;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Exporte en CSV le journal des événements techniques (JET) du magasin
 * actif sur une période, du jour $du au jour $au à l'heure de Paris, et
 * inscrit d'abord cet export au journal (JET 110) : l'export d'une période
 * qui comprend aujourd'hui contient sa propre trace.
 *
 * Une ligne par événement, dans l'ordre de la chaîne : sa séquence, son
 * horodatage (à l'heure de Paris, puis tel qu'il est signé, en UTC), son
 * code et son libellé, son opérateur, ses détails, l'adresse IP et le
 * navigateur, le document qu'il référence, puis de quoi le vérifier hors
 * de l'application : la signature précédente, sa signature, l'algorithme,
 * la clé et la version du module, et le contenu exact qui est signé.
 *
 * Format : UTF-8 avec marque d'ordre des octets, séparateur
 * point-virgule, guillemets doublés (RFC 4180), pour s'ouvrir tel quel
 * dans un tableur français ; le contenu signé s'y relit octet pour octet.
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

    public function __construct(private readonly RegistreFiscal $registre) {}

    /**
     * Inscrit l'export au journal, puis rend ce qui écrit le fichier dans
     * le flux qu'on lui passe.
     *
     * @param  string  $du  AAAA-MM-JJ
     * @param  string  $au  AAAA-MM-JJ, pas avant $du
     * @return Closure(resource): void
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(string $du, string $au): Closure
    {
        if (self::jour($au)->lessThan(self::jour($du))) {
            throw new InvalidArgumentException('La période de l\'export se termine au plus tôt le jour où elle commence.');
        }

        DB::transaction(fn () => $this->registre->journaliser(CodeEvenement::ExportDeDonnees, [
            'export' => 'journal_des_evenements',
            'format' => 'csv',
            'du' => $du,
            'au' => $au,
        ]));

        $magasinId = app(TenantContext::class)->id();

        return static function ($flux) use ($magasinId, $du, $au): void {
            fwrite($flux, "\u{FEFF}");
            self::ecrireUneLigne($flux, self::COLONNES);

            // Le fichier s'écrit après la réponse de l'action : la requête
            // est filtrée par magasin elle-même, sans dépendre du magasin
            // actif à ce moment-là.
            EnregistrementFiscal::query()
                ->withoutGlobalScope(MagasinScope::class)
                ->where('magasin_id', $magasinId)
                ->where('chaine', ChaineFiscale::Evenement->value)
                ->horodatesDuJourAuJour($du, $au)
                ->orderBy('sequence')
                ->lazy(500)
                ->each(fn (EnregistrementFiscal $evenement) => self::ecrireUneLigne($flux, self::ligne($evenement)));
        };
    }

    private static function jour(string $date): CarbonImmutable
    {
        try {
            $jour = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            $jour = null;
        }

        if ($jour === null || $jour->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("« {$date} » n'est pas une date de période valide (AAAA-MM-JJ attendu).");
        }

        return $jour;
    }

    /**
     * @return list<string>
     */
    private static function ligne(EnregistrementFiscal $evenement): array
    {
        $donnees = $evenement->contenuSigne()['donnees'] ?? [];
        $details = $donnees['details'] ?? [];

        return [
            (string) $evenement->sequence,
            $evenement->horodatage->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s'),
            (string) ($evenement->contenuSigne()['horodatage'] ?? ''),
            (string) ($donnees['code'] ?? $evenement->type),
            (string) ($donnees['libelle'] ?? ''),
            $evenement->libelleDeLOperateur(),
            $details === [] ? '' : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (string) ($donnees['ip'] ?? ''),
            (string) ($donnees['agent_utilisateur'] ?? ''),
            (string) $evenement->libelleDuDocument(),
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
