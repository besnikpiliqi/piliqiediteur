<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Concerns\JournaliseSesParametresDeConformite;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Facturation\Casts\NumeroTvaNormalise;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

/**
 * Le client de l'éditeur : une entreprise, société ou entrepreneur
 * individuel, identifiée par son SIREN, avec son propriétaire et ses
 * magasins (ses établissements, un SIRET chacun).
 *
 * Elle seule porte l'identité légale que ses magasins impriment (raison
 * sociale, forme juridique, capital, immatriculation, siège, n° de TVA,
 * voir Emetteur) et ses réglages fiscaux communs (régime de TVA, option
 * pour les débits, mois de fin d'exercice) : aucun magasin n'a de
 * privilège sur eux. Le régime, l'option, le mois de fin d'exercice, le
 * SIREN et le n° de TVA touchent la conformité de la caisse : chaque
 * changement s'inscrit au journal des événements de chacun de ses
 * magasins, fermés compris, sous le verrou de tous leurs registres (JET
 * 270, voir JournaliseSesParametresDeConformite).
 *
 * Un SIREN n'appartient qu'à une seule société dans tout le logiciel :
 * celle dont l'inscription attend le code de son courrier recommandé, ou
 * qui est validée (index unique partiel societes_siren_unique) ; une
 * inscription close (voir estClose()) ou une société révoquée le libère.
 *
 * Ses gardes, quel que soit l'écran ou la commande qui l'enregistre :
 * - jamais validée et close à la fois ;
 * - son SIREN, celui que vérifie le courrier, ne change jamais (une
 *   erreur se traite par la procédure exceptionnelle de l'éditeur), et son
 *   mois de fin d'exercice plus après la première vente de l'un de ses
 *   magasins (voir aVenduUneFois()) ;
 * - ce que sa forme juridique exclut ne s'enregistre pas (voir
 *   retirerCeQueSaFormeExclut()) ;
 * - elle ne s'efface jamais (voir forceDelete() et le déclencheur de la
 *   table societes).
 * La mention « EI » d'une autre forme n'est refusée qu'à la saisie (voir
 * ModifierIdentiteSocieteAction) : la dénomination que donne le répertoire
 * Sirene s'enregistre telle qu'elle est.
 *
 * Volontairement sans SocieteScope : une société est le tenant du panneau
 * de son administration. Toute requête qui en lit une autre que celle de
 * l'utilisateur passe par ses relations, ou filtre explicitement.
 */
class Societe extends Model implements HasName
{
    /** @use HasFactory<SocieteFactory> */
    use HasFactory, JournaliseSesParametresDeConformite;

    /**
     * Les champs de son identité légale, tels que le journal d'audit en
     * retient les changements (voir identiteAuditee()) : ceux que le
     * propriétaire saisit, et la catégorie juridique lue au répertoire
     * Sirene.
     */
    public const CHAMPS_DE_L_IDENTITE = [
        'raison_sociale',
        'forme_juridique',
        'forme_juridique_autre',
        'societe_commerciale',
        'categorie_juridique_insee',
        'capital_social',
        'capital_variable',
        'immatriculation',
        'numero_tva',
        'siege_adresse',
        'siege_complement_adresse',
        'siege_code_postal',
        'siege_ville',
    ];

    protected $fillable = [
        'raison_sociale',
        'forme_juridique',
        'forme_juridique_autre',
        'societe_commerciale',
        'capital_social',
        'capital_variable',
        'immatriculation',
        'numero_tva',
        'siege_adresse',
        'siege_complement_adresse',
        'siege_code_postal',
        'siege_ville',
        'regime_tva',
        'option_debits_tva',
        'mois_fin_exercice',
    ];

    protected $casts = [
        'forme_juridique' => FormeJuridique::class,
        'societe_commerciale' => 'boolean',
        'capital_social' => 'decimal:2',
        'capital_variable' => 'boolean',
        'numero_tva' => NumeroTvaNormalise::class,
        'regime_tva' => RegimeTva::class,
        'option_debits_tva' => 'boolean',
        'mois_fin_exercice' => 'integer',
        'statut' => StatutSociete::class,
        'validee_le' => Instant::class,
        'close_le' => Instant::class,
        'motif_cloture' => MotifCloture::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Societe $societe): void {
            $societe->uuid ??= (string) Str::uuid();
            $societe->statut ??= StatutSociete::Active;
        });

        static::saving(function (Societe $societe): void {
            if ($societe->validee_le !== null && $societe->close_le !== null) {
                throw new LogicException('Une société n\'est jamais à la fois validée et close.');
            }

            $societe->retirerCeQueSaFormeExclut();
        });

        static::updating(function (Societe $societe): void {
            if ($societe->isDirty('siren')) {
                throw new LogicException('Le SIREN d\'une société ne change jamais : une erreur se traite par la procédure exceptionnelle de l\'éditeur.');
            }

            if ($societe->isDirty('mois_fin_exercice') && $societe->aVenduUneFois()) {
                throw new LogicException('Le mois de fin d\'exercice est verrouillé depuis la première vente de l\'un de vos magasins : seul un changement légal de la date de clôture, par la procédure de l\'éditeur, le modifie.');
            }
        });
    }

    /**
     * La société d'un identifiant ou d'un uuid tapé par l'éditeur (voir
     * ProcedureDeLEditeur), null si elle n'existe pas. Un texte qui n'est
     * ni l'un ni l'autre ne désigne aucune société : la colonne uuid, typée
     * sous PostgreSQL, n'est alors pas interrogée.
     */
    public static function trouverParIdentifiantOuUuid(string $identifiant): ?self
    {
        return match (true) {
            ctype_digit($identifiant) => static::query()->find((int) $identifiant),
            Str::isUuid($identifiant) => static::query()->firstWhere('uuid', $identifiant),
            default => null,
        };
    }

    /**
     * La société d'un uuid tapé dans l'adresse du panneau de son
     * administration (/societe/{uuid}) : un texte qui n'est pas un uuid ne
     * désigne aucune société (404), sans interroger la colonne uuid, typée
     * sous PostgreSQL.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return mixed
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if (($field ?? $this->getRouteKeyName()) === 'uuid' && ! Str::isUuid((string) $value)) {
            throw (new ModelNotFoundException)->setModel(self::class, [$value]);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Une société ne s'efface jamais : ses documents se conservent dix ans
     * et son SIREN reste celui de ses documents émis. La base refuse elle
     * aussi sa suppression (voir DeclencheursDeProtection::sansSuppression()).
     *
     * @throws LogicException
     */
    public function delete(): never
    {
        throw new LogicException('Une société ne se supprime jamais : ses documents sont conservés.');
    }

    /**
     * Tous ses magasins, magasin de démonstration compris.
     *
     * @return HasMany<Magasin, $this>
     */
    public function magasins(): HasMany
    {
        return $this->hasMany(Magasin::class);
    }

    /**
     * Ses établissements, chacun un SIRET : tous ses magasins sauf celui de
     * démonstration.
     *
     * @return HasMany<Magasin, $this>
     */
    public function magasinsReels(): HasMany
    {
        return $this->hasMany(Magasin::class)->where('est_demonstration', false);
    }

    /**
     * Son magasin de démonstration, s'il a été ouvert (voir
     * OuvrirLaDemonstrationAction).
     *
     * @return HasOne<Magasin, $this>
     */
    public function demonstration(): HasOne
    {
        return $this->hasOne(Magasin::class)->where('est_demonstration', true);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function membres(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasOne<User, $this>
     */
    public function proprietaire(): HasOne
    {
        return $this->hasOne(User::class)->where('role_societe', RoleSociete::Proprietaire->value);
    }

    /**
     * La société est-elle validée ? Posée par la seule validation de la
     * société, à la saisie du code de son courrier recommandé (voir
     * ValiderLaSocieteAction) : avant elle, aucune opération commerciale
     * réelle (voir Magasin::peutVendre()).
     */
    public function estValidee(): bool
    {
        return $this->validee_le !== null;
    }

    /**
     * Son inscription est-elle close sans avoir été validée (voir
     * CloreLInscriptionAction) ? Pour toujours : son SIREN est libéré.
     */
    public function estClose(): bool
    {
        return $this->close_le !== null;
    }

    /**
     * Son inscription attend-elle encore le code de son courrier : ni
     * validée, ni close, ni révoquée ?
     */
    public function attendSonCode(): bool
    {
        return ! $this->estValidee() && ! $this->estClose() && $this->statut !== StatutSociete::Revoquee;
    }

    /**
     * Ses magasins sont-ils tous en lecture seule ? Suspendue, résiliée ou
     * révoquée par l'éditeur, ou son inscription close : consultation et
     * téléchargements seulement, et aucun réglage ne se modifie.
     */
    public function estEnLectureSeule(): bool
    {
        return $this->statut->estEnLectureSeule() || $this->estClose();
    }

    /**
     * Ce SIREN est-il déjà celui d'une société qui le garde : une
     * inscription qui attend son code, ou une société validée, ni close ni
     * révoquée ? La seule lecture d'une autre société permise à
     * l'inscription : un booléen, jamais son identité ni son état.
     */
    public static function sirenPris(string $siren): bool
    {
        return static::query()
            ->where('siren', $siren)
            ->whereNull('close_le')
            ->where('statut', '<>', StatutSociete::Revoquee->value)
            ->exists();
    }

    /**
     * L'un de ses magasins réels a-t-il déjà vendu ? Un enregistrement de
     * la chaîne des ventes dans le registre fiscal de l'un d'eux : la
     * première vente de la société, qui verrouille son mois de fin
     * d'exercice ; les ventes du magasin de démonstration ne comptent pas.
     * Lecture hors du magasin courant, filtrée par les magasins réels de
     * cette société et limitée à l'existence d'un enregistrement.
     */
    public function aVenduUneFois(): bool
    {
        return EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->whereIn('magasin_id', $this->magasinsReels()->select('id'))
            ->where('chaine', ChaineFiscale::Vente->value)
            ->exists();
    }

    /**
     * La société vend-elle aujourd'hui en franchise en base de TVA (B10) ?
     * Un brouillon note la réponse quand il s'enregistre (voir
     * ContexteDeVente) ; l'émission la contrôle.
     */
    public function estEnFranchiseDeTva(): bool
    {
        return $this->regime_tva?->estFranchise() ?? false;
    }

    public function estEntrepreneurIndividuel(): bool
    {
        return $this->forme_juridique?->estEntrepreneurIndividuel() ?? false;
    }

    /**
     * Retire ce que sa forme juridique exclut : la réponse « société
     * commerciale ? », qui n'a de sens que pour une autre forme ; le
     * capital, le capital variable et le libellé de forme d'un entrepreneur
     * individuel, qui n'en a pas. Appelé à chaque enregistrement : une
     * forme changée par le propriétaire ou par le répertoire Sirene ne
     * laisse rien de la précédente.
     */
    public function retirerCeQueSaFormeExclut(): void
    {
        if ($this->forme_juridique !== FormeJuridique::Autre) {
            $this->societe_commerciale = null;
        }

        if ($this->estEntrepreneurIndividuel()) {
            $this->forceFill(['capital_social' => null, 'capital_variable' => false, 'forme_juridique_autre' => null]);
        }
    }

    /**
     * Son identité telle que le journal d'audit la retient (voir
     * JournalAudit::inscrireLesChangements()).
     *
     * @return array<string, mixed>
     */
    public function identiteAuditee(): array
    {
        return array_map(JournalAudit::valeur(...), $this->only(self::CHAMPS_DE_L_IDENTITE));
    }

    /**
     * La dénomination que le répertoire Sirene donne de son entreprise, à
     * son dernier contrôle concluant (voir ControleSirene::noterUnite()) ;
     * null quand aucun ne la donne (diffusion partielle). Lue dans le
     * registre des vérifications de la société active, et de cette société
     * seulement.
     */
    public function denominationSirene(): ?string
    {
        $details = VerificationSociete::query()
            ->where('societe_id', $this->getKey())
            ->where('type', TypeVerification::SireneUnite->value)
            ->where('resultat', ResultatVerification::Concluante->value)
            ->whereNull('magasin_id')
            ->orderByDesc('id')
            ->first()
            ?->details;

        return $details['denomination'] ?? null;
    }

    /**
     * La forme telle qu'une facture l'imprime : le libellé exact saisi
     * pour une autre forme ou une variante (SELARL...), sinon celui de la
     * forme ; aucun pour un entrepreneur individuel, dont la dénomination
     * porte déjà la mention « EI » (voir Emetteur::denominationImprimee()).
     */
    public function formeImprimee(): ?string
    {
        if ($this->forme_juridique === null || $this->estEntrepreneurIndividuel()) {
            return null;
        }

        return filled($this->forme_juridique_autre) ? $this->forme_juridique_autre : $this->forme_juridique->getLabel();
    }

    /**
     * Les mentions de son identité légale qui manquent encore pour émettre
     * une facture à un professionnel (S3, S4, S5, B11, K7), dans l'ordre de
     * l'identité : raison sociale, forme juridique (et, pour une autre
     * forme, si la société est commerciale), capital social pour toute
     * société commerciale, SIREN, immatriculation (registre, ou mention de
     * la dispense), siège ou adresse de l'entreprise, et n° de TVA
     * intracommunautaire, sauf en franchise en base (« le cas échéant »,
     * art. 242 nonies A de l'annexe II au CGI). Celles de l'établissement
     * s'y ajoutent (voir Magasin::mentionsLegalesManquantes()).
     *
     * @return list<string>
     */
    public function mentionsLegalesManquantes(): array
    {
        $capitalExige = $this->forme_juridique?->exigeUnCapital($this->societe_commerciale);
        $siege = $this->estEntrepreneurIndividuel() ? 'adresse de l\'entreprise' : 'adresse du siège';

        $mentions = [
            'raison sociale' => filled($this->raison_sociale),
            'forme juridique' => $this->forme_juridique !== null,
            'société commerciale ou non' => $this->forme_juridique === null || $capitalExige !== null,
            'capital social' => $capitalExige !== true || $this->capital_social !== null,
            'SIREN' => filled($this->siren),
            'immatriculation' => filled($this->immatriculation),
            $siege => filled($this->siege_adresse) && filled($this->siege_code_postal) && filled($this->siege_ville),
            'n° de TVA intracommunautaire' => $this->estEnFranchiseDeTva() || filled($this->numero_tva),
        ];

        return array_keys(array_filter($mentions, fn (bool $renseignee): bool => ! $renseignee));
    }

    /**
     * Les magasins dont le journal des événements note un changement de
     * ses réglages : ses magasins réels, fermés compris, par ordre de
     * création, dont les registres se verrouillent dans cet ordre (voir
     * JournaliseSesParametresDeConformite) ; le magasin de démonstration ne
     * reçoit pas les réglages de la société. Un réglage ne se modifie que
     * dans le contexte de cette société, jamais dans celui d'une autre.
     *
     * @return Collection<int, Magasin>
     *
     * @throws LogicException
     */
    protected function magasinsDuJournal(): Collection
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $this->getKey()) {
            throw new LogicException('Les réglages d\'une société ne se modifient que dans son propre contexte.');
        }

        return $this->magasinsReels()->orderBy('id')->get();
    }

    public static function parametresDeConformite(): array
    {
        return ['regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'numero_tva'];
    }

    /**
     * Une raison sociale porte-t-elle la mention de l'entrepreneur
     * individuel, en mot entier (« EI » ou « Entrepreneur individuel »),
     * quel que soit le signe qui l'entoure : « Jeanne Dupont EI »,
     * « Jeanne Dupont (EI) » ou « Jeanne Dupont EI. » la portent ;
     * « SEIGNEUR » ou « Peinture » non.
     */
    public static function porteLaMentionEi(?string $raisonSociale): bool
    {
        return $raisonSociale !== null && preg_match('/(?<![\p{L}\p{N}])(EI|Entrepreneur individuel)(?![\p{L}\p{N}])/iu', $raisonSociale) === 1;
    }

    public function getFilamentName(): string
    {
        return filled($this->raison_sociale) ? $this->raison_sociale : "SIREN {$this->siren}";
    }
}
