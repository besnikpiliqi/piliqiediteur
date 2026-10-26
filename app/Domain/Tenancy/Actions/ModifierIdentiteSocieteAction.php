<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
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
 * Toute société commerciale indique son capital (art. R123-238 du Code de
 * commerce, voir FormeJuridique::exigeUnCapital()) ; un entrepreneur
 * individuel n'en a pas, et choisir cette forme efface celui d'avant (voir
 * Societe::retirerCeQueSaFormeExclut()) ; une autre forme dit si elle est
 * commerciale. La mention « EI » est réservée à l'entrepreneur
 * individuel : refusée dans une raison sociale saisie pour une autre
 * forme, sauf si c'est la dénomination même que donne le répertoire Sirene
 * (voir Societe::denominationSirene()). Une mention encore vide
 * s'enregistre sans valeur : l'identité se complète peu à peu, et les
 * mentions qui manquent ne bloquent que les factures à un professionnel
 * (voir Societe::mentionsLegalesManquantes()).
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
    ];

    /**
     * Les champs de l'assurance professionnelle, que seul un type qui a des
     * magasins écrit.
     */
    public const CHAMPS_DE_L_ASSURANCE = ['assurance_assureur', 'assurance_adresse', 'assurance_contrat', 'assurance_couverture'];

    public const CAPITAL_MAXIMUM = 999999999999.99;

    public const MESSAGE_CAPITAL_EXIGE = 'Le capital social est obligatoire pour une société commerciale (art. R123-238 du Code de commerce).';

    public const MESSAGE_CAPITAL_D_UN_ENTREPRENEUR = 'Un entrepreneur individuel n\'a pas de capital social.';

    public const MESSAGE_SOCIETE_COMMERCIALE = 'Dites si votre société est une société commerciale : son capital doit alors figurer sur ses factures.';

    public const MESSAGE_MENTION_EI = 'La mention « EI » est réservée à un entrepreneur individuel : retirez-la de la raison sociale, ou choisissez cette forme juridique.';

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
     * validée champ par champ, puis dans son ensemble : la mention « EI »,
     * la réponse « société commerciale ? » d'une autre forme, le capital
     * d'une société commerciale, et aucun capital saisi pour un
     * entrepreneur individuel (celui d'avant s'efface avec la forme).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function valider(Societe $societe, array $donnees): array
    {
        $recus = array_intersect_key($donnees, array_flip($societe->type_activite->aDesMagasins() ? self::CHAMPS : array_diff(self::CHAMPS, self::CHAMPS_DE_L_ASSURANCE)));
        $saisie = [...array_map(JournalAudit::valeur(...), $societe->only(self::CHAMPS)), ...$recus];
        $forme = FormeJuridique::tryFrom((string) ($saisie['forme_juridique'] ?? ''));
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
        ]);

        $validateur->after(function (ValidatorDeLaravel $validateur) use ($societe, $saisie, $recus, $forme, $commerciale): void {
            if ($forme !== FormeJuridique::EntrepreneurIndividuel && is_string($saisie['raison_sociale']) && Societe::porteLaMentionEi($saisie['raison_sociale']) && $saisie['raison_sociale'] !== $societe->denominationSirene()) {
                $validateur->errors()->add('raison_sociale', self::MESSAGE_MENTION_EI);
            }

            if ($forme === FormeJuridique::Autre && $commerciale === null) {
                $validateur->errors()->add('societe_commerciale', self::MESSAGE_SOCIETE_COMMERCIALE);
            }

            if ($forme === FormeJuridique::EntrepreneurIndividuel && filled($recus['capital_social'] ?? null)) {
                $validateur->errors()->add('capital_social', self::MESSAGE_CAPITAL_D_UN_ENTREPRENEUR);
            } elseif ($forme?->exigeUnCapital($commerciale) === true && blank($saisie['capital_social'])) {
                $validateur->errors()->add('capital_social', self::MESSAGE_CAPITAL_EXIGE);
            }

            if (blank($saisie['assurance_assureur']) && collect(['assurance_adresse', 'assurance_contrat', 'assurance_couverture'])->contains(fn (string $champ): bool => filled($saisie[$champ]))) {
                $validateur->errors()->add('assurance_assureur', self::MESSAGE_ASSUREUR_EXIGE);
            }
        });

        return array_intersect_key($validateur->validate(), $recus);
    }
}
