<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorDeLaravel;
use LogicException;

/**
 * Enregistre l'identité légale de la société, celle que tous ses magasins
 * impriment (voir Emetteur) : raison sociale, forme juridique (et, pour
 * une autre forme, si la société est commerciale), capital social,
 * immatriculation, n° de TVA intracommunautaire, siège (ou adresse de
 * l'entreprise d'un entrepreneur individuel), et son assurance
 * professionnelle, facultative (assureur ou garant, son adresse, n° de
 * contrat, couverture géographique), qu'un cabinet d'expertise comptable,
 * sans magasin ni document de vente, n'a pas : ses champs ne s'écrivent
 * jamais pour lui. Le SIREN ne s'y saisit jamais : c'est celui de
 * l'inscription, que vérifie le courrier recommandé, et il ne change pas
 * (voir Societe).
 *
 * Rien de l'assurance n'est exigé, dans aucun type : seul un champ
 * renseigné sans l'assureur ou le garant est refusé, pour qu'aucune ligne
 * incomplète ne s'imprime (voir Emetteur::ligneDeLAssurance()).
 *
 * Les mentions facultatives de l'identité (décision P14.7) : la société en
 * liquidation, sa qualité de locataire-gérant ou de gérant-mandataire, et
 * l'EIRL, réservée à l'entrepreneur individuel, avec l'objet de son
 * activité, sans lequel elle est refusée ; comme l'assurance, un cabinet
 * d'expertise comptable, sans document de vente, ne les écrit jamais. Elles
 * s'impriment dans le pied des documents quand elles sont remplies (voir
 * Emetteur::mentionsFacultatives()).
 *
 * Le capital social s'exige selon la famille de la forme (décisions P14.1
 * et P14.3, art. R123-238 et L238-3 du Code de commerce, voir
 * FormeJuridique::exigeUnCapital()) : de la SARL, de l'EURL, de la SA, de
 * la SAS, de la SASU, de la SCA et de la SE, sauf si le capital est
 * variable, jamais de la SNC ni de la SCS ; un entrepreneur individuel n'en
 * a pas, et choisir cette forme efface celui d'avant (voir
 * Societe::retirerCeQueSaFormeExclut()) ; une autre forme dit si elle est
 * commerciale. Les mentions « EI » et « EIRL » sont réservées à
 * l'entrepreneur individuel : refusées dans une raison sociale saisie pour
 * une autre forme, sauf si c'est la dénomination même que donne le
 * répertoire Sirene (voir Societe::denominationSirene()) ; la raison
 * sociale d'un entrepreneur individuel contient son nom ou son nom d'usage
 * quand le répertoire les donne (décision P14.4, art. R526-27, voir
 * Societe::nomsDeLEntrepreneur()), ne porte pas « EI » pour un EIRL, et ne
 * porte « EIRL » qu'avec la case de l'EIRL et l'objet de son activité. Une
 * mention encore vide s'enregistre sans valeur : l'identité se complète peu
 * à peu, et les mentions qui manquent ne bloquent que les factures à un
 * professionnel (voir Societe::mentionsLegalesManquantes()).
 *
 * Réservé à qui règle les paramètres de la société (voir SocietePolicy).
 * Le n° de TVA touche la conformité de la caisse : l'enregistrement se
 * fait sous le verrou des registres de tous les magasins de la société,
 * qui reçoivent son changement à leur journal des événements (JET 270,
 * voir JournaliseSesParametresDeConformite). Ce qui change s'inscrit au
 * journal d'audit, avec les anciennes et les nouvelles valeurs.
 */
class ModifierIdentiteSocieteAction
{
    public const CHAMPS = [
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
        'assurance_assureur',
        'assurance_adresse',
        'assurance_contrat',
        'assurance_couverture',
        'en_liquidation',
        'exploitation_en_gerance',
        'eirl',
        'eirl_objet',
    ];

    /**
     * Les champs de l'assurance professionnelle, que seul un type qui a des
     * magasins écrit.
     */
    public const CHAMPS_DE_L_ASSURANCE = ['assurance_assureur', 'assurance_adresse', 'assurance_contrat', 'assurance_couverture'];

    /**
     * Les mentions facultatives de l'identité (liquidation, location-gérance,
     * EIRL et son objet), que seul un type qui a des magasins écrit : elles
     * s'impriment sur des documents de vente.
     */
    public const CHAMPS_DES_MENTIONS_FACULTATIVES = ['en_liquidation', 'exploitation_en_gerance', 'eirl', 'eirl_objet'];

    /**
     * Le plus grand capital social que garde sa colonne decimal(14,2),
     * écrit en texte comme toute borne d'un montant (voir
     * Caisse::MONTANT_MAXIMUM) : la règle « max: » qui la lit ne dépend pas
     * de l'écriture d'un nombre à virgule flottante par PHP.
     */
    public const CAPITAL_MAXIMUM = '999999999999.99';

    public const MESSAGE_CAPITAL_EXIGE = 'Le capital social est obligatoire pour cette société (art. R123-238 du Code de commerce), sauf si son capital est variable (art. L238-3) : saisissez-le, ou cochez « Capital variable ».';

    public const MESSAGE_CAPITAL_D_UN_ENTREPRENEUR = 'Un entrepreneur individuel n\'a pas de capital social.';

    public const MESSAGE_SOCIETE_COMMERCIALE = 'Dites si votre société est une société commerciale : son capital doit alors figurer sur ses factures, sauf s\'il est variable.';

    public const MESSAGE_MENTION_EI = 'Les mentions « EI » et « EIRL » sont réservées à un entrepreneur individuel : retirez-la de la raison sociale, ou choisissez cette forme juridique.';

    public const MESSAGE_EI_D_UN_EIRL = 'Pour un EIRL, la raison sociale ne porte pas « EI » : retirez-la, PILIQI imprime « EIRL ».';

    public const MESSAGE_EIRL_A_COCHER = 'La raison sociale porte « EIRL » : cochez « EIRL » et indiquez l\'objet de l\'activité (art. R123-237, 8° du Code de commerce), ou retirez la mention.';

    public const MESSAGE_EIRL_D_UNE_SOCIETE = 'Seul un entrepreneur individuel peut être un EIRL.';

    public const MESSAGE_OBJET_DE_L_EIRL = 'Indiquez l\'objet de l\'activité à laquelle le patrimoine de l\'EIRL est affecté (art. R123-237, 8° du Code de commerce).';

    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire de la société modifie ses paramètres, tant qu\'elle n\'est pas en lecture seule.';

    public const MESSAGE_ASSUREUR_EXIGE = 'Indiquez l\'assureur ou le garant.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  les champs de CHAMPS ; un champ absent ne change pas
     *
     * @throws LogicException quand l'auteur ne peut pas modifier les paramètres de cette société
     * @throws ValidationException quand une donnée est refusée
     */
    public function handle(User $auteur, Societe $societe, array $donnees): Societe
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException(self::MESSAGE_SANS_AUTORISATION);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $societe, $donnees): Societe {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'modifierLesParametres', $societe, $societe, new LogicException(self::MESSAGE_SANS_AUTORISATION));

            $valides = array_map(fn (mixed $valeur): mixed => is_string($valeur) && trim($valeur) === '' ? null : $valeur, $this->valider($societe, $donnees));

            return $this->operateur->pendant($auteur, fn (): Societe => $this->registre->sousVerrouDesMagasins($societe->magasins()->orderBy('id')->get(), function () use ($auteur, $societe, $valides): Societe {
                $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
                $avant = $enregistree->identiteAuditee();

                $enregistree->fill($valides)->save();

                $this->journalAudit->inscrireLesChangements(ActionAuditee::IdentiteModifiee, $auteur, null, $avant, $enregistree->identiteAuditee(), $enregistree);

                return $enregistree;
            }));
        });
    }

    /**
     * La saisie complète (les champs reçus sur l'identité enregistrée),
     * validée champ par champ, puis dans son ensemble : les mentions « EI »
     * et « EIRL », le nom de l'entrepreneur individuel dans sa raison
     * sociale, la réponse « société commerciale ? » d'une autre forme, le
     * capital que sa forme exige, l'objet de l'activité d'un EIRL, et aucun
     * capital saisi pour un entrepreneur individuel (celui d'avant s'efface
     * avec la forme).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function valider(Societe $societe, array $donnees): array
    {
        $champsEcrits = $societe->type_activite->aDesMagasins() ? self::CHAMPS : array_diff(self::CHAMPS, self::CHAMPS_DE_L_ASSURANCE, self::CHAMPS_DES_MENTIONS_FACULTATIVES);
        $recus = array_intersect_key($donnees, array_flip($champsEcrits));
        $saisie = [...array_map(JournalAudit::valeur(...), $societe->only(self::CHAMPS)), ...$recus];
        $forme = FormeJuridique::tryFrom(ValeurSimple::enTexte($saisie['forme_juridique'] ?? null));
        $commerciale = $saisie['societe_commerciale'] === null || $saisie['societe_commerciale'] === ''
            ? null
            : filter_var($saisie['societe_commerciale'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $validateur = Validator::make($saisie, [
            'raison_sociale' => ['nullable', 'string', 'max:200'],
            'forme_juridique' => ['nullable', Rule::enum(FormeJuridique::class)],
            'forme_juridique_autre' => ['nullable', 'string', 'max:100', Rule::requiredIf($forme === FormeJuridique::Autre)],
            'societe_commerciale' => ['nullable', 'boolean'],
            'capital_social' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::CAPITAL_MAXIMUM],
            'capital_variable' => ['boolean'],
            'immatriculation' => ['nullable', 'string', 'max:100'],
            'numero_tva' => ['nullable', 'string', 'max:20', new NumeroTvaAuFormat],
            'siege_adresse' => ['nullable', 'string', 'max:255'],
            'siege_complement_adresse' => ['nullable', 'string', 'max:255'],
            'siege_code_postal' => ['nullable', 'string', 'max:10'],
            'siege_ville' => ['nullable', 'string', 'max:100'],
            'assurance_assureur' => ['nullable', 'string', 'max:200'],
            'assurance_adresse' => ['nullable', 'string', 'max:300'],
            'assurance_contrat' => ['nullable', 'string', 'max:100'],
            'assurance_couverture' => ['nullable', 'string', 'max:200'],
            'en_liquidation' => ['boolean'],
            'exploitation_en_gerance' => ['nullable', Rule::enum(ExploitationEnGerance::class)],
            'eirl' => ['boolean'],
            'eirl_objet' => ['nullable', 'string', 'max:200'],
        ], [
            'capital_social.numeric' => 'Le capital social est un montant en euros.',
            'capital_social.decimal' => 'Le capital social se saisit au centime près.',
            'capital_social.min' => 'Le capital social ne peut pas être négatif.',
            'capital_social.max' => 'Le capital social ne peut pas dépasser 999 999 999 999,99 €.',
        ], [
            'raison_sociale' => 'raison sociale',
            'forme_juridique' => 'forme juridique',
            'forme_juridique_autre' => 'libellé de la forme juridique',
            'immatriculation' => 'immatriculation',
            'assurance_assureur' => 'assureur ou garant',
            'assurance_adresse' => 'adresse de l\'assureur ou du garant',
            'assurance_contrat' => 'n° de contrat',
            'assurance_couverture' => 'couverture géographique',
            'exploitation_en_gerance' => 'qualité d\'exploitant',
            'eirl_objet' => 'objet de l\'activité',
        ]);

        $validateur->after(function (ValidatorDeLaravel $validateur) use ($societe, $saisie, $recus, $forme, $commerciale): void {
            $raisonSociale = is_string($saisie['raison_sociale']) ? $saisie['raison_sociale'] : null;
            $capitalVariable = (bool) $saisie['capital_variable'];
            $eirl = (bool) $saisie['eirl'];

            if ($forme !== FormeJuridique::EntrepreneurIndividuel && $raisonSociale !== null && Societe::porteLaMentionEi($raisonSociale) && $raisonSociale !== $societe->denominationSirene()) {
                $validateur->errors()->add('raison_sociale', self::MESSAGE_MENTION_EI);
            }

            if ($forme === FormeJuridique::EntrepreneurIndividuel) {
                $noms = $societe->nomsDeLEntrepreneur();

                if (! Societe::denominationNommeLEntrepreneur($raisonSociale, $noms)) {
                    $validateur->errors()->add('raison_sociale', self::messageDuNomDeLEntrepreneur($noms));
                }

                if ($eirl && Societe::porteLaMentionEi($raisonSociale) && ! Societe::porteLaMentionEirl($raisonSociale)) {
                    $validateur->errors()->add('raison_sociale', self::MESSAGE_EI_D_UN_EIRL);
                }

                if ($eirl && blank($saisie['eirl_objet'])) {
                    $validateur->errors()->add('eirl_objet', self::MESSAGE_OBJET_DE_L_EIRL);
                }

                if (! $eirl && Societe::porteLaMentionEirl($raisonSociale)) {
                    $validateur->errors()->add('eirl', self::MESSAGE_EIRL_A_COCHER);
                }
            } elseif (filter_var($recus['eirl'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $validateur->errors()->add('eirl', self::MESSAGE_EIRL_D_UNE_SOCIETE);
            }

            if ($forme === FormeJuridique::Autre && $commerciale === null) {
                $validateur->errors()->add('societe_commerciale', self::MESSAGE_SOCIETE_COMMERCIALE);
            }

            if ($forme === FormeJuridique::EntrepreneurIndividuel && filled($recus['capital_social'] ?? null)) {
                $validateur->errors()->add('capital_social', self::MESSAGE_CAPITAL_D_UN_ENTREPRENEUR);
            } elseif ($forme?->exigeUnCapital($commerciale, $capitalVariable) === true && blank($saisie['capital_social'])) {
                $validateur->errors()->add('capital_social', self::MESSAGE_CAPITAL_EXIGE);
            }

            if (blank($saisie['assurance_assureur']) && collect(['assurance_adresse', 'assurance_contrat', 'assurance_couverture'])->contains(fn (string $champ): bool => filled($saisie[$champ]))) {
                $validateur->errors()->add('assurance_assureur', self::MESSAGE_ASSUREUR_EXIGE);
            }
        });

        return array_intersect_key($validateur->validate(), $recus);
    }

    /**
     * Le refus d'une raison sociale d'entrepreneur individuel qui ne
     * contient aucun des noms que le répertoire Sirene lui donne.
     *
     * @param  list<string>  $noms  voir Societe::nomsDeLEntrepreneur()
     */
    public static function messageDuNomDeLEntrepreneur(array $noms): string
    {
        return 'La raison sociale d\'un entrepreneur individuel contient son nom ou son nom d\'usage (art. R526-27 du Code de commerce) : le répertoire Sirene indique « '.implode(' » et « ', $noms).' ».';
    }
}
