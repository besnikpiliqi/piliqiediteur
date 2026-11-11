<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
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
 * Le propriétaire d'un client règle les autorisations qu'il accorde à son
 * cabinet lié (voir LienCabinetPolicy::reglerLesAutorisations()) : il en
 * retire et, pour un client qui a des articles, en ajoute parmi celles des
 * achats et du stock, jamais celles de la vente, des comptes, des réglages
 * ni de la caisse (voir PermissionRegistry::verifierDuCabinet() ; la base
 * les refuse aussi). Une liste vide est permise : le cabinet reste lié,
 * sans plus ouvrir aucun document de ses magasins. Sous les verrous de
 * JournalDesDroits::modifierLeLien() : le lien doit être encore actif ; le
 * JET 130 de chaque compte du cabinet qui a accès, dans chaque magasin
 * réel du client, et l'audit dans les deux journaux. Le propriétaire du
 * cabinet est prévenu.
 */
class ReglerLesAutorisationsDuCabinetAction
{
    public const MESSAGE_LIEN_TERMINE = 'Ce cabinet n\'est plus lié à votre société.';

    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    /**
     * @param  array<array-key, mixed>  $autorisations
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien, array $autorisations): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);

        $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($autorisations): void {
            [$relu] = $relus;
            $this->journalDesDroits->exigerDansSaSociete(ValeurRequise::de($auteurRelu, 'l\'auteur du geste, relu sous verrou'), 'reglerLesAutorisations', $relu);

            if (! $relu->estActif()) {
                throw new LogicException(self::MESSAGE_LIEN_TERMINE);
            }

            $relu->accorder($autorisations);
        }, fn (Societe $journal, array $relus): array => [[ActionAuditee::AutorisationsDuCabinetModifiees, [
            'cabinet' => IdentiteDansLeJournal::de(Societe::query()->findOrFail($relus[0]->cabinet_id)),
        ], (int) $relus[0]->cabinet_id]]);

        DB::afterCommit(function () use ($client, $lien): void {
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
