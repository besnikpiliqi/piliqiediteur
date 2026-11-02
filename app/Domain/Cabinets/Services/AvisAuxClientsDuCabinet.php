<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Societe\Pages\CabinetComptable;
use Throwable;

/**
 * Les avis au propriétaire de chaque client lié d'un cabinet d'expertise
 * comptable quand l'accès du cabinet à ses clients s'arrête sans que ses
 * liens prennent fin (le cabinet n'est plus vérifié, après la correction
 * de son SIREN par l'éditeur, voir CorrigerSirenAction), puis quand il
 * revient (le cabinet est de nouveau vérifié, voir VerifierLOrdreAction et
 * ConstaterLInscriptionALOrdreAction) : sans autre détail que le nom du
 * cabinet, dans la cloche et par e-mail, chacun mené à « Cabinet
 * comptable ». Les destinataires se lisent par les liens actifs du cabinet
 * (voir AccesDesCabinets::liensActifsDuCabinet()) : de chaque client, son
 * seul propriétaire, la lecture « les destinataires des notifications » de
 * CLAUDE.md. Chaque avis part à part : celui qui ne part pas (une
 * messagerie en panne) est signalé sans empêcher les suivants ni rien
 * défaire.
 */
class AvisAuxClientsDuCabinet
{
    public const TITRE_SUSPENDU = 'Accès de votre cabinet suspendu';

    public const TITRE_RETABLI = 'Accès de votre cabinet rétabli';

    public function __construct(private readonly AccesDesCabinets $acces) {}

    public function accesSuspendu(Societe $cabinet): void
    {
        $this->prevenir($cabinet, self::TITRE_SUSPENDU, "L'accès du cabinet {$cabinet->getFilamentName()} à votre société est suspendu jusqu'à sa nouvelle vérification.", AvisDuCabinet::ATTENTION);
    }

    public function accesRetabli(Societe $cabinet): void
    {
        $this->prevenir($cabinet, self::TITRE_RETABLI, "Le cabinet {$cabinet->getFilamentName()} accède de nouveau à votre société, selon les autorisations que vous lui accordez.", AvisDuCabinet::SUCCES);
    }

    private function prevenir(Societe $cabinet, string $titre, string $texte, string $nature): void
    {
        foreach ($this->acces->liensActifsDuCabinet($cabinet) as $lien) {
            try {
                $client = Societe::query()->whereKey($lien->societe_id)->firstOrFail(['id', 'uuid', 'siren', 'raison_sociale']);
                $client->proprietaire()->first()?->notify(new AvisDuCabinet(
                    $client,
                    $titre,
                    $texte,
                    CabinetComptable::getUrl(panel: 'societe', tenant: $client),
                    'Voir le cabinet comptable',
                    $nature,
                    parCourriel: true,
                ));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }
    }
}
