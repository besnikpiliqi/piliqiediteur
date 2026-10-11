<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeVerification;
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
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
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
 * actifs puis de la ligne de la société, l'évaluation se refait ; puis la
 * société est validée (validee_le, seule écriture de cette colonne) et son
 * SIREN réservé (l'index unique partiel societes_siren_valide_unique
 * n'en retient qu'une par SIREN) ; chaque magasin réel actif se met en
 * activité (SIRET réservé, période ouverte, droits implicites du
 * propriétaire au JET 130, voir MiseEnActiviteDesMagasins) ; la qualité de
 * représentant du propriétaire est notée pour lui (qualite_verifiee_le) ;
 * la validation s'inscrit au journal d'audit. Un magasin réel fermé
 * n'est ni réservé ni ouvert. Si une autre société a validé ce SIREN, ou
 * réservé l'un de ces SIRET, entre-temps, rien n'est écrit : la société
 * devient une réclamation possible, et l'éditeur en est prévenu, sans
 * rien en révéler à personne d'autre.
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
    public function __construct(
        private readonly EvaluateurDeVerification $evaluateur,
        private readonly RegistreFiscal $registre,
        private readonly MiseEnActiviteDesMagasins $miseEnActivite,
        private readonly JournalAudit $journalAudit,
        private readonly FermerLaDemonstrationAction $fermerLaDemonstration,
        private readonly AlerteEditeur $alerteEditeur,
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
                $validation = $this->valider($societe, $editeur);
            } catch (ConflitDeValidationException) {
                $this->signalerLaReclamation($societe);

                return false;
            }

            if ($validation === null) {
                return false;
            }

            $this->apresLaValidation($societe->fresh(), $validation['etat'], $validation['proprietaire']);

            return true;
        });
    }

    /**
     * @return array{etat: EtatDeVerification, proprietaire: User}|null null quand la société n'est plus validable sous les verrous
     *
     * @throws ConflitDeValidationException quand, sous les verrous, une autre société a déjà validé ce SIREN ou réservé l'un de ces SIRET
     */
    private function valider(Societe $societe, ?InterventionDeLEditeur $editeur): ?array
    {
        $magasins = $societe->magasinsReels()->where('statut', StatutMagasin::Actif->value)->orderBy('id')->get();

        return $this->registre->sousVerrouDesMagasins($magasins, function () use ($societe, $magasins, $editeur): ?array {
            $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
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

            $proprietaire->forceFill(['qualite_verifiee_le' => now()])->save();

            $this->journalAudit->inscrire(ActionAuditee::SocieteValidee, null, null, null, [
                'preuve' => $etat->preuveDeQualite,
                'beneficiaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                'sirets' => $magasins->map(fn (Magasin $magasin): string => (string) $magasin->siret)->values()->all(),
            ], $enregistree, $editeur);

            return ['etat' => $etat, 'proprietaire' => $proprietaire];
        });
    }

    private function apresLaValidation(Societe $societe, EtatDeVerification $etat, User $proprietaire): void
    {
        $demonstration = $societe->demonstration()->where('statut', StatutMagasin::Actif->value)->first();

        if ($demonstration !== null) {
            try {
                $this->fermerLaDemonstration->handle($demonstration);
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }

        EnvoyerLaLettreDInformationJob::dispatch($societe->getKey(), $this->personneVerifiee($proprietaire, $etat))->afterCommit();

        $proprietaire->notify(new AvisDeVerification(
            $societe,
            'Votre société est validée',
            'La vente, les e-mails aux clients, les PDF et les transmissions sont ouverts dans vos magasins.'.($demonstration !== null ? ' Le magasin de démonstration est terminé et reste consultable.' : ''),
            AvisDeVerification::SUCCES,
        ));

        $this->prevenirLesInscriptionsConcurrentes($societe);
    }

    /**
     * La personne que nomme la lettre d'information : celle dont
     * l'identité a été vérifiée, ou, pour des pièces examinées par
     * l'éditeur, le titulaire du compte.
     */
    private function personneVerifiee(User $proprietaire, EtatDeVerification $etat): string
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
        $nom = is_array($details) ? trim(($details['prenoms'][0] ?? '').' '.($details['nom'] ?? '')) : '';

        return $nom !== '' ? $nom : $proprietaire->name;
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

    private function signalerLaReclamation(Societe $societe): void
    {
        $this->alerteEditeur->alerter($societe, 'Réclamation possible d\'un SIREN validé par une autre société', [
            'Cette société a prouvé sa qualité de représentant alors que son SIREN est déjà validé par une autre société.',
            'Examinez la réclamation : php artisan editeur:reclamation:ouvrir '.$societe->getKey(),
        ]);
    }
}
