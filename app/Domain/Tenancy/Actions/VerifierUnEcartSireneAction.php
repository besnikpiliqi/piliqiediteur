<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * « C'est vérifié », pour un écart que la revérification du répertoire
 * Sirene a relevé après la validation (voir ReverificationSirene) : la
 * personne dit l'avoir regardé, et l'écart sort de l'« À faire » de
 * l'espace de la société (décision de l'éditeur du 01/11/2026, Q-AF13).
 * Rien d'autre ne change : ni l'identité de la société, ni le magasin,
 * ni ce que l'éditeur peut décider ; un nouvel écart relevé ensuite se
 * signale de nouveau. Le propriétaire, ou, pour l'établissement d'un
 * magasin, un administrateur qui peut le rouvrir (voir
 * PorteeDesDroits::peutVerifierLEcart()).
 *
 * Sous le verrou de la ligne de l'auteur relu (voir
 * JournalDesDroits::sousVerrouDesComptes()), puis de l'écart, relu dans la
 * société de l'auteur : un écart d'une autre société est introuvable, et
 * deux « C'est vérifié » du même écart ne passent pas l'un à côté de
 * l'autre. Le geste note le nom figé de son auteur et s'inscrit au journal
 * d'audit de la société (dans la chaîne du magasin en cause s'il y en a
 * un).
 */
class VerifierUnEcartSireneAction
{
    use AgitDansLaSociete;

    public const DEJA_VERIFIE = 'Cet écart est déjà vérifié.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, EcartSirene $ecart): void
    {
        $this->dansLaSociete($auteur->societe()->firstOrFail(), PorteeDesDroits::REFUS, fn () => $this->journalDesDroits->sousVerrouDesComptes($auteur, [], function (User $auteurRelu) use ($ecart): void {
            $relu = EcartSirene::query()->with('magasin')->whereKey($ecart->getKey())->lockForUpdate()->first();

            if ($relu === null || ! $this->portee->peutVerifierLEcart($auteurRelu, $relu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            if ($relu->verifie_le !== null) {
                throw new LogicException(self::DEJA_VERIFIE);
            }

            $relu->forceFill(['verifie_le' => now(), 'verifie_par' => $auteurRelu->getKey()])->save();

            $this->journalAudit->inscrire(ActionAuditee::EcartSireneVerifie, $auteurRelu, $relu->magasin, null, [
                'motif' => $relu->motif,
                'signale_le' => $relu->signale_le === null ? null : Calendrier::deParis()->afficher($relu->signale_le, 'd/m/Y H:i'),
            ]);
        }));
    }
}
