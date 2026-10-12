<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Rules\NicValide;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\MiseEnActiviteDesMagasins;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Point d'entrée unique pour la création d'un magasin, établissement d'une
 * société : à l'inscription de la société (voir CreerSocieteAction), puis
 * pour chacun des suivants.
 *
 * Contrôles, avant toute écriture :
 * - le propriétaire de la société, ou un administrateur de tous ses
 *   magasins autorisé à les gérer, dans une société qui n'est pas en
 *   lecture seule (voir SocietePolicy::creerUnMagasin()) ; jamais dans une
 *   autre société que celle déjà active ;
 * - le NIC, qui forme avec le SIREN de la société un SIRET valide, libre
 *   dans la société et, dans une société validée, qu'aucun magasin n'a
 *   réservé, même autrefois (voir SiretsDesMagasins) : le refus ne dit
 *   rien de qui l'a. Avant la validation, rien n'est réservé pour la
 *   société et rien ne lui dit qu'un SIRET l'est par une autre : la
 *   réservation se joue à la validation, et une inscription n'est jamais
 *   refusée parce qu'une autre société a réservé ce SIRET ; le code,
 *   libre dans la société (voir CodeMagasinLibre), ou proposé depuis le
 *   nom s'il n'est pas choisi ;
 * - le répertoire Sirene de l'INSEE, consulté tout de suite (voir
 *   ControleSirene) : un établissement fermé, inconnu ou rattaché à une
 *   autre entreprise est refusé, et rien n'est créé ; un établissement
 *   confirmé donne au magasin son adresse et le rend vérifié ; quand
 *   l'INSEE ne répond pas, le magasin est créé « établissement à
 *   vérifier », sans adresse, et ne vend pas avant la reprise du contrôle
 *   (voir app:reverifier-sirene) ;
 * - un établissement hors des territoires que le logiciel gère est
 *   refusé (voir TerritoireTva::estPrisEnCharge()).
 *
 * Puis, dans une transaction ouverte sous le verrou de la ligne de la
 * société (FOR NO KEY UPDATE sous PostgreSQL : il attend la validation,
 * qui la verrouille FOR UPDATE, et la fait attendre, sans bloquer les
 * écritures qui ne font que la référencer, comme les entrées du journal
 * d'audit) : le magasin, toujours dans cette société, quel que soit
 * l'identifiant de société d'une requête (il ne se lit jamais dans les
 * données reçues) ; son client comptoir ; le contrôle Sirene au registre
 * des vérifications ; sa création au journal d'audit. Aucune affectation
 * n'est créée. Dans une société validée, telle que la relit ce verrou (et non
 * telle qu'elle était au début de la requête, avant le contrôle Sirene),
 * le magasin se met aussitôt en activité : son SIRET se réserve, sa
 * première période d'activité s'ouvre, et les droits implicites du
 * propriétaire et des administrateurs de tous les magasins dans ce nouveau
 * magasin s'inscrivent à son journal des événements (voir
 * MiseEnActiviteDesMagasins) ; dans une société en cours
 * de vérification, tout cela se fait à sa validation (voir
 * ValiderLaSocieteAction), qui, sous le même verrou, compte ce magasin
 * dès qu'il existe.
 */
class CreerEtablissementAction
{
    public const MESSAGE_SANS_AUTORISATION = 'Seuls le propriétaire et un administrateur de tous les magasins autorisé à les gérer créent un magasin, tant que la société n\'est pas en lecture seule.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly MiseEnActiviteDesMagasins $miseEnActivite,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @param  array{nom?: mixed, code?: mixed, nic?: mixed, email?: mixed}  $donnees  les seules données lues : le nom, le code (facultatif), le NIC et l'adresse e-mail du magasin (facultative)
     *
     * @throws LogicException quand l'auteur ne peut pas créer de magasin dans cette société
     * @throws ValidationException quand une donnée est refusée, l'établissement Sirene compris
     */
    public function handle(User $auteur, Societe $societe, array $donnees): Magasin
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException(self::MESSAGE_SANS_AUTORISATION);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $societe, $donnees): Magasin {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'creerUnMagasin', $societe, $societe, new LogicException(self::MESSAGE_SANS_AUTORISATION));

            $valides = $this->valider($societe, $donnees);
            $siret = $societe->siren.$valides['nic'];
            $consultation = $this->controleSirene->etablissement($siret, $societe->siren);
            $etablissement = $consultation['etablissement'];

            if ($consultation['resultat'] === ResultatVerification::Negative) {
                throw ValidationException::withMessages(['nic' => $consultation['motif']]);
            }

            if ($etablissement !== null && ! ControleSirene::estDansUnTerritoireGere($etablissement)) {
                throw ValidationException::withMessages(['nic' => ControleSirene::MOTIF_TERRITOIRE_NON_GERE]);
            }

            return DB::transaction(function () use ($auteur, $societe, $valides, $siret, $consultation, $etablissement): Magasin {
                $verrouillee = Societe::query()->whereKey($societe->getKey())->lock('for no key update')->firstOrFail();
                $magasin = Magasin::forceCreate([
                    'societe_id' => $societe->getKey(),
                    'nom' => $valides['nom'],
                    'code' => filled($valides['code'] ?? null) ? $valides['code'] : null,
                    'email' => filled($valides['email'] ?? null) ? $valides['email'] : null,
                    'siret' => $siret,
                    'etablissement_verifie_le' => $etablissement === null ? null : now(),
                    ...($etablissement === null ? [] : ControleSirene::adresseDe($etablissement)),
                ]);

                $magasin->setRelation('societe', $verrouillee);

                app(TenantContext::class)->runAs($magasin, function () use ($auteur, $verrouillee, $magasin): void {
                    // Vente au comptoir (particulier anonyme, sans
                    // coordonnées saisies) : plutôt que de forcer un nom à
                    // chaque vente, un client générique réutilisable existe
                    // dès la création du magasin, reconnu à est_comptoir
                    // (voir Client::estComptoir()). Les clients particuliers
                    // nommés restent des fiches à part entière.
                    Client::forceCreate([
                        'nom' => Client::NOM_COMPTOIR,
                        'type_client' => TypeClient::Particulier,
                        'est_comptoir' => true,
                    ]);

                    if ($verrouillee->estValidee()) {
                        $this->miseEnActivite->mettreEnActivite($magasin, $auteur);
                    }
                });

                $this->controleSirene->noterEtablissement($magasin, $consultation, $auteur);
                $this->journalAudit->inscrire(ActionAuditee::MagasinCree, $auteur, $magasin, null, [
                    'nom' => $magasin->nom,
                    'code_magasin' => $magasin->code,
                    'siret' => $magasin->siret,
                    'adresse' => $magasin->adresse,
                    'complement_adresse' => $magasin->complement_adresse,
                    'code_postal' => $magasin->code_postal,
                    'ville' => $magasin->ville,
                    'pays' => $magasin->pays,
                    'etablissement_verifie' => $magasin->etablissement_verifie_le !== null,
                ], $magasin);

                return $magasin;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array{nom: string, code?: string|null, nic: string, email?: string|null}
     *
     * @throws ValidationException
     */
    private function valider(Societe $societe, array $donnees): array
    {
        return Validator::make($donnees, [
            'nom' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', new CodeMagasinLibre($societe)],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'nic' => [
                'required',
                'string',
                new NicValide,
                function (string $attribut, mixed $nic, Closure $echec) use ($societe): void {
                    if (! is_string($nic)) {
                        return;
                    }

                    $siret = $societe->siren.$nic;
                    $refus = null;
                    (new SiretValide($societe->siren))->validate($attribut, $siret, function (string $message) use (&$refus): void {
                        $refus = $message;
                    });

                    if ($refus === null && ($societe->magasins()->where('siret', $siret)->exists() || ($societe->estValidee() && SiretsDesMagasins::estReserve($siret)))) {
                        $refus = SiretsDesMagasins::MESSAGE_SIRET_RESERVE;
                    }

                    if ($refus !== null) {
                        $echec($refus);
                    }
                },
            ],
        ], attributes: ['nom' => 'nom du magasin', 'code' => 'code du magasin', 'nic' => 'NIC'])->validate();
    }
}
