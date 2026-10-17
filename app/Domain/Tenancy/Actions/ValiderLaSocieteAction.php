<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Events\SocieteValidee;
use App\Domain\Tenancy\Exceptions\ConflitDeValidationException;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Jobs\EnvoyerLaLettreDInformationJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\EtatDeVerification;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\MiseEnActiviteDesMagasins;
use App\Domain\Tenancy\Services\RapprochementDesDirigeants;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Identite\ResultatDIdentite;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Valide une société dès que sa vérification le permet (voir
 * EvaluateurDeVerification) : appelée après chaque vérification
 * concluante (identité, mandat, code d'un courrier, décision de
 * l'éditeur, établissement enfin confirmé), elle ne fait rien tant que la
 * société n'est pas validable.
 *
 * En une transaction, sous les verrous des registres de ses magasins réels
 * actifs puis de la ligne de la société, l'évaluation se refait, sur ces
 * magasins-là exactement : un magasin créé entre la lecture de leur liste
 * et le verrou de la société (voir CreerEtablissementAction, qui prend le
 * même verrou) fait tout reprendre avec la nouvelle liste ; puis la
 * société est validée (validee_le, seule écriture de cette colonne) et son
 * SIREN réservé (l'index unique partiel societes_siren_valide_unique
 * n'en retient qu'une par SIREN) ; chaque magasin réel actif se met en
 * activité (SIRET réservé, période ouverte, droits implicites du
 * propriétaire au JET 130, voir MiseEnActiviteDesMagasins) ; la qualité de
 * représentant du propriétaire est notée pour lui (qualite_verifiee_le),
 * seulement si la preuve est à son nom : l'identité vérifiée est celle que
 * nomme son compte (voir RapprochementDesDirigeants::nommeLeTitulaire()),
 * ou l'éditeur a examiné ses pièces. Une identité vérifiée au nom d'un
 * autre (la session relayée à un dirigeant) valide la société, mais ne
 * passe pas au titulaire du compte : signer les attestations ou recevoir
 * la propriété exigent une qualité prouvée à son propre nom. La
 * validation s'inscrit au journal d'audit, avec la personne vérifiée, sans
 * auteur : elle suit la vérification, et les journaux des événements de
 * ses magasins l'inscrivent au nom du système (voir OperateurCourant),
 * même quand la personne connectée l'a déclenchée en saisissant un code.
 * L'événement SocieteValidee part une fois la transaction validée, après
 * le MagasinOuvert de chaque magasin mis en activité (étape 12 : les
 * abonnements). Un magasin réel fermé n'est ni réservé ni ouvert : sa
 * réouverture le mettra en activité (voir RouvrirMagasinAction). Si une
 * autre société a validé ce SIREN, ou réservé l'un de ces SIRET,
 * entre-temps, rien n'est écrit : la société devient une réclamation
 * possible, et l'éditeur en est prévenu, sans rien en révéler à personne
 * d'autre.
 *
 * Aucune autre inscription du même SIREN n'est refusée : celles qui ont
 * déjà prouvé leur qualité de représentant voient leur propriétaire
 * prévenu qu'un examen de l'éditeur est nécessaire, sans rien apprendre de
 * cette société, et l'éditeur est alerté. Après la transaction : le
 * magasin de démonstration se ferme (un échec n'empêche rien : il ne vend
 * plus, la société étant validée, et la commande quotidienne le reprend),
 * la lettre d'information part au siège, et le propriétaire est prévenu.
 */
class ValiderLaSocieteAction
{
    /**
     * Combien de fois la validation reprend quand la liste des magasins
     * réels actifs change sous elle ; ce qu'elle rend alors.
     */
    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

    private const MAGASINS_CHANGES = 'magasins_changes';

    /**
     * Ce que lit le propriétaire dont la qualité n'est pas notée à son nom,
     * l'identité vérifiée (%s) étant celle d'un autre.
     */
    public const MESSAGE_QUALITE_D_UN_AUTRE = 'Votre qualité de représentant n\'est pas notée à votre nom : l\'identité vérifiée, celle de %s, n\'est pas celle que nomme votre compte. Signer les attestations de la caisse ou recevoir la propriété de la société exigera une qualité prouvée à votre propre nom.';

    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly RegistreFiscal $registre,
        private readonly MiseEnActiviteDesMagasins $miseEnActivite,
        private readonly JournalAudit $journalAudit,
        private readonly FermerLaDemonstrationAction $fermerLaDemonstration,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @return bool la société a-t-elle été validée par cet appel ?
     */
    public function siPossible(Societe $societe, ?InterventionDeLEditeur $editeur = null): bool
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $editeur): bool {
            $etat = $this->evaluateur->evaluer($societe);

            if ($etat->reclamationPossible()) {
                $this->signalerLaReclamation($societe);

                return false;
            }

            if (! $etat->estValidable()) {
                return false;
            }

            try {
                $validation = $this->operateur->pendantLeSysteme(fn (): ?array => $this->valider($societe, $editeur));
            } catch (ConflitDeValidationException) {
                $this->signalerLaReclamation($societe);

                return false;
            }

            if ($validation === null) {
                return false;
            }

            $this->apresLaValidation($societe->fresh(), $validation['proprietaire'], $validation['personne'], $validation['qualite_du_titulaire']);

            return true;
        });
    }

    /**
     * Valide la société sous les verrous, en reprenant tant qu'un magasin
     * réel actif apparaît ou disparaît entre la lecture de leur liste et le
     * verrou de la société, quelques fois au plus.
     *
     * @return array{etat: EtatDeVerification, proprietaire: User, personne: string, qualite_du_titulaire: bool}|null null quand la société n'est plus validable sous les verrous
     *
     * @throws ConflitDeValidationException quand, sous les verrous, une autre société a déjà validé ce SIREN ou réservé l'un de ces SIRET
     */
    private function valider(Societe $societe, ?InterventionDeLEditeur $editeur): ?array
    {
        for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
            $validation = $this->validerCesMagasins($societe, $societe->magasinsReels()->where('statut', StatutMagasin::Actif->value)->orderBy('id')->get(), $editeur);

            if ($validation !== self::MAGASINS_CHANGES) {
                return $validation;
            }
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, Magasin>  $magasins  les magasins réels actifs lus avant les verrous
     * @return array{etat: EtatDeVerification, proprietaire: User, personne: string, qualite_du_titulaire: bool}|string|null MAGASINS_CHANGES quand la liste a changé avant le verrou de la société
     *
     * @throws ConflitDeValidationException
     */
    private function validerCesMagasins(Societe $societe, EloquentCollection $magasins, ?InterventionDeLEditeur $editeur): array|string|null
    {
        return $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $magasins, $editeur): array|string|null {
            $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
            $verrouilles = $magasins;
            $magasins = $enregistree->magasinsReels()->where('statut', StatutMagasin::Actif->value)->orderBy('id')->get();

            if (array_map(intval(...), $magasins->modelKeys()) !== array_map(intval(...), $verrouilles->modelKeys())) {
                return self::MAGASINS_CHANGES;
            }

            $etat = $this->evaluateur->evaluer($enregistree);

            if ($etat->reclamationPossible()) {
                throw new ConflitDeValidationException;
            }

            if (! $etat->estValidable()) {
                return null;
            }

            $proprietaire = User::query()->whereKey($enregistree->proprietaire()->value('id'))->lockForUpdate()->firstOrFail();

            try {
                DB::transaction(fn (): bool => $enregistree->forceFill(['validee_le' => now()])->save());
            } catch (UniqueConstraintViolationException) {
                throw new ConflitDeValidationException;
            }

            foreach ($magasins as $magasin) {
                try {
                    app(TenantContext::class)->runAs($magasin, fn () => $this->miseEnActivite->mettreEnActivite($magasin->setRelation('societe', $enregistree), null));
                } catch (SiretDejaReserveException) {
                    throw new ConflitDeValidationException;
                }
            }

            $personneVerifiee = $this->personneVerifiee($proprietaire, $etat);
            $qualiteDuTitulaire = $personneVerifiee === null || RapprochementDesDirigeants::nommeLeTitulaire($personneVerifiee, $proprietaire->name);

            if ($qualiteDuTitulaire) {
                $proprietaire->forceFill(['qualite_verifiee_le' => now()])->save();
            }

            $this->journalAudit->inscrire(ActionAuditee::SocieteValidee, null, null, null, [
                'preuve' => $etat->preuveDeQualite,
                'beneficiaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                'personne_verifiee' => $personneVerifiee?->nomComplet() ?? $proprietaire->name,
                'qualite_du_titulaire' => $qualiteDuTitulaire,
                'sirets' => $magasins->map(fn (Magasin $magasin): string => (string) $magasin->siret)->values()->all(),
            ], $enregistree, $editeur);
            SocieteValidee::dispatch($enregistree);

            return ['etat' => $etat, 'proprietaire' => $proprietaire, 'personne' => $personneVerifiee?->nomComplet() ?? $proprietaire->name, 'qualite_du_titulaire' => $qualiteDuTitulaire];
        });
    }

    private function apresLaValidation(Societe $societe, User $proprietaire, string $personneVerifiee, bool $qualiteDuTitulaire): void
    {
        $demonstration = $societe->demonstration()->where('statut', StatutMagasin::Actif->value)->first();

        if ($demonstration !== null) {
            try {
                $this->fermerLaDemonstration->handle($demonstration);
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }

        EnvoyerLaLettreDInformationJob::dispatch($societe->getKey(), $personneVerifiee)->afterCommit();

        $proprietaire->notify(new AvisDeVerification(
            $societe,
            'Votre société est validée',
            'La vente, les e-mails aux clients, les PDF et les transmissions sont ouverts dans vos magasins.'
                .($demonstration !== null ? ' Le magasin de démonstration est terminé et reste consultable.' : '')
                .($qualiteDuTitulaire ? '' : ' '.sprintf(self::MESSAGE_QUALITE_D_UN_AUTRE, $personneVerifiee)),
            AvisDeVerification::SUCCES,
        ));

        $this->prevenirLesInscriptionsConcurrentes($societe);
    }

    /**
     * La personne dont l'identité a été vérifiée pour la preuve retenue, telle
     * que la lettre d'information la nomme ; null pour des pièces examinées
     * par l'éditeur, qui nomment le titulaire du compte.
     */
    private function personneVerifiee(User $proprietaire, EtatDeVerification $etat): ?ResultatDIdentite
    {
        $type = match ($etat->preuveDeQualite) {
            EtatDeVerification::PREUVE_PLATEFORME => TypeVerification::PdpKyb,
            EtatDeVerification::PREUVE_PIECES_ET_COURRIER => null,
            default => TypeVerification::Identite,
        };
        $details = $type === null ? null : VerificationSociete::query()
            ->where('type', $type->value)
            ->where('resultat', ResultatVerification::Concluante->value)
            ->where('beneficiaire_id', $proprietaire->getKey())
            ->orderByDesc('id')
            ->value('details');
        $personne = is_array($details) ? ResultatDIdentite::depuisLesDetails($details) : null;

        return $personne !== null && $personne->nomComplet() !== '' ? $personne : null;
    }

    /**
     * Les autres sociétés du même SIREN qui ont déjà prouvé leur qualité de
     * représentant : leur propriétaire apprend qu'un examen de l'éditeur
     * est nécessaire, sans rien de cette société ; l'éditeur est alerté.
     * Seule lecture hors de la société : les identifiants de ces sociétés,
     * pour les prévenir.
     */
    private function prevenirLesInscriptionsConcurrentes(Societe $societe): void
    {
        $concurrentes = Societe::query()
            ->where('siren', $societe->siren)
            ->whereKeyNot($societe->getKey())
            ->whereNull('validee_le')
            ->whereNull('refusee_le')
            ->where('statut', '<>', StatutSociete::Revoquee->value)
            ->get()
            ->filter(fn (Societe $autre): bool => $this->evaluateur->evaluer($autre)->reclamationPossible());

        foreach ($concurrentes as $autre) {
            $autre->proprietaire?->notify(new AvisDeVerification(
                $autre,
                'Un examen de l\'éditeur est nécessaire',
                'L\'inscription de ce SIREN demande un examen par l\'éditeur, qui a été prévenu et vous contactera.',
            ));
            $this->signalerLaReclamation($autre);
        }
    }

    /**
     * Alerte l'éditeur qu'une réclamation est possible, une seule fois par
     * société : la première fois que son évaluation le montre (sa preuve
     * de qualité, ou la validation d'une autre société du SIREN), jamais
     * aux passages suivants de la reprise horaire, ni une fois la
     * réclamation ouverte. Le signalement se note sous le verrou de sa
     * ligne (reclamation_signalee_le).
     */
    private function signalerLaReclamation(Societe $societe): void
    {
        $aSignaler = DB::transaction(function () use ($societe): bool {
            $verrouillee = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();

            if ($verrouillee->reclamation_signalee_le !== null || $verrouillee->reclamation_ouverte_le !== null) {
                return false;
            }

            return $verrouillee->forceFill(['reclamation_signalee_le' => now()])->save();
        });

        if ($aSignaler) {
            $this->alerteEditeur->alerter($societe, 'Réclamation possible d\'un SIREN validé par une autre société', [
                'Cette société a prouvé sa qualité de représentant alors que son SIREN est déjà validé par une autre société.',
                'Examinez la réclamation : php artisan editeur:reclamation:ouvrir '.$societe->getKey(),
            ]);
        }
    }
}
