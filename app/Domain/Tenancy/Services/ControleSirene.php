<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\SireneIndisponibleException;
use App\Integrations\Sirene\UniteSirene;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Les contrôles d'une société et de ses magasins au répertoire public
 * Sirene de l'INSEE (voir RepertoireSirene) : l'entreprise existe et n'est
 * pas cessée ; l'établissement d'un magasin existe, est actif et est
 * rattaché au SIREN de la société ; et la liste des établissements actifs
 * de l'entreprise, que propose la création d'un magasin (voir
 * etablissementsActifs()). Chaque consultation rend son résultat
 * (concluant, négatif avec son motif, ou impossible quand l'INSEE ne
 * répond pas) sans rien écrire ; noterUnite() et noterEtablissement()
 * l'inscrivent ensuite au registre des vérifications de la société (voir
 * VerificationSociete), avec les seuls détails que retient son type, et
 * au journal d'audit, catégorie des vérifications (voir
 * RegistreDesVerifications).
 *
 * Les motifs de refus ne disent que ce que le répertoire public dit
 * lui-même : jamais rien d'une autre société inscrite dans le logiciel.
 */
class ControleSirene
{
    public const FOURNISSEUR = 'insee';

    public const MOTIF_ENTREPRISE_INCONNUE = 'Ce SIREN est inconnu du répertoire Sirene de l\'INSEE : vérifiez-le.';

    public const MOTIF_ENTREPRISE_CESSEE = 'Cette entreprise est cessée au répertoire Sirene de l\'INSEE.';

    public const MOTIF_ETABLISSEMENT_INCONNU = 'Ce SIRET est inconnu du répertoire Sirene de l\'INSEE : vérifiez le NIC.';

    public const MOTIF_ETABLISSEMENT_FERME = 'Cet établissement est fermé au répertoire Sirene de l\'INSEE.';

    public const MOTIF_AUTRE_ENTREPRISE = 'Cet établissement n\'est pas rattaché à cette entreprise au répertoire Sirene de l\'INSEE.';

    /**
     * Les champs de l'identité d'une société que le répertoire donne de son
     * entreprise (voir identiteDepuisSirene()), hors le n° de TVA, que
     * forme son SIREN.
     */
    public const CHAMPS_DU_REPERTOIRE = [
        'raison_sociale',
        'categorie_juridique_insee',
        'forme_juridique',
        'siege_adresse',
        'siege_complement_adresse',
        'siege_code_postal',
        'siege_ville',
    ];

    public const MOTIF_TERRITOIRE_NON_GERE = 'Le logiciel ne gère pas encore les magasins situés en Guyane, à Mayotte ou dans une collectivité d\'outre-mer.';

    public const MOTIF_TERRITOIRE_INCONNU = 'Le répertoire Sirene de l\'INSEE ne situe cet établissement dans aucune commune française : son taux de TVA et son heure ne peuvent pas être fixés.';

    public function __construct(
        private readonly RepertoireSirene $repertoire,
        private readonly RegistreDesVerifications $registre,
    ) {}

    /**
     * L'entreprise de ce SIREN, active au répertoire.
     *
     * @return array{resultat: ResultatVerification, unite: UniteSirene|null, motif: string|null}
     */
    public function unite(string $siren): array
    {
        try {
            $unite = $this->repertoire->unite($siren);
        } catch (SireneIndisponibleException) {
            return ['resultat' => ResultatVerification::Indisponible, 'unite' => null, 'motif' => null];
        }

        return match (true) {
            $unite === null => ['resultat' => ResultatVerification::Negative, 'unite' => null, 'motif' => self::MOTIF_ENTREPRISE_INCONNUE],
            ! $unite->active => ['resultat' => ResultatVerification::Negative, 'unite' => $unite, 'motif' => self::MOTIF_ENTREPRISE_CESSEE],
            default => ['resultat' => ResultatVerification::Concluante, 'unite' => $unite, 'motif' => null],
        };
    }

    /**
     * L'établissement de ce SIRET, actif au répertoire et rattaché à ce
     * SIREN.
     *
     * @return array{resultat: ResultatVerification, etablissement: EtablissementSirene|null, motif: string|null}
     */
    public function etablissement(string $siret, string $siren): array
    {
        try {
            $etablissement = $this->repertoire->etablissement($siret);
        } catch (SireneIndisponibleException) {
            return ['resultat' => ResultatVerification::Indisponible, 'etablissement' => null, 'motif' => null];
        }

        return match (true) {
            $etablissement === null => ['resultat' => ResultatVerification::Negative, 'etablissement' => null, 'motif' => self::MOTIF_ETABLISSEMENT_INCONNU],
            ! $etablissement->estRattacheA($siren) => ['resultat' => ResultatVerification::Negative, 'etablissement' => $etablissement, 'motif' => self::MOTIF_AUTRE_ENTREPRISE],
            ! $etablissement->actif => ['resultat' => ResultatVerification::Negative, 'etablissement' => $etablissement, 'motif' => self::MOTIF_ETABLISSEMENT_FERME],
            default => ['resultat' => ResultatVerification::Concluante, 'etablissement' => $etablissement, 'motif' => null],
        };
    }

    /**
     * Les établissements actifs de l'entreprise de ce SIREN, tels que le
     * répertoire les liste, pour proposer celui d'un nouveau magasin (voir
     * CreerMagasin) : une proposition, qui n'inscrit rien au registre des
     * vérifications ; l'établissement choisi se contrôle encore à la
     * création du magasin (voir CreerEtablissementAction). Quand l'INSEE ne
     * répond pas, aucun n'est proposé et le NIC se saisit.
     *
     * @return array{resultat: ResultatVerification, etablissements: list<EtablissementSirene>, complete: bool}
     */
    public function etablissementsActifs(string $siren): array
    {
        try {
            $liste = $this->repertoire->etablissementsActifs($siren);
        } catch (SireneIndisponibleException) {
            return ['resultat' => ResultatVerification::Indisponible, 'etablissements' => [], 'complete' => false];
        }

        return ['resultat' => ResultatVerification::Concluante, 'etablissements' => $liste->etablissements, 'complete' => $liste->complete];
    }

    /**
     * L'identité que le répertoire donne de l'entreprise, telle qu'elle
     * préremplit la société : sa dénomination, sa catégorie juridique et la
     * forme qu'elle donne (voir FormeJuridique::depuisCategorieInsee()),
     * l'adresse de son siège quand le répertoire la diffuse, et le n° de
     * TVA que forme son SIREN, que le propriétaire corrige si sa société en
     * a un autre (groupe TVA). Une consultation impossible du siège laisse
     * son adresse vide.
     *
     * @return array<string, mixed>
     */
    public function identiteDepuisSirene(UniteSirene $unite): array
    {
        $siretDuSiege = $unite->siretDuSiege();
        $siege = $siretDuSiege === null ? null : $this->etablissement($siretDuSiege, $unite->siren)['etablissement'];

        return [
            'raison_sociale' => $unite->denomination,
            'categorie_juridique_insee' => $unite->categorieJuridique,
            'forme_juridique' => $unite->categorieJuridique === null ? null : FormeJuridique::depuisCategorieInsee($unite->categorieJuridique),
            'numero_tva' => IdentifiantsInsee::numeroTvaPropose($unite->siren),
            ...($siege === null || $siege->diffusionPartielle ? [] : [
                'siege_adresse' => $siege->adresse,
                'siege_complement_adresse' => $siege->complementAdresse,
                'siege_code_postal' => $siege->codePostal,
                'siege_ville' => $siege->ville,
            ]),
        ];
    }

    /**
     * Inscrit le contrôle de l'entreprise au registre des vérifications de
     * la société, qui doit être la société active.
     *
     * @param  array{resultat: ResultatVerification, unite: UniteSirene|null, motif: string|null}  $consultation  ce qu'a rendu unite()
     * @param  User|null  $auteur  la personne dont l'action a demandé le contrôle ; null pour le système ou l'éditeur
     * @param  InterventionDeLEditeur|null  $editeur  l'intervention de l'éditeur qui a demandé le contrôle
     */
    public function noterUnite(Societe $societe, array $consultation, ?User $auteur = null, ?InterventionDeLEditeur $editeur = null): VerificationSociete
    {
        $unite = $consultation['unite'];

        return $this->noter($societe, null, TypeVerification::SireneUnite, $consultation['resultat'], $unite === null ? null : [
            'siren' => $unite->siren,
            'etat_administratif' => $unite->active ? 'active' : 'cessee',
            'categorie_juridique' => $unite->categorieJuridique,
            'denomination' => $unite->denomination,
            'diffusion_partielle' => $unite->diffusionPartielle,
        ], $auteur, $editeur);
    }

    /**
     * Inscrit le contrôle de l'établissement du magasin au registre des
     * vérifications de sa société, qui doit être la société active.
     *
     * Un contrôle négatif retient son motif, que montre la page de la
     * vérification tant que l'établissement attend sa confirmation (un
     * établissement fermé, ou d'un autre territoire que le magasin, voir
     * ReverificationSirene).
     *
     * @param  array{resultat: ResultatVerification, etablissement: EtablissementSirene|null, motif: string|null}  $consultation  ce qu'a rendu etablissement()
     * @param  User|null  $auteur  la personne dont l'action a demandé le contrôle ; null pour le système ou l'éditeur
     * @param  InterventionDeLEditeur|null  $editeur  l'intervention de l'éditeur qui a demandé le contrôle (voir CorrigerLeTerritoireAction)
     */
    public function noterEtablissement(Magasin $magasin, array $consultation, ?User $auteur = null, ?InterventionDeLEditeur $editeur = null): VerificationSociete
    {
        $etablissement = $consultation['etablissement'];

        return $this->noter($magasin->societe, $magasin, TypeVerification::SireneEtablissement, $consultation['resultat'], [
            'siret' => $magasin->siret,
            ...($etablissement === null ? [] : [
                'etat_administratif' => $etablissement->actif ? 'actif' : 'ferme',
                'rattache' => $etablissement->estRattacheA($magasin->societe->siren),
                'adresse' => self::adresseEnUneLigne($etablissement),
            ]),
            ...($consultation['resultat'] === ResultatVerification::Negative && $consultation['motif'] !== null ? ['motif' => $consultation['motif']] : []),
        ], $auteur, $editeur);
    }

    /**
     * Le répertoire a-t-il déjà confirmé l'établissement du magasin sous son
     * SIRET actuel ? Un contrôle concluant de ce SIRET pour ce magasin est
     * inscrit au registre des vérifications de sa société. Un établissement
     * redevenu « à vérifier » par une réouverture faite sans réponse de
     * l'INSEE l'a été ; un établissement créé, ou dont le NIC a été corrigé,
     * pendant une panne, jamais : seul ce dernier reprend son adresse du
     * répertoire à sa confirmation (voir RouvrirMagasinAction et
     * ReverificationSirene), l'adresse qu'une société a enregistrée après
     * une confirmation restant la sienne.
     */
    public static function etablissementDejaConfirme(Magasin $magasin): bool
    {
        return VerificationSociete::query()
            ->where('societe_id', $magasin->societe_id)
            ->where('magasin_id', $magasin->getKey())
            ->where('type', TypeVerification::SireneEtablissement->value)
            ->where('resultat', ResultatVerification::Concluante->value)
            ->where('details->siret', $magasin->siret)
            ->exists();
    }

    /**
     * L'adresse de l'établissement en une ligne, telle que le registre des
     * vérifications la retient : « 1 RUE DE LA REPUBLIQUE, 75001 PARIS ».
     */
    public static function adresseEnUneLigne(EtablissementSirene $etablissement): string
    {
        return implode(', ', array_filter([$etablissement->adresse, $etablissement->complementAdresse, trim("{$etablissement->codePostal} {$etablissement->ville}")], filled(...)));
    }

    /**
     * L'adresse du magasin n'est-elle plus celle que le répertoire donne de
     * son établissement ? Comparées sans la casse, les accents, la
     * ponctuation ni les espaces en trop.
     */
    public static function adresseDiffere(Magasin $magasin, EtablissementSirene $etablissement): bool
    {
        $enUneLigne = fn (string $adresse): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($adresse)))));
        $adresseDuMagasin = implode(', ', array_filter([$magasin->adresse, $magasin->complement_adresse, trim("{$magasin->code_postal} {$magasin->ville}")], filled(...)));

        return $enUneLigne($adresseDuMagasin) !== $enUneLigne(self::adresseEnUneLigne($etablissement));
    }

    /**
     * L'adresse de l'établissement telle que le magasin la reprend, sans ce
     * que le répertoire ne diffuse pas.
     *
     * @return array{adresse: string|null, complement_adresse: string|null, code_postal: string|null, ville: string|null}
     */
    public static function adresseDe(EtablissementSirene $etablissement): array
    {
        return [
            'adresse' => $etablissement->adresse,
            'complement_adresse' => $etablissement->complementAdresse,
            'code_postal' => $etablissement->codePostal,
            'ville' => $etablissement->ville,
        ];
    }

    /**
     * Le territoire de l'établissement, lu à sa commune, à défaut à son
     * code postal (voir Territoire::depuisLeRepertoire()), quand le
     * logiciel le gère ; sinon le motif du refus : la Guyane, Mayotte ou
     * une collectivité d'outre-mer (MOTIF_TERRITOIRE_NON_GERE), ou aucune
     * commune française, comme pour un établissement à l'étranger
     * (MOTIF_TERRITOIRE_INCONNU). Le magasin qui le reçoit le garde pour
     * toujours (voir Magasin) : son taux de TVA par défaut et son
     * calendrier en dépendent.
     *
     * @return array{territoire: Territoire, motif: null}|array{territoire: null, motif: string}
     */
    public static function territoireDe(EtablissementSirene $etablissement): array
    {
        $territoire = Territoire::depuisLeRepertoire($etablissement->codeCommune, $etablissement->codePostal);
        $horsDeFrance = $etablissement->codeCommune === null ? $etablissement->codePostal === null : str_starts_with($etablissement->codeCommune, '99');

        return match (true) {
            $horsDeFrance => ['territoire' => null, 'motif' => self::MOTIF_TERRITOIRE_INCONNU],
            $territoire === null, ! $territoire->estPrisEnCharge() => ['territoire' => null, 'motif' => self::MOTIF_TERRITOIRE_NON_GERE],
            default => ['territoire' => $territoire, 'motif' => null],
        };
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function noter(Societe $societe, ?Magasin $magasin, TypeVerification $type, ResultatVerification $resultat, ?array $details, ?User $auteur, ?InterventionDeLEditeur $editeur = null): VerificationSociete
    {
        return $this->registre->noter($societe, $type, $resultat, $details, $auteur, fournisseur: self::FOURNISSEUR, magasin: $magasin, editeur: $editeur);
    }
}
