<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\MiseEnActiviteDesMagasins;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Integrations\Sirene\EtablissementSirene;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use SensitiveParameter;

/**
 * La société rouvre l'un de ses magasins réels fermés temporairement ou
 * pour cessation d'activité, avant comme après sa validation ; un magasin
 * cédé ne se rouvre jamais (voir Magasin::aEteCede()).
 *
 * Qui : le propriétaire, ou un administrateur autorisé à gérer les
 * magasins, pour un magasin de sa portée, dans une société qui n'est pas
 * en lecture seule (voir MagasinDeLaSocietePolicy::rouvrirLeMagasin()),
 * en redonnant son mot de passe (voir MotDePasseRedemande). Jamais un
 * responsable ni un expert-comptable. La suspension décidée par l'éditeur
 * (suspendu_le) n'est jamais levée par une réouverture : un magasin
 * suspendu rouvert reste en lecture seule.
 *
 * Son établissement se contrôle d'abord au répertoire Sirene, avant toute
 * écriture (voir ControleSirene) : fermé, inconnu ou rattaché à une autre
 * entreprise, la réouverture est refusée, et rien ne change ; confirmé, le
 * magasin redevient vendable, une fois sa société validée ; quand l'INSEE
 * ne répond pas, il rouvre « établissement à vérifier » et ne vend pas
 * avant la reprise du contrôle (voir app:reverifier-sirene). L'adresse ne
 * se reprend du répertoire que pour un établissement qu'il n'avait jamais
 * confirmé sous ce SIRET (voir ControleSirene::etablissementDejaConfirme()),
 * à la réouverture comme à la reprise du contrôle (voir
 * ReverificationSirene) : celle qu'une société a enregistrée après une
 * confirmation reste la sienne, que l'INSEE réponde ou non à la
 * réouverture.
 *
 * En une transaction, le registre du magasin verrouillé en tête, puis la
 * ligne de sa société (FOR NO KEY UPDATE sous PostgreSQL, comme à la
 * création d'un magasin : elle attend la validation de la société, qui la
 * verrouille FOR UPDATE, et la fait attendre, comme son refus ou toute
 * autre écriture de sa ligne), relue pour refuser, comme la policy, une
 * société passée en lecture seule (refusée, suspendue...) pendant que le
 * répertoire répondait, puis la ligne du magasin, relue pour refuser un
 * magasin rouvert ou cédé entre-temps, ou dont le SIRET a changé depuis la
 * consultation du répertoire (son NIC, ou le SIREN de la société, corrigé
 * avant la validation pendant qu'il répondait, voir
 * ModifierEtablissementAction et CorrigerSirenAvantValidationAction) : le
 * contrôle de l'ancien SIRET ne vaut jamais pour le nouveau, et la
 * réouverture recommencée contrôle celui-ci. Rien ne s'écrit avant ces
 * refus. Puis son statut repasse à actif et son motif de fermeture
 * s'efface (JET 270
 * statut_magasin), le contrôle Sirene s'inscrit au registre des
 * vérifications, et la réouverture s'inscrit au journal d'audit. Dans une
 * société validée, telle que la relit ce verrou, une nouvelle période
 * d'activité s'ouvre (voir MiseEnActiviteDesMagasins, qui émet
 * MagasinRouvert une fois la transaction validée : son abonnement
 * reprend, étape 12 ; ou MagasinOuvert, avec son SIRET réservé et les
 * droits implicites au JET 130, pour un magasin fermé avant la validation
 * de sa société, qui n'en avait encore aucune). Avant la validation, le
 * magasin rouvert n'a ni période d'activité ni SIRET réservé, comme un
 * magasin créé alors : la validation, qui compte ses magasins actifs sous
 * le même verrou de la société, le met en activité avec eux (voir
 * ValiderLaSocieteAction) ; une validation faite pendant la réouverture
 * passe donc tout entière avant, et la réouverture le met en activité, ou
 * tout entière après, et la validation le compte.
 *
 * Sa caisse reprend le même registre, les mêmes chaînes, séquences, séries
 * et grands totaux, sans rien remettre à zéro : le premier ticket Z est
 * celui de son premier jour non clôturé, aucun n'étant produit pour ses
 * jours de fermeture (voir JourneesDeCaisse) ; rouvert le jour même de sa
 * fermeture, dont la journée est déjà clôturée, il ne vend qu'à partir du
 * lendemain. Ses caisses personnelles, fermées d'office à la fermeture,
 * repartent de zéro, et le stock resté en place redevient disponible.
 */
class RouvrirMagasinAction
{
    public const MESSAGE_PAS_FERME = 'Ce magasin n\'est pas fermé.';

    public const MESSAGE_CEDE = 'Ce magasin a été cédé : il ne se rouvre pas.';

    public const MESSAGE_CONTROLE_PERIME = 'Le SIRET du magasin a changé pendant son contrôle au répertoire Sirene : recommencez la réouverture, qui contrôlera le nouveau.';

    public function __construct(
        private readonly ControleSirene $controleSirene,
        private readonly RegistreFiscal $registre,
        private readonly MiseEnActiviteDesMagasins $miseEnActivite,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException quand l'auteur ne peut pas rouvrir ce magasin
     * @throws LogicException quand le mot de passe, l'état du magasin ou son établissement au répertoire Sirene ne le permettent pas
     */
    public function handle(User $auteur, Magasin $magasin, #[SensitiveParameter] string $motDePasse): Magasin
    {
        $societe = $magasin->societe;
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new AuthorizationException(LecteurDesDroits::REFUS_DANS_LA_SOCIETE);
        }

        return $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $motDePasse, $contexte): Magasin {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'rouvrirLeMagasin', $societe, $magasin);
            MotDePasseRedemande::exiger($auteur, $motDePasse);
            self::verifierQuIlSeRouvre($magasin);

            $siret = (string) $magasin->siret;
            $siren = (string) $societe->siren;
            $consultation = $this->controleSirene->etablissement($siret, $siren);
            $etablissement = $consultation['etablissement'];

            if ($consultation['resultat'] === ResultatVerification::Negative) {
                throw new LogicException((string) $consultation['motif']);
            }

            if ($etablissement !== null && ! ControleSirene::estDansUnTerritoireGere($etablissement)) {
                throw new LogicException(ControleSirene::MOTIF_TERRITOIRE_NON_GERE);
            }

            try {
                return $contexte->runAs($magasin, fn (): Magasin => $this->operateur->pendant($auteur, fn (): Magasin => $this->registre->sousVerrou($magasin, fn (): Magasin => $this->rouvrir($auteur, $magasin, $siret, $siren, $consultation))));
            } catch (SiretDejaReserveException) {
                throw new LogicException(SiretsDesMagasins::MESSAGE_SIRET_RESERVE);
            }
        });
    }

    /**
     * La réouverture elle-même, sous le verrou du registre du magasin
     * actif, avec la consultation du répertoire faite pour ce SIRET et ce
     * SIREN.
     *
     * @param  array{resultat: ResultatVerification, etablissement: EtablissementSirene|null, motif: string|null}  $consultation
     *
     * @throws AuthorizationException quand la société est passée en lecture seule entre-temps
     * @throws LogicException
     * @throws SiretDejaReserveException quand le SIRET d'un magasin fermé avant la validation a été réservé entre-temps par un autre
     */
    private function rouvrir(User $auteur, Magasin $magasin, string $siret, string $siren, array $consultation): Magasin
    {
        $societe = Societe::query()->whereKey($magasin->societe_id)->lock('for no key update')->firstOrFail();

        if ($societe->estEnLectureSeule()) {
            throw new AuthorizationException(LecteurDesDroits::REFUS_DANS_LA_SOCIETE);
        }

        $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();
        self::verifierQuIlSeRouvre($verrouille);

        if ($verrouille->siret !== $siret || $societe->siren !== $siren) {
            throw new LogicException(self::MESSAGE_CONTROLE_PERIME);
        }

        $verrouille->setRelation('societe', $societe);
        $etablissement = $consultation['etablissement'];
        $jamaisConfirme = ! ControleSirene::etablissementDejaConfirme($verrouille);
        $verrouille->forceFill([
            'statut' => StatutMagasin::Actif,
            'motif_fermeture' => null,
            'etablissement_verifie_le' => $etablissement === null ? null : now(),
            ...($etablissement !== null && $jamaisConfirme ? [...ControleSirene::adresseDe($etablissement), 'pays' => 'FR'] : []),
        ])->save();
        $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
            'parametre' => 'statut_magasin',
            'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
            'ancienne_valeur' => StatutMagasin::Ferme->value,
            'nouvelle_valeur' => StatutMagasin::Actif->value,
        ]);
        $this->controleSirene->noterEtablissement($verrouille, $consultation, $auteur);

        if ($societe->estValidee()) {
            $this->miseEnActivite->mettreEnActivite($verrouille, $auteur);
        }

        $this->journalAudit->inscrire(ActionAuditee::MagasinRouvert, $auteur, $verrouille, ['statut' => StatutMagasin::Ferme->value], [
            'statut' => StatutMagasin::Actif->value,
            'etablissement_verifie' => $verrouille->etablissement_verifie_le !== null,
        ], $verrouille);

        return $verrouille;
    }

    /**
     * Le magasin se rouvre-t-il ? Fermé, et pas cédé.
     *
     * @throws LogicException
     */
    private static function verifierQuIlSeRouvre(Magasin $magasin): void
    {
        if (! $magasin->estFerme()) {
            throw new LogicException(self::MESSAGE_PAS_FERME);
        }

        if ($magasin->aEteCede()) {
            throw new LogicException(self::MESSAGE_CEDE);
        }
    }
}
