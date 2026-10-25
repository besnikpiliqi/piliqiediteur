<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Concerns\JournaliseSesParametresDeConformite;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\TerritoireTva;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Exceptions\IdentiteDeLaSocieteIncompleteException;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\CodeMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\MagasinFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Un établissement d'une société : son SIRET, son adresse, ses
 * coordonnées et ses paramètres locaux (barème, relances, échéance et
 * mentions des factures), avec sa caisse, ses stocks, ses ventes, ses
 * clients et son catalogue, qu'il ne partage avec aucun autre magasin
 * (BelongsToMagasin + MagasinScope), et son équipe : les comptes de la
 * société qui y sont affectés (voir Affectation). Il imprime l'identité légale
 * de sa société et en suit les réglages fiscaux (voir Societe et
 * Emetteur) ; aucun magasin n'a de privilège sur les autres.
 *
 * Volontairement PAS de BelongsToMagasin ici : un magasin ne s'appartient
 * pas à lui-même. La table n'a pas de scope global : dans l'espace de la
 * société, ses magasins se lisent par Societe::magasins().
 *
 * Son SIRET touche la conformité de sa caisse : chaque changement
 * s'inscrit à son journal des événements (JET 270, voir
 * JournaliseSesParametresDeConformite). Une fois sa société validée, il
 * ne change que par un déménagement (voir ChangerSiretAction), qui passe
 * par le registre des SIRET réservés (voir SiretsDesMagasins), et un
 * SIRET réservé ne sert jamais à un autre magasin.
 *
 * Son code court (voir CodeMagasin) figure dans tous les numéros de ses
 * documents : unique dans la société, proposé depuis son nom s'il n'est
 * pas choisi, il ne change plus dès son premier document (voir
 * aNumeroteUnDocument()).
 *
 * Ce qu'il fait encore, quel que soit l'écran ou la commande qui le
 * demande : en lecture seule (fermé, suspendu par l'éditeur, ou société
 * qui n'est plus active), il ne vend plus et n'écrit plus à ses clients
 * (voir estEnLectureSeule()) ; il ne vend qu'une fois sa société validée
 * et son établissement confirmé par le répertoire Sirene (voir
 * peutVendre()). Un magasin ne se supprime jamais (voir delete() et le
 * déclencheur de la table magasins) : sa société le ferme et le rouvre
 * (voir FermerMagasinAction et RouvrirMagasinAction), son motif de
 * fermeture disant pourquoi il est fermé (motif_fermeture), et un magasin
 * cédé, ouvert ou déjà fermé, ne se rouvre plus (voir aEteCede() et
 * booted()). La société peut indiquer l'un de
 * ses magasins comme son établissement principal, une simple information
 * (voir ChoisirEtablissementPrincipalAction).
 *
 * Le magasin de démonstration d'une société (est_demonstration, voir
 * OuvrirLaDemonstrationAction) est un magasin à part entière, avec son
 * registre, ses séries (code DEMO) et ses clôtures, sans SIRET ni identité
 * réelle (voir Emetteur::deDemonstration()) : il vend tant que sa société
 * n'est ni validée ni close, n'écrit à aucun client et ne transmet rien
 * (voir peutEcrireAuxClients()), et rien n'en passe aux magasins réels.
 */
class Magasin extends Model implements HasName
{
    /** @use HasFactory<MagasinFactory> */
    use HasFactory, JournaliseSesParametresDeConformite;

    /**
     * L'exemple proposé au magasin qui active les relances (Paramètres >
     * Relances) : il le modifie, le complète ou le réduit comme il veut.
     */
    public const DELAIS_RELANCE_PAR_DEFAUT = [7, 15, 30];

    /**
     * Les mentions des factures entre professionnels (art. L441-9 du Code
     * de commerce, B9) que chaque magasin reçoit à sa création et règle
     * ensuite (Paramètres > Factures) : pénalités de trois fois le taux
     * d'intérêt légal, indemnité forfaitaire de 40 €, pas d'escompte.
     */
    public const MENTION_PENALITES_PAR_DEFAUT = 'Pénalités de retard : trois fois le taux d\'intérêt légal, exigibles dès le lendemain de la date d\'échéance.';

    public const MENTION_INDEMNITE_PAR_DEFAUT = 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.';

    public const MENTION_ESCOMPTE_PAR_DEFAUT = 'Pas d\'escompte pour paiement anticipé.';

    /**
     * Le délai de paiement de ses factures, en jours après leur émission,
     * quand le client n'a pas le sien (B7).
     */
    public const DELAI_PAIEMENT_PAR_DEFAUT = 30;

    private const REFUS_SOCIETE_A_VERIFIER = 'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation.';

    private const REFUS_DEMONSTRATION_TERMINEE = 'Votre société est validée : la démonstration est terminée, vendez dans vos magasins.';

    private const REFUS_DEMONSTRATION_SANS_ENVOI = 'Le magasin de démonstration n\'écrit à aucun client et ne transmet rien.';

    protected $fillable = [
        'nom',
        'code',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'email',
        'telephone',
        'references_acheteur',
        'prix_vente_automatique',
        'bareme_prix_vente',
        'relances_automatiques',
        'delais_relance',
        'delai_paiement_jours',
        'mention_penalites_retard',
        'mention_indemnite_recouvrement',
        'mention_escompte',
    ];

    protected $casts = [
        'statut' => StatutMagasin::class,
        'motif_fermeture' => MotifFermeture::class,
        'est_demonstration' => 'boolean',
        'etablissement_verifie_le' => Instant::class,
        'suspendu_le' => Instant::class,
        'references_acheteur' => 'array',
        'est_etablissement_principal' => 'boolean',
        'prix_vente_automatique' => 'boolean',
        'bareme_prix_vente' => BaremePrixDeVente::class,
        'relances_automatiques' => 'boolean',
        'delai_paiement_jours' => 'integer',
    ];

    /**
     * Le magasin d'un identifiant ou d'un uuid tapé par l'éditeur (voir
     * caisse:verifier), null s'il
     * n'existe pas. Un texte qui n'est ni l'un ni l'autre ne désigne aucun
     * magasin : la colonne uuid, typée sous PostgreSQL, n'est alors pas
     * interrogée.
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
     * Le magasin de l'adresse du panneau (/app/{uuid}, voir
     * AppPanelProvider) : une adresse qui ne porte pas un uuid ne désigne
     * aucun magasin (404), sans interroger la colonne uuid, typée sous
     * PostgreSQL.
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

    protected static function booted(): void
    {
        static::creating(function (Magasin $magasin) {
            $magasin->uuid ??= (string) Str::uuid();
            $magasin->code ??= CodeMagasin::proposer($magasin->societe, $magasin->nom);
            $magasin->statut ??= StatutMagasin::Actif;
            $magasin->pays ??= 'FR';
            $magasin->est_etablissement_principal ??= false;
            $magasin->prix_vente_automatique ??= false;
            $magasin->bareme_prix_vente ??= BaremePrixDeVente::parDefaut();
            $magasin->relances_automatiques ??= false;
            $magasin->delais_relance ??= self::DELAIS_RELANCE_PAR_DEFAUT;
            $magasin->delai_paiement_jours ??= self::DELAI_PAIEMENT_PAR_DEFAUT;
            $magasin->mention_penalites_retard ??= self::MENTION_PENALITES_PAR_DEFAUT;
            $magasin->mention_indemnite_recouvrement ??= self::MENTION_INDEMNITE_PAR_DEFAUT;
            $magasin->mention_escompte ??= self::MENTION_ESCOMPTE_PAR_DEFAUT;
        });

        static::saving(function (Magasin $magasin) {
            if ((bool) $magasin->est_demonstration !== ($magasin->siret === null)) {
                throw new LogicException('Seul le magasin de démonstration n\'a pas de SIRET, et il n\'en a jamais.');
            }

            if (! $magasin->estFerme() && $magasin->motif_fermeture !== null) {
                throw new LogicException('Un magasin ouvert n\'a pas de motif de fermeture : sa réouverture l\'efface.');
            }
        });

        static::updating(function (Magasin $magasin) {
            if ($magasin->getOriginal('motif_fermeture') === MotifFermeture::Cession && $magasin->isDirty(['statut', 'motif_fermeture'])) {
                throw new LogicException('Un magasin cédé ne se rouvre jamais et reste cédé.');
            }

            if ($magasin->isDirty('est_demonstration')) {
                throw new LogicException('Un magasin réel ne devient jamais le magasin de démonstration, ni l\'inverse.');
            }

            if ($magasin->est_etablissement_principal && $magasin->est_demonstration) {
                throw new LogicException('Le magasin de démonstration n\'est jamais l\'établissement principal.');
            }

            if ($magasin->isDirty('code')) {
                $magasin->verifierQueSonCodePeutChanger();
            }

            if ($magasin->isDirty('siret')) {
                $magasin->verifierQueSonSiretEstReserve();
            }
        });
    }

    /**
     * A-t-il numéroté un document ? Dès qu'une série de numérotation de
     * document existe pour lui, la première s'étant créée avec son premier
     * numéro, code compris (voir DocumentNumberingService) : son code ne
     * change plus. Les références de produits (PRD-000001), qui ne portent
     * pas le code, ne comptent pas. sequences_documents n'a pas de
     * MagasinScope : le filtre par magasin est explicite.
     */
    public function aNumeroteUnDocument(): bool
    {
        return SequenceDocument::query()
            ->where('magasin_id', $this->getKey())
            ->where('type_document', '!=', TypeDocument::Produit->value)
            ->exists();
    }

    /**
     * A-t-il déjà vendu ? Un enregistrement de la chaîne des ventes dans
     * son registre fiscal (un ticket, une facture ou un avoir émis) : sa
     * première vente, après laquelle un inventaire d'ouverture ne se
     * saisit plus (voir ValiderInventaireOuvertureAction). Lu sous le
     * verrou de son registre, que toute vente prend en premier, il ne
     * laisse passer aucune vente en même temps. Le registre n'a que le
     * filtre par magasin explicite ci-dessous : la lecture vaut aussi hors
     * du magasin actif.
     */
    public function aVendu(): bool
    {
        return EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->where('magasin_id', $this->getKey())
            ->where('chaine', ChaineFiscale::Vente->value)
            ->exists();
    }

    /**
     * Refuse le changement d'un code définitif. La ligne du magasin se
     * verrouille d'abord : dans la transaction du changement (voir
     * ModifierEtablissementAction), une première série de numérotation qui
     * se crée en même temps, et relit le code sous un verrou partagé,
     * attend que le changement soit validé et prend le nouveau code ; ou
     * bien le changement attend la fin de la transaction qui a créé la
     * série, et la voit.
     *
     * @throws LogicException
     */
    private function verifierQueSonCodePeutChanger(): void
    {
        static::query()->whereKey($this->getKey())->lockForUpdate()->value($this->getKeyName());

        if ($this->aNumeroteUnDocument()) {
            throw new LogicException('Le code du magasin figure déjà dans les numéros de ses documents : il ne change plus.');
        }
    }

    /**
     * Une fois sa société validée, le SIRET d'un magasin ne change que par
     * le registre des SIRET réservés, qui réserve le nouveau avant de
     * l'écrire ici (voir SiretsDesMagasins) : tout autre changement est
     * refusé. Avant la validation, rien n'est réservé, et le SIRET se
     * corrige avec le contrôle du répertoire Sirene (voir
     * ModifierEtablissementAction).
     *
     * @throws LogicException
     */
    private function verifierQueSonSiretEstReserve(): void
    {
        if (! $this->societe()->first()?->estValidee()) {
            return;
        }

        $reserve = SiretMagasin::query()
            ->where('magasin_id', $this->getKey())
            ->where('siret', $this->siret)
            ->whereNull('retire_le')
            ->whereNull('libere_le')
            ->exists();

        if (! $reserve) {
            throw new LogicException('Le SIRET d\'un magasin d\'une société validée ne change que par le registre des SIRET réservés.');
        }
    }

    /**
     * Un magasin ne s'efface jamais de la base : ses documents se
     * conservent dix ans, même après sa fermeture ou la résiliation de sa
     * société, et son SIRET reste réservé. La base refuse elle aussi ce qui
     * ne passe pas par le modèle (voir
     * DeclencheursDeProtection::sansSuppression()).
     *
     * @throws LogicException
     */
    public function delete(): never
    {
        throw new LogicException('Un magasin ne se supprime jamais : ses documents sont conservés et son SIRET reste réservé.');
    }

    /**
     * Est-il fermé par sa société (voir FermerMagasinAction), ou, pour le
     * magasin de démonstration, terminé ? Une suspension par l'éditeur
     * n'est pas une fermeture (voir estEnLectureSeule()).
     */
    public function estFerme(): bool
    {
        return $this->statut === StatutMagasin::Ferme;
    }

    /**
     * A-t-il été cédé ? Son motif de fermeture est la cession, qu'il ait été
     * fermé pour cession ou cédé une fois fermé (voir FermerMagasinAction) :
     * il ne se rouvre jamais et ne se modifie plus (voir booted()).
     */
    public function aEteCede(): bool
    {
        return $this->motif_fermeture === MotifFermeture::Cession;
    }

    /**
     * Est-ce le magasin de démonstration d'une société validée ou close,
     * fermé pour de bon : il ne se rouvre jamais, ses comptes d'entraînement
     * sont retirés (voir FermerLaDemonstrationAction), et plus aucun compte
     * n'y est créé, nommé ni réintégré (voir
     * PorteeDesDroits::peutDonnerLeRole()). Lu en base à chaque appel, sous
     * les verrous de qui l'appelle. Une démonstration fermée après trente
     * jours sans opération, qui se rouvre, ne l'est pas.
     */
    public function estUneDemonstrationTerminee(): bool
    {
        return $this->est_demonstration && Societe::query()
            ->whereKey($this->societe_id)
            ->where(fn ($societe) => $societe->whereNotNull('validee_le')->orWhereNotNull('close_le'))
            ->exists();
    }

    /**
     * Le magasin est-il en lecture seule ? Fermé par sa société, suspendu
     * par l'éditeur, ou parce que sa société n'est plus active ou que son
     * inscription est close (voir Societe::estEnLectureSeule()) : la
     * consultation et les
     * téléchargements restent possibles, plus aucune vente ni aucun e-mail
     * aux clients ; ses clôtures, les statuts de ses factures et ses
     * déclarations continuent. Seul endroit où ces états se lisent (voir
     * ControleDesDroits, RegistreFiscal, les envois et les relances).
     */
    public function estEnLectureSeule(): bool
    {
        return $this->motifDeLectureSeule() !== null;
    }

    /**
     * Ce qui le met en lecture seule, tel que l'écran le dit, le magasin
     * étant désigné ainsi (« Ce magasin est fermé », « Le magasin est en
     * lecture seule, votre société étant suspendue ») ; null quand il ne
     * l'est pas.
     */
    public function motifDeLectureSeule(string $designation = 'Ce magasin'): ?string
    {
        $societe = $this->societe;

        return match (true) {
            $this->estFerme() => "{$designation} est fermé",
            $this->suspendu_le !== null => "{$designation} est suspendu",
            $societe->estClose() => "{$designation} est en lecture seule, l'inscription de votre société étant close",
            $societe->statut->estEnLectureSeule() => "{$designation} est en lecture seule, votre société étant ".mb_strtolower($societe->statut->getLabel()),
            default => null,
        };
    }

    /**
     * Garde métier de ce que le magasin fait encore, quel que soit l'écran
     * ou la commande qui le demande : en lecture seule, il n'émet plus
     * rien, n'encaisse plus, ne valide plus d'achat et n'écrit plus à ses
     * clients (voir RegistreFiscal et les actions qui l'appellent). Ses
     * clôtures, les statuts de ses factures et ses déclarations, eux,
     * continuent.
     *
     * @throws LogicException
     */
    public function verifierQuIlEstOuvert(): void
    {
        $refus = $this->refusEnLectureSeule();

        if ($refus !== null) {
            throw new LogicException($refus);
        }
    }

    /**
     * Ce que l'écran dit d'un geste que la lecture seule refuse (voir
     * motifDeLectureSeule()) ; null quand le magasin n'est pas en lecture
     * seule.
     */
    public function refusEnLectureSeule(): ?string
    {
        $motif = $this->motifDeLectureSeule();

        return $motif === null ? null : "{$motif} : seules la consultation et les téléchargements restent possibles.";
    }

    /**
     * Le magasin peut-il vendre ? Hors lecture seule, un magasin réel une
     * fois sa société validée (voir Societe::estValidee()) et son
     * établissement confirmé par le répertoire Sirene
     * (etablissement_verifie_le), le magasin de démonstration tant que sa
     * société n'est ni validée ni close : ticket, facture, avoir,
     * paiement, devis numéroté, e-mail à un client et transmission en
     * dépendent (voir verifierQuIlPeutVendre() et peutEcrireAuxClients()).
     * Une revérification négative après la validation n'y change rien.
     */
    public function peutVendre(): bool
    {
        return $this->refusDeVente() === null;
    }

    /**
     * Pourquoi il ne peut pas vendre, tel que l'écran le dit ; null quand
     * il le peut.
     */
    public function refusDeVente(): ?string
    {
        $refusEnLectureSeule = $this->refusEnLectureSeule();

        return match (true) {
            $refusEnLectureSeule !== null => $refusEnLectureSeule,
            $this->est_demonstration => $this->societe->estValidee() ? self::REFUS_DEMONSTRATION_TERMINEE : null,
            ! $this->societe->estValidee() => self::REFUS_SOCIETE_A_VERIFIER,
            $this->etablissement_verifie_le === null => "L'établissement de ce magasin (SIRET {$this->siret}) n'est pas encore confirmé par le répertoire Sirene de l'INSEE : aucune vente avant sa confirmation.",
            default => null,
        };
    }

    /**
     * La porte de validation : appelée par le registre fiscal pour toute
     * vente et tout encaissement, juste après son verrou par l'émission
     * d'une facture, et par l'envoi d'un devis ou d'un document, un job
     * d'envoi, une relance (voir peutVendre()).
     *
     * @throws VenteNonAutoriseeException
     */
    public function verifierQuIlPeutVendre(): void
    {
        $refus = $this->refusDeVente();

        if ($refus !== null) {
            throw new VenteNonAutoriseeException($refus);
        }
    }

    /**
     * Le magasin écrit-il à ses clients et transmet-il à la plateforme ?
     * Seulement s'il peut vendre (voir peutVendre()), et jamais le magasin
     * de démonstration : ses documents n'ont aucune valeur.
     */
    public function peutEcrireAuxClients(): bool
    {
        return $this->refusDEcrireAuxClients() === null;
    }

    /**
     * Pourquoi il n'écrit pas à ses clients, tel que l'écran le dit ; null
     * quand il le peut.
     */
    public function refusDEcrireAuxClients(): ?string
    {
        return $this->refusDeVente() ?? ($this->est_demonstration ? self::REFUS_DEMONSTRATION_SANS_ENVOI : null);
    }

    /**
     * La garde des e-mails aux clients, des relances et des transmissions
     * (voir EnvoyerDocumentAction, EnvoyerDocumentJob et la transmission à
     * la plateforme).
     *
     * @throws VenteNonAutoriseeException
     */
    public function verifierQuIlPeutEcrireAuxClients(): void
    {
        $refus = $this->refusDEcrireAuxClients();

        if ($refus !== null) {
            throw new VenteNonAutoriseeException($refus);
        }
    }

    /**
     * Avant la validation de sa société, aucun PDF d'un magasin réel ne se
     * produit : un usurpateur ne fabrique pas un devis ou une facture au
     * nom d'une entreprise qui n'a rien demandé. Ceux du magasin de
     * démonstration, à l'identité fictive et marqués « Démonstration —
     * sans valeur », se produisent toujours. En lecture seule, les
     * téléchargements restent possibles.
     *
     * @throws VenteNonAutoriseeException
     */
    public function verifierQuIlPeutImprimer(): void
    {
        if (! $this->est_demonstration && ! $this->societe->estValidee()) {
            throw new VenteNonAutoriseeException(self::REFUS_SOCIETE_A_VERIFIER);
        }
    }

    /**
     * Le territoire de TVA du magasin, d'après son adresse (voir
     * TerritoireTva::depuisAdresse()) : null hors des territoires que le
     * logiciel connaît, que la création d'un magasin refuse (voir
     * CreerEtablissementAction).
     */
    public function territoireTva(): ?TerritoireTva
    {
        return TerritoireTva::depuisAdresse($this->pays, $this->code_postal);
    }

    /**
     * Le taux proposé pour une vente ou un nouveau produit, saisi ensuite
     * librement (B2) : le taux normal de son territoire, 20 % en métropole,
     * 8,5 % en Guadeloupe, en Martinique ou à La Réunion. Un magasin hors
     * des territoires connus, que sa création refuse, retombe sur celui de
     * la métropole.
     */
    public function tauxTvaParDefaut(): float
    {
        return ($this->territoireTva() ?? TerritoireTva::Metropole)->tauxNormal();
    }

    /**
     * Les mentions de l'identité légale qui manquent encore pour émettre
     * une facture à un professionnel : celles de sa société (voir
     * Societe::mentionsLegalesManquantes()), puis celles de
     * l'établissement : adresse, code postal et ville, que le répertoire
     * Sirene n'a pas encore données quand l'INSEE n'a pas répondu (voir
     * CreerEtablissementAction). Son SIRET, lui, ne manque jamais. Le
     * magasin de démonstration, qui imprime une identité fictive, n'en
     * attend aucune.
     *
     * @return list<string>
     */
    public function mentionsLegalesManquantes(): array
    {
        if ($this->est_demonstration) {
            return [];
        }

        $etablissement = [
            'adresse du magasin' => $this->adresse,
            'code postal du magasin' => $this->code_postal,
            'ville du magasin' => $this->ville,
        ];

        return [...$this->societe->mentionsLegalesManquantes(), ...array_keys(array_filter($etablissement, blank(...)))];
    }

    /**
     * Garde de l'émission d'une facture à un professionnel, ou de son
     * avoir (voir EmettreFactureAction et EmettreAvoirAction) : refusée
     * tant que l'identité légale de la société ou de l'établissement est
     * incomplète.
     *
     * @throws IdentiteDeLaSocieteIncompleteException
     */
    public function verifierSonIdentiteLegale(): void
    {
        $manquantes = $this->mentionsLegalesManquantes();

        if ($manquantes !== []) {
            throw new IdentiteDeLaSocieteIncompleteException($manquantes);
        }
    }

    /**
     * Le délai de paiement d'une facture de ce client, en jours après son
     * émission (B7) : le sien s'il en a un, sinon celui du magasin, qui
     * vaut aussi tant qu'aucun client n'est choisi (voir FactureResource).
     */
    public function delaiDePaiementPour(?Client $client): int
    {
        return $client?->delai_paiement_jours ?? $this->delai_paiement_jours;
    }

    /**
     * Les mentions qu'imprime une facture à un professionnel (B9), dans
     * l'ordre : pénalités de retard, indemnité forfaitaire pour frais de
     * recouvrement, escompte.
     *
     * @return array{penalites_retard: string, indemnite_recouvrement: string, escompte: string}
     */
    public function mentionsEntreProfessionnels(): array
    {
        return [
            'penalites_retard' => $this->mention_penalites_retard,
            'indemnite_recouvrement' => $this->mention_indemnite_recouvrement,
            'escompte' => $this->mention_escompte,
        ];
    }

    public static function parametresDeConformite(): array
    {
        return ['siret'];
    }

    /**
     * Son propre journal, et seulement dans le contexte de sa société.
     *
     * @return Collection<int, Magasin>
     *
     * @throws LogicException
     */
    protected function magasinsDuJournal(): Collection
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== (int) $this->societe_id) {
            throw new LogicException('Le SIRET d\'un magasin ne se modifie que dans le contexte de sa société.');
        }

        return new Collection([$this]);
    }

    /**
     * @return array{magasin: array{id: int, nom: string}}
     */
    protected function identiteDansLeJournal(): array
    {
        return ['magasin' => ['id' => $this->getKey(), 'nom' => $this->nom]];
    }

    /**
     * Le code s'enregistre toujours en majuscules, sans espace autour ;
     * laissé vide à la création, il est proposé depuis le nom (voir
     * booted()).
     *
     * @return Attribute<string, string|null>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $code): ?string => $code === null ? null : CodeMagasin::normaliser($code),
        );
    }

    /**
     * Le pays s'enregistre en majuscules, sans espace autour, comme celui
     * d'un client (voir Client::booted()) : « fr » saisi devient « FR ».
     *
     * @return Attribute<string, string|null>
     */
    protected function pays(): Attribute
    {
        return Attribute::make(
            set: fn (?string $pays): ?string => $pays === null ? null : Str::upper(trim($pays)),
        );
    }

    /**
     * Les délais de relance, en jours après l'échéance d'une facture :
     * toujours enregistrés dans l'ordre, sans doublon, la relance n°1 étant
     * celle du plus court (voir relanceDue()).
     *
     * @return Attribute<list<int>|null, list<int|string>>
     */
    protected function delaisRelance(): Attribute
    {
        return Attribute::make(
            get: fn (?string $valeur): ?array => $valeur === null ? null : json_decode($valeur, true, flags: JSON_THROW_ON_ERROR),
            set: function (array $delais): string {
                $jours = collect($delais)->map(fn (int|string $delai): int => (int) $delai)->unique()->sort()->values();

                if ($jours->contains(fn (int $delai): bool => $delai < 1)) {
                    throw new InvalidArgumentException('Une relance part au moins un jour après l\'échéance.');
                }

                return json_encode($jours->all(), JSON_THROW_ON_ERROR);
            },
        );
    }

    /**
     * La relance n°$niveau d'une facture échue depuis $joursDeRetard jours
     * est-elle due ? Les relances partent dans l'ordre, chacune à son délai
     * après l'échéance (avec 7, 15 et 30 jours : la n°2 pas avant 15 jours
     * de retard), et jamais plus près de la précédente que l'écart entre
     * leurs deux délais (8 jours entre la n°1 et la n°2) : une facture déjà
     * très en retard quand les relances commencent -- activées tard,
     * planificateur arrêté, relance en échec -- reçoit la n°1, puis les
     * suivantes au rythme choisi par le magasin, jamais toutes d'un coup.
     * Aucune au-delà du dernier délai.
     */
    public function relanceDue(int $niveau, int $joursDeRetard, ?int $joursDepuisLaPrecedente): bool
    {
        $delais = $this->delais_relance;

        if ($niveau < 1 || $niveau > count($delais) || $joursDeRetard < $delais[$niveau - 1]) {
            return false;
        }

        return $niveau === 1
            || $joursDepuisLaPrecedente === null
            || $joursDepuisLaPrecedente >= $delais[$niveau - 1] - $delais[$niveau - 2];
    }

    /**
     * Le barème avec lequel ses factures d'achat proposent les prix de
     * vente, une fois que le magasin a activé le calcul automatique
     * (désactivé au départ).
     */
    public function baremePrixDeVenteActif(): ?BaremePrixDeVente
    {
        return $this->prix_vente_automatique ? $this->bareme_prix_vente : null;
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /**
     * Ses périodes d'activité (voir PeriodesDActivite) : les tickets Z ne
     * se produisent que pour leurs jours.
     *
     * @return HasMany<PeriodeActivite, $this>
     */
    public function periodes(): HasMany
    {
        return $this->hasMany(PeriodeActivite::class);
    }

    /**
     * @return HasOne<PeriodeActivite, $this>
     */
    public function periodeOuverte(): HasOne
    {
        return $this->hasOne(PeriodeActivite::class)->whereNull('fin');
    }

    /**
     * clients(), fournisseurs() et services() servent aux créations que
     * Filament enregistre lui-même (CreateClient, CreateFournisseur et la
     * fenêtre « Nouveau » de ManageServices) : il y rattache la fiche au
     * magasin par la relation que nomme Resource::getTenantRelationshipName(),
     * le pluriel du modèle. Les créations qui surchargent
     * handleRecordCreation() ne s'en servent pas, et un produit ne se crée
     * jamais par un écran (voir OrigineDesProduits). L'isolation, elle, reste
     * BelongsToMagasin + MagasinScope.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function fournisseurs(): HasMany
    {
        return $this->hasMany(Fournisseur::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Les affectations des comptes de la société à ce magasin (voir
     * Affectation) : celles à tous les magasins n'y figurent pas.
     *
     * @return HasMany<Affectation, $this>
     */
    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class);
    }

    public function getFilamentName(): string
    {
        return $this->nom;
    }
}
