<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Exceptions\LienDuDevisInvalideException;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Notifications\ReponseDuClientAuDevis;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * La réponse du client à un devis par le lien secret de son envoi, sans
 * compte (décision de l'éditeur du 02/11/2026) : il l'accepte en tapant
 * son nom et en cochant « Bon pour accord » (signature électronique
 * simple), ou il le refuse, avec un motif facultatif. Une seule réponse
 * par lien, et le lien cesse aussitôt de valoir (voir
 * LienDuDevis::estValable()).
 *
 * Dans une seule transaction, sous le verrou du registre fiscal du
 * magasin, premier verrou (voir RegistreFiscal::sousVerrou()), puis la
 * ligne du magasin sous un verrou partagé et le devis sous le sien : deux
 * réponses simultanées par le même lien, ou une réponse et un geste du
 * magasin sur le devis (acceptation, refus, modification, transformation,
 * retrait du lien), passent l'une après l'autre, et la seconde trouve le
 * lien caduc. La réponse s'enregistre (voir ReponseDuDevis) avec le nom
 * tapé, l'instant et l'empreinte de la version du devis à laquelle le
 * client répond ; le devis passe « Accepté », avec l'instant de son
 * acceptation, ou « Refusé » ; la réponse s'inscrit au journal d'audit du
 * magasin (origine « client ») et à son journal des événements (JET 900),
 * l'adresse IP et le navigateur du client allant à part, dans leurs
 * traces (voir TraceDuVisiteur) ; l'e-mail qui confirme la réponse au
 * client, devis joint, part en file d'attente une fois la transaction
 * validée, à l'adresse du lien (voir EnvoyerDocumentJob).
 *
 * Après la transaction, le magasin est prévenu, dans la cloche et par
 * e-mail : la personne qui a demandé l'envoi, si elle peut encore ouvrir
 * le magasin, et le propriétaire de la société (voir
 * ReponseDuClientAuDevis), l'avis d'une acceptation disant quels
 * produits du devis n'ont plus assez de stock (voir
 * Devis::stocksInsuffisants()). La transformation en facture reste un
 * geste du magasin, par un compte autorisé à vendre (voir
 * CreerFactureDepuisDevisAction).
 */
class RepondreAuDevisEnLigneAction
{
    public const SIGNATAIRE_MAXIMUM = 150;

    public const MOTIF_MAXIMUM = 1000;

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journal,
    ) {}

    /**
     * @throws LienDuDevisInvalideException
     * @throws ValidationException
     */
    public function accepter(string $jeton, string $signataire, bool $bonPourAccord): ReponseDuDevis
    {
        $signataire = trim((string) preg_replace('/\s+/u', ' ', $signataire));

        Validator::make(['signataire' => $signataire, 'bon_pour_accord' => $bonPourAccord], [
            'signataire' => ['required', 'string', 'min:2', 'max:'.self::SIGNATAIRE_MAXIMUM],
            'bon_pour_accord' => ['accepted'],
        ], [
            'signataire.required' => 'Tapez votre nom et votre prénom pour accepter le devis.',
            'signataire.min' => 'Tapez votre nom et votre prénom pour accepter le devis.',
            'signataire.max' => 'Votre nom ne doit pas dépasser '.self::SIGNATAIRE_MAXIMUM.' caractères.',
            'bon_pour_accord.accepted' => 'Cochez « Bon pour accord » pour accepter le devis.',
        ])->validate();

        return $this->repondre($jeton, ReponseAuDevis::Acceptation, $signataire, null);
    }

    /**
     * @throws LienDuDevisInvalideException
     * @throws ValidationException
     */
    public function refuser(string $jeton, ?string $motif): ReponseDuDevis
    {
        $motif = trim((string) $motif);

        Validator::make(['motif' => $motif], [
            'motif' => ['nullable', 'string', 'max:'.self::MOTIF_MAXIMUM],
        ], [
            'motif.max' => 'Le motif ne doit pas dépasser '.self::MOTIF_MAXIMUM.' caractères.',
        ])->validate();

        return $this->repondre($jeton, ReponseAuDevis::Refus, null, $motif === '' ? null : $motif);
    }

    /**
     * @throws LienDuDevisInvalideException
     */
    private function repondre(string $jeton, ReponseAuDevis $reponse, ?string $signataire, ?string $motif): ReponseDuDevis
    {
        $lien = LienDuDevis::parSonJeton($jeton) ?? throw new LienDuDevisInvalideException;
        $magasin = Magasin::query()->findOrFail($lien->magasin_id);

        return app(TenantContext::class)->runAs($magasin, function () use ($lien, $magasin, $reponse, $signataire, $motif): ReponseDuDevis {
            [$enregistree, $devis, $envoi] = $this->registre->sousVerrou($magasin, function () use ($lien, $magasin, $reponse, $signataire, $motif): array {
                $magasinRelu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
                $devis = Devis::query()->whereKey($lien->devis_id)->lockForUpdate()->first() ?? throw new LienDuDevisInvalideException;
                $lien = LienDuDevis::query()->whereKey($lien->getKey())->with('envoi')->firstOrFail();
                $devis->setRelation('magasin', $magasinRelu);

                if (! $lien->estValable($devis)) {
                    throw new LienDuDevisInvalideException;
                }

                $maintenant = CarbonImmutable::now();
                $empreinte = $devis->empreinteDeSaVersion();
                $enregistree = (new ReponseDuDevis)->forceFill([
                    'devis_id' => $devis->getKey(),
                    'lien_du_devis_id' => $lien->getKey(),
                    'reponse' => $reponse,
                    'signataire' => $signataire,
                    'motif' => $motif,
                    'empreinte_du_devis' => $empreinte,
                    'created_at' => $maintenant,
                ]);
                $enregistree->save();

                $devis->forceFill(['statut' => $reponse->statut(), 'accepte_le' => $reponse === ReponseAuDevis::Acceptation ? $maintenant : null])->save();

                $nature = $reponse->natureDeLaConfirmation();
                $confirmation = $devis->envois()->create([
                    'destinataire' => $lien->envoi->destinataire,
                    'objet' => EnvoiDocument::objetPour($devis, $nature),
                    'nature' => $nature,
                    'envoye_par' => null,
                ]);
                EnvoyerDocumentJob::dispatch($confirmation->magasin_id, $confirmation->id)->afterCommit();

                $this->journal->inscrireLaReponseDuClient(
                    $reponse === ReponseAuDevis::Acceptation ? ActionAuditee::DevisAccepteEnLigne : ActionAuditee::DevisRefuseEnLigne,
                    $magasinRelu,
                    array_filter([
                        'devis' => $devis->numero,
                        'signataire' => $signataire,
                        'motif' => $motif,
                        'empreinte_du_devis' => $empreinte,
                    ], fn (?string $valeur): bool => $valeur !== null),
                    $devis,
                );
                $this->registre->journaliser(CodeEvenement::ReponseAUnDevis, [
                    'devis_id' => $devis->getKey(),
                    'numero' => $devis->numero,
                    'reponse' => $reponse->value,
                    'empreinte_du_devis' => $empreinte,
                ]);

                return [$enregistree, $devis, $lien->envoi];
            });

            Notification::send($this->aPrevenir($magasin, $envoi), new ReponseDuClientAuDevis($magasin, $devis, $enregistree, $reponse === ReponseAuDevis::Acceptation ? $devis->stocksInsuffisants() : []));

            return $enregistree;
        });
    }

    /**
     * La personne qui a demandé l'envoi, si elle peut encore ouvrir le
     * magasin, et le propriétaire de la société, une fois chacun.
     *
     * @return Collection<int, User>
     */
    private function aPrevenir(Magasin $magasin, EnvoiDocument $envoi): Collection
    {
        $demandeur = $envoi->envoyePar;

        return collect([
            $demandeur !== null && $demandeur->peutOuvrir($magasin) ? $demandeur : null,
            ValeurRequise::de($magasin->societe->proprietaire, 'le propriétaire de la société'),
        ])->filter()->unique(fn (User $compte): int => $compte->getKey())->values();
    }
}
