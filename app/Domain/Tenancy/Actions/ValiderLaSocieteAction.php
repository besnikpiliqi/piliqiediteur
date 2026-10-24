<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\PreuveDeValidation;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Events\SocieteValidee;
use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\MiseEnActiviteDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use LogicException;
use Throwable;

/**
 * La seule validation d'une société, qui reçoit la preuve utilisée (voir
 * PreuveDeValidation : aujourd'hui seulement le code du courrier
 * recommandé, saisi par SaisirCodeDeConfirmationAction) et l'inscrit au
 * journal d'audit. Une autre voie que l'éditeur déciderait passerait par
 * elle, avec sa propre preuve.
 *
 * valider() s'exécute dans la transaction de l'appelant, sous les verrous
 * des registres de tous les magasins de la société, puis de la ligne de la
 * société, qui attend encore son code : la société est validée
 * (validee_le, seule écriture de cette colonne) ; chacun de ses magasins
 * réels actifs se met en activité (SIRET réservé, période ouverte, droits
 * implicites du propriétaire au JET 130, voir MiseEnActiviteDesMagasins),
 * que son établissement soit déjà confirmé par le répertoire Sirene ou
 * encore à vérifier (il ne vend alors qu'après sa confirmation, comme un
 * magasin créé pendant une panne de l'INSEE dans une société validée) ;
 * pour le code du courrier, le propriétaire qui l'a saisi est noté
 * (code_du_courrier_saisi_le) : lui seul signe les attestations dans
 * l'application (voir User::signeLesAttestationsDansLApplication()). La
 * validation s'inscrit au journal d'audit, sans auteur : elle suit la
 * preuve, et les journaux des événements de ses magasins l'inscrivent au
 * nom du système (voir OperateurCourant), même quand la personne qui a
 * saisi le code est connectée. L'événement SocieteValidee part une fois la
 * transaction validée, après le MagasinOuvert de chaque magasin mis en
 * activité (étape 12 : les abonnements). Un magasin réel fermé n'est ni
 * réservé ni ouvert : sa réouverture le mettra en activité (voir
 * RouvrirMagasinAction). Un SIRET réservé entre-temps par une autre
 * société fait tout refuser (SiretDejaReserveException) : rien n'est
 * écrit.
 *
 * apresLaValidation(), une fois la transaction validée : le magasin de
 * démonstration se ferme pour de bon et ses comptes d'entraînement sont
 * retirés, même s'il était déjà fermé pour inactivité (un échec n'empêche
 * rien : il ne vend plus, la société étant validée, et la commande
 * quotidienne le reprend), et le propriétaire est prévenu.
 */
class ValiderLaSocieteAction
{
    public function __construct(
        private readonly MiseEnActiviteDesMagasins $miseEnActivite,
        private readonly JournalAudit $journalAudit,
        private readonly FermerLaDemonstrationAction $fermerLaDemonstration,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  Societe  $verrouillee  la société, relue sous le verrou de sa ligne, dans son propre contexte
     * @param  User  $proprietaire  son propriétaire, qui a donné la preuve
     *
     * @throws LogicException quand la société n'attend plus de code
     * @throws SiretDejaReserveException
     */
    public function valider(Societe $verrouillee, User $proprietaire, PreuveDeValidation $preuve): void
    {
        if (! $verrouillee->attendSonCode() || ! $proprietaire->estProprietaire() || (int) $proprietaire->societe_id !== $verrouillee->getKey()) {
            throw new LogicException('Seule une inscription qui attend son code se valide, par la preuve de son propriétaire.');
        }

        $this->operateur->pendantLeSysteme(function () use ($verrouillee, $proprietaire, $preuve): void {
            $magasins = $verrouillee->magasinsReels()->where('statut', StatutMagasin::Actif->value)->orderBy('id')->get();
            $verrouillee->forceFill(['validee_le' => now()])->save();

            foreach ($magasins as $magasin) {
                app(TenantContext::class)->runAs($magasin, fn () => $this->miseEnActivite->mettreEnActivite($magasin->setRelation('societe', $verrouillee), null));
            }

            if ($preuve === PreuveDeValidation::CodeDuCourrier) {
                $proprietaire->forceFill(['code_du_courrier_saisi_le' => now()])->save();
            }

            $this->journalAudit->inscrire(ActionAuditee::SocieteValidee, null, null, null, [
                'preuve' => $preuve->value,
                'proprietaire' => ['id' => $proprietaire->getKey(), 'nom' => $proprietaire->name],
                'sirets' => $magasins->map(fn (Magasin $magasin): string => (string) $magasin->siret)->values()->all(),
            ], $verrouillee);

            SocieteValidee::dispatch($verrouillee);
        });
    }

    public function apresLaValidation(Societe $societe, User $proprietaire): void
    {
        app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $proprietaire): void {
            $demonstration = $societe->demonstration()->first();
            $demonstrationOuverte = $demonstration?->statut === StatutMagasin::Actif;

            if ($demonstration !== null) {
                try {
                    $this->fermerLaDemonstration->handle($demonstration);
                } catch (Throwable $erreur) {
                    report($erreur);
                }
            }

            $proprietaire->notify(new AvisDeVerification(
                $societe,
                'Votre société est validée',
                'La vente, les e-mails aux clients, les PDF et les transmissions sont ouverts dans vos magasins.'
                    .($demonstrationOuverte ? ' Le magasin de démonstration est terminé et reste consultable.' : ''),
                AvisDeVerification::SUCCES,
            ));
        });
    }
}
