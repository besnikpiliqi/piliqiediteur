<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
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
use Illuminate\Notifications\DatabaseNotification;
use LogicException;
use Throwable;

/**
 * La seule validation d'une société, qui reçoit la preuve utilisée (voir
 * PreuveDeValidation : le code du courrier recommandé, saisi par
 * SaisirCodeDeConfirmationAction, ou l'invitation d'un cabinet
 * d'expertise comptable, acceptée par AccepterLInvitationAction, avec ce
 * cabinet) et l'inscrit au journal d'audit. Une autre voie que l'éditeur
 * déciderait passerait par elle, avec sa propre preuve.
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
 * quotidienne le reprend) ; il disparaît de tous les écrans de la société
 * et de ses magasins, plus personne n'y entrant (T115, lot DEMO-V, voir
 * User::peutOuvrir()), et les notifications à son propos s'effacent des
 * cloches de la société, ses données restant en base (registre, journal
 * des événements, JET 100 et 105) ; le propriétaire est prévenu, sans
 * parler de magasins à un cabinet d'expertise comptable, qui n'en a aucun.
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
     * @param  Societe|null  $cabinet  le cabinet dont l'invitation est la preuve, et seulement pour elle
     *
     * @throws LogicException quand la société n'attend plus de code, ou que le cabinet ne va pas avec la preuve
     * @throws SiretDejaReserveException
     */
    public function valider(Societe $verrouillee, User $proprietaire, PreuveDeValidation $preuve, ?Societe $cabinet = null): void
    {
        if (! $verrouillee->attendSonCode() || ! $proprietaire->estProprietaire() || (int) $proprietaire->societe_id !== $verrouillee->getKey()) {
            throw new LogicException('Seule une inscription qui attend son code se valide, par la preuve de son propriétaire.');
        }

        if (($preuve === PreuveDeValidation::InvitationDuCabinet) !== ($cabinet?->estUnCabinet() === true)) {
            throw new LogicException('Seule l\'invitation d\'un cabinet d\'expertise comptable nomme le cabinet qui valide la société.');
        }

        $this->operateur->pendantLeSysteme(function () use ($verrouillee, $proprietaire, $preuve, $cabinet): void {
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
                'cabinet' => $cabinet === null ? [] : IdentiteDansLeJournal::de($cabinet),
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

                DatabaseNotification::query()
                    ->where('notifiable_type', (new User)->getMorphClass())
                    ->whereIn('notifiable_id', User::query()->where('societe_id', $societe->getKey())->select('id'))
                    ->where('data->societe_id', $societe->getKey())
                    ->where('data->magasin_id', $demonstration->getKey())
                    ->delete();
            }

            $proprietaire->notify(new AvisDeVerification(
                $societe,
                'Votre société est validée',
                $societe->estUnCabinet()
                    ? 'Votre cabinet est validé.'
                    : 'La vente, les e-mails aux clients, les PDF et les transmissions sont ouverts dans vos magasins.'
                        .($demonstrationOuverte ? ' Le magasin de démonstration est terminé : il n\'apparaît plus, et rien n\'en passe à vos magasins réels.' : ''),
                AvisDeVerification::SUCCES,
            ));
        });
    }
}
