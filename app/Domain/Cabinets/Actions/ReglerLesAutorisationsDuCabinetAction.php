<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Le propriétaire d'un client règle les magasins réels qu'il ouvre à son
 * cabinet lié, tous, présents et futurs, ou une sélection d'au moins un
 * (décision de l'éditeur du 11/11/2026, voir
 * LienCabinet::choisirLesMagasins()), et les autorisations qu'il lui
 * accorde, les mêmes dans chacun de ces magasins (voir
 * LienCabinetPolicy::reglerLesAutorisations()) : il en
 * retire et, pour un client qui a des articles, en ajoute parmi celles des
 * achats et du stock, jamais celles de la vente, des comptes, des réglages
 * ni de la caisse (voir PermissionRegistry::verifierDuCabinet() ; la base
 * les refuse aussi). Une liste vide est permise : le cabinet reste lié,
 * sans plus ouvrir aucun document de ses magasins. Sous les verrous de
 * JournalDesDroits::modifierLeLien() : le lien doit être encore en cours
 * (actif, ou la déclaration de l'expert-comptable de la société) ; le
 * JET 130 de chaque compte du cabinet qui a accès, dans chaque magasin
 * réel du client où ses droits changent (un magasin ajouté ou retiré de la
 * sélection compris), et l'audit dans les deux journaux, avec les
 * autorisations et les magasins ouverts. Un compte du cabinet perd un
 * magasin retiré dès sa requête suivante. Le propriétaire du cabinet est
 * prévenu ; pour une déclaration, chaque compte ouvert de
 * l'expert-comptable de la société l'est dans sa cloche, et l'entrée ne
 * va qu'au journal du client, avec les droits de ces comptes.
 */
class ReglerLesAutorisationsDuCabinetAction
{
    public const MESSAGE_LIEN_TERMINE = 'Ce cabinet n\'est plus lié à votre société.';

    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  array<array-key, mixed>  $autorisations
     * @param  array<array-key, mixed>|null  $magasins  les magasins réels ouverts au cabinet, ou null pour tous, présents et futurs
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien, array $autorisations, ?array $magasins = null): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);

        $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($autorisations, $magasins): void {
            [$relu] = $relus;
            $this->journalDesDroits->exigerDansSaSociete(ValeurRequise::de($auteurRelu, 'l\'auteur du geste, relu sous verrou'), 'reglerLesAutorisations', $relu);

            if (! $relu->estEnCours()) {
                throw new LogicException(self::MESSAGE_LIEN_TERMINE);
            }

            $relu->accorder($autorisations);
            $relu->choisirLesMagasins($magasins);
        }, fn (Societe $journal, array $relus): array => [[ActionAuditee::AutorisationsDuCabinetModifiees, [
            'cabinet' => $relus[0]->cabinet_id === null ? IdentiteDansLeJournal::declaree($relus[0]) : IdentiteDansLeJournal::de(Societe::query()->findOrFail($relus[0]->cabinet_id)),
            'autorisations' => $relus[0]->autorisations(),
            'magasins' => IdentiteDansLeJournal::magasinsOuverts($relus[0]),
        ], $relus[0]->cabinet_id === null ? CleDuModele::de($client) : (int) $relus[0]->cabinet_id]]);

        DB::afterCommit(function () use ($client, $lien): void {
            if ($lien->cabinet_id === null) {
                foreach (app(ExpertsComptablesDeclares::class)->comptesDe($lien, avecLesSuspendus: false) as $compte) {
                    $compte->notify(new AvisDeLEquipe($client, 'Autorisations changées', "Vos autorisations chez {$client->getFilamentName()} ont changé.", null, parCourriel: false));
                }

                return;
            }

            $cabinet = Societe::query()->findOrFail($lien->cabinet_id);
            $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                $cabinet,
                'Autorisations changées',
                "{$client->getFilamentName()} a changé les autorisations de votre cabinet.",
                ClientDuCabinetResource::getUrl('view', ['record' => $lien->uuid], panel: 'cabinet', tenant: $cabinet),
                'Voir le client',
                AvisDuCabinet::INFORMATION,
                clientId: CleDuModele::de($client),
            ));
        });
    }
}
