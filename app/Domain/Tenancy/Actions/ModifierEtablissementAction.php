<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Rules\NicValide;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\EtablissementSirene;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Modifie un magasin de la société : son nom ; son adresse, sans changer
 * de territoire de TVA (son taux par défaut et ses règles de vente en
 * dépendent) ; son code, tant qu'il n'a numéroté aucun document (voir
 * Magasin::aNumeroteUnDocument()) ; son NIC, tant que la société n'est pas
 * validée, avec un nouveau contrôle Sirene synchrone (voir
 * CreerEtablissementAction) : une fois la société validée, le SIRET d'un
 * magasin ne change que par le registre des SIRET réservés.
 *
 * Réservé à qui gère les magasins de la société (voir SocietePolicy), dans
 * cette société. Tout se fait sous le verrou du registre du magasin, qui
 * reçoit un changement de SIRET à son journal des événements (JET 270), et
 * la ligne du magasin se relit verrouillée : un code devenu définitif
 * pendant la saisie n'est pas modifié, le reste l'est. Un code ou un
 * SIRET pris par un autre magasin de la société entre la validation et
 * l'enregistrement est refusé comme par la validation. La modification
 * s'inscrit au journal d'audit, avec les anciennes et les nouvelles
 * valeurs de ce qui a changé.
 */
class ModifierEtablissementAction
{
    public const MESSAGE_TERRITOIRE_CHANGE = 'Cette adresse change le territoire de TVA du magasin : son taux par défaut et ses règles de vente en dépendent. Elle ne peut pas être enregistrée.';

    public const MESSAGE_SIRET_SOCIETE_VALIDEE = 'Le SIRET d\'un magasin d\'une société validée ne change que par un changement de SIRET.';

    private const CHAMPS_DE_L_ADRESSE = ['adresse', 'complement_adresse', 'code_postal', 'ville', 'pays'];

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  les seules données lues : nom, code, adresse, complement_adresse, code_postal, ville, pays, nic ; une donnée absente ne change pas
     *
     * @throws LogicException quand l'auteur ne peut pas modifier ce magasin
     * @throws ValidationException quand une donnée est refusée
     */
    public function handle(User $auteur, Magasin $magasin, array $donnees): Magasin
    {
        $societe = $magasin->societe;
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException(CreerEtablissementAction::MESSAGE_SANS_AUTORISATION);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $donnees): Magasin {
            if (Gate::forUser($auteur)->denies('gererLesMagasins', $societe)) {
                throw new LogicException(CreerEtablissementAction::MESSAGE_SANS_AUTORISATION);
            }

            $valides = $this->valider($magasin, $donnees);
            $consultation = null;

            if (isset($valides['nic']) && $societe->siren.$valides['nic'] !== $magasin->siret) {
                if ($societe->estValidee()) {
                    throw new LogicException(self::MESSAGE_SIRET_SOCIETE_VALIDEE);
                }

                $consultation = $this->controleSirene->etablissement($societe->siren.$valides['nic'], $societe->siren);

                if ($consultation['resultat'] === ResultatVerification::Negative) {
                    throw ValidationException::withMessages(['nic' => $consultation['motif']]);
                }
            }

            try {
                return $this->registre->sousVerrouDesMagasins(new Collection([$magasin]), fn (): Magasin => $this->enregistrer($magasin, $valides, $consultation, $auteur));
            } catch (UniqueConstraintViolationException $doublon) {
                throw $this->refusDuDoublon($doublon);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $valides
     * @param  array{resultat: ResultatVerification, etablissement: EtablissementSirene|null, motif: string|null}|null  $consultation
     *
     * @throws ValidationException
     */
    private function enregistrer(Magasin $magasin, array $valides, ?array $consultation, User $auteur): Magasin
    {
        $enregistre = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();
        $avant = $this->valeursAuditees($enregistre);
        $territoire = $enregistre->territoireTva();

        if (isset($valides['code']) && $enregistre->aNumeroteUnDocument()) {
            unset($valides['code']);
        }

        $enregistre->fill(array_intersect_key($valides, array_flip(['nom', 'code', ...self::CHAMPS_DE_L_ADRESSE])));

        if ($consultation !== null) {
            $etablissement = $consultation['etablissement'];
            $enregistre->forceFill([
                'siret' => $enregistre->societe->siren.$valides['nic'],
                'etablissement_verifie_le' => $etablissement === null ? null : now(),
                ...($etablissement === null ? [] : [...ControleSirene::adresseDe($etablissement), 'pays' => 'FR']),
            ]);
        }

        if (array_intersect(self::CHAMPS_DE_L_ADRESSE, array_keys($enregistre->getDirty())) !== [] && $enregistre->territoireTva() !== $territoire) {
            throw ValidationException::withMessages(['code_postal' => self::MESSAGE_TERRITOIRE_CHANGE]);
        }

        $enregistre->save();

        if ($consultation !== null) {
            $this->controleSirene->noterEtablissement($enregistre, $consultation, $auteur);
        }

        $apres = $this->valeursAuditees($enregistre);
        $champsModifies = array_keys(array_diff_assoc(array_map(serialize(...), $apres), array_map(serialize(...), $avant)));

        if ($champsModifies !== []) {
            $this->journalAudit->inscrire(
                ActionAuditee::MagasinModifie,
                $enregistre,
                array_intersect_key($avant, array_flip($champsModifies)),
                array_intersect_key($apres, array_flip($champsModifies)),
                $enregistre,
            );
        }

        return $enregistre;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function valider(Magasin $magasin, array $donnees): array
    {
        $societe = $magasin->societe;

        return Validator::make($donnees, [
            'nom' => ['sometimes', 'required', 'string', 'max:150'],
            'code' => ['sometimes', 'required', 'string', new CodeMagasinLibre($societe, $magasin)],
            'adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'complement_adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code_postal' => ['sometimes', 'nullable', 'string', 'max:10'],
            'ville' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pays' => ['sometimes', 'required', 'string', 'size:2'],
            'nic' => [
                'sometimes',
                'required',
                'string',
                new NicValide,
                function (string $attribut, mixed $nic, Closure $echec) use ($magasin, $societe): void {
                    if (! is_string($nic) || $societe->siren.$nic === $magasin->siret) {
                        return;
                    }

                    $siret = $societe->siren.$nic;
                    $refus = null;
                    (new SiretValide($societe->siren))->validate($attribut, $siret, function (string $message) use (&$refus): void {
                        $refus = $message;
                    });

                    if ($refus === null && $societe->magasins()->where('siret', $siret)->whereKeyNot($magasin->getKey())->exists()) {
                        $refus = SiretsDesMagasins::MESSAGE_SIRET_RESERVE;
                    }

                    if ($refus !== null) {
                        $echec($refus);
                    }
                },
            ],
        ], attributes: ['nom' => 'nom du magasin', 'code' => 'code du magasin', 'nic' => 'NIC'])->validate();
    }

    /**
     * Ce que le journal d'audit retient du magasin (voir
     * ActionAuditee::champsAudites()).
     *
     * @return array<string, mixed>
     */
    private function valeursAuditees(Magasin $magasin): array
    {
        return [
            'nom' => $magasin->nom,
            'code_magasin' => $magasin->code,
            'siret' => $magasin->siret,
            'adresse' => $magasin->adresse,
            'complement_adresse' => $magasin->complement_adresse,
            'code_postal' => $magasin->code_postal,
            'ville' => $magasin->ville,
            'pays' => $magasin->pays,
            'etablissement_verifie' => $magasin->etablissement_verifie_le !== null,
        ];
    }

    /**
     * Un code ou un SIRET pris par un autre magasin de la société entre la
     * validation et l'enregistrement : le même refus que la validation. Le
     * message du pilote de la base, sans la requête, nomme l'index refusé
     * (magasins.siret, magasins_societe_id_code_unique...) ; un code
     * s'écrit en majuscules et un SIRET en chiffres : « siret » et « code »
     * en minuscules n'y désignent que leur index. Tout autre doublon n'est
     * attribué à aucun champ.
     */
    private function refusDuDoublon(UniqueConstraintViolationException $doublon): ValidationException|UniqueConstraintViolationException
    {
        $indexRefuse = (string) ($doublon->errorInfo[2] ?? '');

        return match (true) {
            str_contains($indexRefuse, 'siret') => ValidationException::withMessages(['nic' => SiretsDesMagasins::MESSAGE_SIRET_RESERVE]),
            str_contains($indexRefuse, 'code') => ValidationException::withMessages(['code' => CodeMagasinLibre::MESSAGE_DEJA_UTILISE]),
            default => $doublon,
        };
    }
}
