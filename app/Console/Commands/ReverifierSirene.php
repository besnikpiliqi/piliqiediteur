<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reprend les contrôles du répertoire Sirene de l'INSEE (voir
 * ControleSirene), société par société, sous la société de chacune :
 *
 * - chaque heure (voir bootstrap/app.php), les contrôles restés
 *   impossibles, l'INSEE n'ayant pas répondu : l'entreprise d'une société
 *   inscrite pendant la panne, dont l'identité encore vide se complète
 *   alors depuis le répertoire (refusée pour toujours si elle est cessée
 *   avant sa validation), et l'établissement d'un magasin actif
 *   « à vérifier », qui devient vérifié, et peut vendre, quand le
 *   répertoire le confirme, son adresse reprise de Sirene. Un contrôle
 *   négatif s'inscrit, et le magasin reste à vérifier ;
 * - avec --toutes, chaque mois, les sociétés validées et leurs magasins
 *   actifs. Un écart (entreprise cessée, établissement fermé ou inconnu)
 *   s'inscrit au registre des vérifications et se signale à l'éditeur, au
 *   journal de l'application, sans rien bloquer : ni les ventes ni les
 *   paiements ne s'arrêtent, et l'établissement reste vérifié ; l'éditeur
 *   décide.
 *
 * La panne d'une société n'arrête pas les suivantes : elle est signalée
 * (report()), et reprise au passage suivant.
 */
#[Signature('app:reverifier-sirene {--toutes : Revérifie aussi les sociétés validées et leurs magasins actifs}')]
#[Description('Reprend les contrôles Sirene restés impossibles, et revérifie les sociétés validées avec --toutes')]
class ReverifierSirene extends Command
{
    public function handle(TenantContext $tenantContext, ControleSirene $controle, RegistreFiscal $registre): int
    {
        $enEchec = 0;

        Societe::query()->orderBy('id')->each(function (Societe $societe) use ($tenantContext, $controle, $registre, &$enEchec): void {
            try {
                $tenantContext->runAsSociete($societe, function () use ($societe, $controle, $registre): void {
                    $toutes = $this->option('toutes') && $societe->estValidee();

                    if ($toutes || self::dernierResultat($societe, null) === ResultatVerification::Indisponible) {
                        $this->reverifierLEntreprise($societe, $controle, $registre, $toutes);
                    }

                    $societe->magasins()
                        ->where('statut', StatutMagasin::Actif)
                        ->orderBy('id')
                        ->each(function (Magasin $magasin) use ($societe, $controle, $toutes): void {
                            if ($toutes || ($magasin->etablissement_verifie_le === null && self::dernierResultat($societe, $magasin) === ResultatVerification::Indisponible)) {
                                $this->reverifierLEtablissement($magasin, $controle);
                            }
                        });
                });
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        if ($enEchec > 0) {
            $this->error("{$enEchec} société(s) en échec, reprise(s) au prochain passage.");

            return self::FAILURE;
        }

        $this->info('Contrôles Sirene repris.');

        return self::SUCCESS;
    }

    /**
     * Le contrôle de l'entreprise, sous les verrous des registres de ses
     * magasins, que le n° de TVA prérempli s'inscrit à leurs journaux des
     * événements : son identité encore vide se complète depuis le
     * répertoire ; une entreprise cessée avant la validation de la société
     * la fait refuser pour toujours (lecture seule définitive).
     */
    private function reverifierLEntreprise(Societe $societe, ControleSirene $controle, RegistreFiscal $registre, bool $signalerUnEcart): void
    {
        $consultation = $controle->unite((string) $societe->siren);
        $unite = $consultation['unite'];
        $identite = $consultation['resultat'] === ResultatVerification::Concluante && $unite !== null ? $controle->identiteDepuisSirene($unite) : [];

        $registre->sousVerrouDesMagasins($societe->magasins()->orderBy('id')->get(), function () use ($societe, $controle, $consultation, $unite, $identite): void {
            $controle->noterUnite($societe, $consultation);
            $enregistree = Societe::query()->whereKey($societe->getKey())->lockForUpdate()->firstOrFail();
            $enregistree->forceFill(array_filter($identite, fn (mixed $valeur, string $champ): bool => $valeur !== null && $enregistree->getAttribute($champ) === null, ARRAY_FILTER_USE_BOTH))->save();

            if ($unite !== null && ! $unite->active && ! $enregistree->estValidee() && ! $enregistree->estRefusee()) {
                $enregistree->forceFill(['refusee_le' => now()])->save();
            }
        });

        if ($signalerUnEcart && $consultation['resultat'] === ResultatVerification::Negative) {
            Log::warning('Revérification Sirene : écart sur une société validée.', ['societe_id' => $societe->getKey(), 'motif' => $consultation['motif']]);
        }
    }

    private function reverifierLEtablissement(Magasin $magasin, ControleSirene $controle): void
    {
        $societe = $magasin->societe;
        $consultation = $controle->etablissement($magasin->siret, (string) $societe->siren);
        $etablissement = $consultation['etablissement'];

        DB::transaction(function () use ($magasin, $controle, $consultation, $etablissement): void {
            $controle->noterEtablissement($magasin, $consultation);

            if ($magasin->etablissement_verifie_le === null && $consultation['resultat'] === ResultatVerification::Concluante && $etablissement !== null && ControleSirene::estDansUnTerritoireGere($etablissement)) {
                Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail()
                    ->forceFill(['etablissement_verifie_le' => now(), ...ControleSirene::adresseDe($etablissement), 'pays' => 'FR'])
                    ->save();
            }
        });

        if ($magasin->etablissement_verifie_le !== null && $consultation['resultat'] === ResultatVerification::Negative) {
            Log::warning('Revérification Sirene : écart sur un établissement vérifié.', ['magasin_id' => $magasin->getKey(), 'motif' => $consultation['motif']]);
        }
    }

    /**
     * Le résultat du dernier contrôle de l'entreprise (sans magasin), ou de
     * l'établissement du magasin, dans la société active.
     */
    private static function dernierResultat(Societe $societe, ?Magasin $magasin): ?ResultatVerification
    {
        return VerificationSociete::query()
            ->where('societe_id', $societe->getKey())
            ->where('type', ($magasin === null ? TypeVerification::SireneUnite : TypeVerification::SireneEtablissement)->value)
            ->when($magasin === null, fn ($requete) => $requete->whereNull('magasin_id'), fn ($requete) => $requete->where('magasin_id', $magasin->getKey()))
            ->orderByDesc('id')
            ->first()
            ?->resultat;
    }
}
