<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\NicValide;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Integrations\Sirene\EtablissementSirene;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Le magasin d'une société validée déménage : il change de SIRET (même
 * SIREN, nouveau NIC) et garde tout le reste, son registre, ses séries,
 * ses documents, son stock, son équipe et son historique. Avant la
 * validation de la société, le NIC se corrige en modifiant le magasin
 * (voir ModifierEtablissementAction).
 *
 * Qui : le propriétaire, ou un administrateur autorisé à gérer les
 * magasins, pour un magasin réel de sa portée, tant que la société n'est
 * pas en lecture seule (voir MagasinDeLaSocietePolicy::changerLeSiret()),
 * en redonnant son mot de passe (voir MotDePasseRedemande) ; jamais pour
 * un magasin cédé.
 *
 * Contrôles, avant toute écriture : le NIC forme avec le SIREN de la
 * société un SIRET valide, différent du sien, qu'aucun autre magasin de la
 * société ne porte et qu'aucun magasin n'a réservé, même autrefois (voir
 * SiretsDesMagasins, dont le refus ne dit rien de qui l'a) ; le
 * répertoire Sirene, consulté tout de suite, doit confirmer un
 * établissement actif rattaché au même SIREN (voir ControleSirene) : un
 * établissement fermé, inconnu ou d'une autre entreprise est refusé, et,
 * l'INSEE injoignable, le changement attend (aucun « à vérifier » ici,
 * le magasin vendant déjà) ; un établissement que le logiciel ne gère pas,
 * ou dans un autre territoire que le magasin (voir Territoire), même au
 * même taux de TVA, est refusé, un tel déménagement passant par un nouveau
 * magasin ; le premier établissement confirmé d'un magasin qui n'avait
 * jamais été confirmé fixe son territoire.
 *
 * En une transaction, le registre du magasin verrouillé en tête puis sa
 * ligne : son SIRET actuel cesse de l'être au registre des SIRET réservés,
 * mais reste réservé et lisible sur les documents émis, qui l'ont signé ;
 * le nouveau se réserve et prend effet le jour même, avec l'adresse que
 * le répertoire donne de son établissement (voir
 * SiretsDesMagasins::remplacer()), le territoire se comparant à celui de
 * sa ligne relue ; le changement de SIRET, et la fixation de son
 * territoire, s'inscrivent à son journal des événements (JET 270, voir
 * JournaliseSesParametresDeConformite), le contrôle Sirene au registre des
 * vérifications et le changement au journal d'audit, avec les anciennes et
 * les nouvelles valeurs. Un SIRET pris entre-temps par un autre magasin
 * est refusé comme par la validation, sans rien changer.
 */
class ChangerSiretAction
{
    public const MESSAGE_SOCIETE_A_VERIFIER = 'Avant la validation de la société, le NIC d\'un magasin se corrige en modifiant le magasin.';

    public const MESSAGE_CEDE = 'Ce magasin a été cédé : son SIRET ne change plus.';

    public const MESSAGE_MEME_SIRET = 'C\'est déjà le SIRET de ce magasin.';

    public const MESSAGE_INSEE_INJOIGNABLE = 'Le répertoire Sirene de l\'INSEE ne répond pas pour le moment : un changement de SIRET exige son contrôle, réessayez plus tard.';

    public const MESSAGE_TERRITOIRE_CHANGE = 'Cet établissement est dans un autre territoire que le magasin : son taux de TVA, son heure et ses numéros en dépendent. Un déménagement vers un autre territoire passe par un nouveau magasin.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly SiretsDesMagasins $siretsDesMagasins,
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException quand l'auteur ne peut pas changer le SIRET de ce magasin
     * @throws LogicException quand le mot de passe, la société ou le magasin ne le permettent pas
     * @throws ValidationException quand le NIC est refusé, l'établissement Sirene compris
     */
    public function handle(User $auteur, Magasin $magasin, mixed $nic, #[SensitiveParameter] string $motDePasse): Magasin
    {
        $societe = $magasin->societe;
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new AuthorizationException(LecteurDesDroits::REFUS_DANS_LA_SOCIETE);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $nic, $motDePasse, $contexte): Magasin {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'changerLeSiret', $societe, $magasin);
            MotDePasseRedemande::exiger($auteur, $motDePasse);

            if (! $societe->estValidee()) {
                throw new LogicException(self::MESSAGE_SOCIETE_A_VERIFIER);
            }

            if ($magasin->aEteCede()) {
                throw new LogicException(self::MESSAGE_CEDE);
            }

            $siret = $societe->siren.$this->valider($societe, $magasin, $nic);
            $consultation = $this->controleSirene->etablissement($siret, (string) $societe->siren);
            $etablissement = $consultation['etablissement'];

            if ($consultation['resultat'] !== ResultatVerification::Concluante || $etablissement === null) {
                throw ValidationException::withMessages(['nic' => $consultation['motif'] ?? self::MESSAGE_INSEE_INJOIGNABLE]);
            }

            $territoire = ControleSirene::territoireDe($etablissement);

            if ($territoire['motif'] !== null) {
                throw ValidationException::withMessages(['nic' => $territoire['motif']]);
            }

            try {
                return $contexte->runAs($magasin, fn (): Magasin => $this->operateur->pendant($auteur, fn (): Magasin => $this->registre->sousVerrou($magasin, fn (): Magasin => $this->changer($auteur, $magasin, $siret, $consultation, $etablissement, $territoire['territoire']))));
            } catch (SiretDejaReserveException) {
                throw ValidationException::withMessages(['nic' => SiretsDesMagasins::MESSAGE_SIRET_RESERVE]);
            }
        });
    }

    /**
     * Le changement lui-même, sous le verrou du registre du magasin actif.
     *
     * @param  array{resultat: ResultatVerification, etablissement: EtablissementSirene|null, motif: string|null}  $consultation
     *
     * @throws LogicException
     * @throws SiretDejaReserveException
     * @throws ValidationException quand l'établissement est dans un autre territoire que le magasin
     */
    private function changer(User $auteur, Magasin $magasin, string $siret, array $consultation, EtablissementSirene $etablissement, Territoire $territoire): Magasin
    {
        $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

        if ($verrouille->aEteCede()) {
            throw new LogicException(self::MESSAGE_CEDE);
        }

        if ($verrouille->territoire !== null && $verrouille->territoire !== $territoire) {
            throw ValidationException::withMessages(['nic' => self::MESSAGE_TERRITOIRE_CHANGE]);
        }

        $avant = self::valeursAuditees($verrouille);
        $this->siretsDesMagasins->remplacer($verrouille, $siret, [
            ...ControleSirene::adresseDe($etablissement),
            'pays' => 'FR',
            'territoire' => $territoire,
            'etablissement_verifie_le' => now(),
        ]);
        $this->controleSirene->noterEtablissement($verrouille, $consultation, $auteur);
        $this->journalAudit->inscrireLesChangements(ActionAuditee::SiretChange, $auteur, $verrouille, $avant, self::valeursAuditees($verrouille), $verrouille);

        return $verrouille;
    }

    /**
     * Le NIC saisi, s'il forme avec le SIREN de la société un SIRET valide,
     * qui n'est ni le sien ni celui d'un autre de ses magasins, et
     * qu'aucun magasin n'a réservé.
     *
     * @throws ValidationException
     */
    private function valider(Societe $societe, Magasin $magasin, mixed $nic): string
    {
        return Validator::make(['nic' => $nic], [
            'nic' => [
                'required',
                'string',
                new NicValide,
                function (string $attribut, mixed $nic, Closure $echec) use ($societe, $magasin): void {
                    if (! is_string($nic)) {
                        return;
                    }

                    $siret = $societe->siren.$nic;
                    $refus = null;
                    (new SiretValide($societe->siren))->validate($attribut, $siret, function (string $message) use (&$refus): void {
                        $refus = $message;
                    });

                    $refus ??= match (true) {
                        $siret === $magasin->siret => self::MESSAGE_MEME_SIRET,
                        $societe->magasins()->where('siret', $siret)->exists(), SiretsDesMagasins::estReserve($siret) => SiretsDesMagasins::MESSAGE_SIRET_RESERVE,
                        default => null,
                    };

                    if ($refus !== null) {
                        $echec($refus);
                    }
                },
            ],
        ], attributes: ['nic' => 'NIC'])->validate()['nic'];
    }

    /**
     * Ce que le journal d'audit retient du changement (voir
     * ActionAuditee::champsAudites()).
     *
     * @return array<string, mixed>
     */
    private static function valeursAuditees(Magasin $magasin): array
    {
        return [
            'siret' => $magasin->siret,
            'adresse' => $magasin->adresse,
            'complement_adresse' => $magasin->complement_adresse,
            'code_postal' => $magasin->code_postal,
            'ville' => $magasin->ville,
            'territoire' => $magasin->territoire?->value,
            'etablissement_verifie' => $magasin->etablissement_verifie_le !== null,
        ];
    }
}
