<?php

namespace App\Domain\Cabinets\Jobs;

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Societe\Pages\CabinetComptable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Les avis au propriétaire de chaque client lié d'un cabinet d'expertise
 * comptable quand l'accès du cabinet à ses clients s'arrête sans que ses
 * liens prennent fin (le cabinet n'est plus vérifié, après la correction
 * de son SIREN par l'éditeur, voir CorrigerSirenAction), puis quand il
 * revient (le cabinet est de nouveau vérifié, voir VerifierLOrdreAction et
 * ConstaterLInscriptionALOrdreAction) : sans autre détail que le nom du
 * cabinet, dans la cloche et par e-mail, chacun mené à « Cabinet
 * comptable ».
 *
 * En file d'attente, après la validation de la transaction qui l'a décidé
 * : ni la requête du propriétaire du cabinet qui fait vérifier son
 * inscription à l'Ordre, ni la procédure de l'éditeur n'attendent ces
 * e-mails, quel que soit le nombre de clients. Le job relit le cabinet, et
 * ses avis ne partent que s'ils disent encore vrai (l'accès est encore
 * suspendu, ou rétabli). Les destinataires se lisent d'un coup, avec les
 * liens actifs du cabinet (voir AccesDesCabinets::clientsAPrevenir()) : de
 * chaque client, son seul propriétaire, la lecture « les destinataires des
 * notifications » de CLAUDE.md. Chaque avis part à part : celui qui ne part
 * pas (une messagerie en panne) est signalé sans empêcher les suivants.
 */
class PrevenirLesClientsDuCabinet implements ShouldQueue
{
    use Queueable;

    public const TITRE_SUSPENDU = 'Accès de votre cabinet suspendu';

    public const TITRE_RETABLI = 'Accès de votre cabinet rétabli';

    /**
     * @param  bool  $accesRetabli  l'accès revient (sinon, il est suspendu)
     */
    public function __construct(
        public readonly int $cabinetId,
        public readonly bool $accesRetabli,
    ) {
        $this->afterCommit();
    }

    public function handle(AccesDesCabinets $acces): void
    {
        $cabinet = Societe::query()->find($this->cabinetId);

        if ($cabinet === null || $cabinet->estUnCabinetVerifie() !== $this->accesRetabli) {
            return;
        }

        [$titre, $texte, $nature] = $this->accesRetabli
            ? [self::TITRE_RETABLI, "Le cabinet {$cabinet->getFilamentName()} accède de nouveau à votre société, selon les autorisations que vous lui accordez.", AvisDuCabinet::SUCCES]
            : [self::TITRE_SUSPENDU, "L'accès du cabinet {$cabinet->getFilamentName()} à votre société est suspendu jusqu'à sa nouvelle vérification.", AvisDuCabinet::ATTENTION];

        $acces->clientsAPrevenir($cabinet)->each(function (LienCabinet $lien) use ($titre, $texte, $nature): void {
            try {
                $lien->client->proprietaire?->notify(new AvisDuCabinet(
                    $lien->client,
                    $titre,
                    $texte,
                    CabinetComptable::getUrl(panel: 'societe', tenant: $lien->client),
                    'Voir le cabinet comptable',
                    $nature,
                    parCourriel: true,
                ));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        });
    }
}
