<?php

namespace App\Domain\CaisseSecurisee\Actions;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Enums\DeclenchementVerification;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RapportIntegrite;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie l'intégrité du registre fiscal du magasin actif (voir
 * VerificateurIntegrite), puis inscrit la vérification au journal des
 * événements (art. 286 I-3° bis du CGI) :
 *
 * - chaque anomalie que garde son rapport, une rupture de séquence ou de
 *   numérotation (JET 95) ou un autre défaut d'intégrité (JET 90), soit
 *   au plus RapportIntegrite::ANOMALIES_RETENUES par vérification ;
 * - puis l'opération de contrôle (JET 280) : vérification complète ou
 *   incrémentale, déclenchement, résultat, nombre total d'anomalies,
 *   nombre d'enregistrements vérifiés et point de reprise de chaque
 *   chaîne.
 *
 * Une vérification incrémentale reprend après les points de reprise de la
 * dernière opération de contrôle du magasin, pourvu que sa signature se
 * vérifie avec une clé épinglée ; sinon, s'il n'y en a jamais eu ou
 * qu'elle n'en note aucun, elle vérifie tout le registre, et s'inscrit
 * comme complète. Toute anomalie est en plus signalée au journal de
 * l'application (Log::critical), pour que l'éditeur intervienne.
 *
 * Fermé par défaut : sans clé de signature, la vérification ne pourrait
 * pas s'inscrire au journal, elle est refusée avant de commencer.
 */
class VerifierIntegriteAction
{
    public function __construct(
        private readonly VerificateurIntegrite $verificateur,
        private readonly RegistreFiscal $registre,
        private readonly SignataireFiscal $signataire,
    ) {}

    /**
     * @param  bool  $complet  tout le registre, au lieu de ce qui s'y est inscrit depuis la dernière vérification
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Magasin $magasin, bool $complet, DeclenchementVerification $declenchement): RapportIntegrite
    {
        $this->signataire->cleId();

        $rapport = $this->verificateur->verifier($magasin, $complet ? null : $this->pointsDeRepriseDuDernierControle());

        DB::transaction(function () use ($magasin, $rapport, $declenchement): void {
            $this->registre->verrouiller($magasin);

            foreach ($rapport->anomalies() as $anomalie) {
                $this->registre->journaliser($anomalie->code, $anomalie->details());
            }

            $this->registre->journaliser(CodeEvenement::OperationDeControle, [
                'verification' => $rapport->complet ? 'complete' : 'incrementale',
                'declenchement' => $declenchement->value,
                'resultat' => $rapport->estConforme() ? 'conforme' : 'anomalies',
                'nombre_anomalies' => $rapport->nombreDAnomalies(),
                'enregistrements_verifies' => $rapport->enregistrementsVerifies(),
                'points_de_reprise' => $rapport->pointsDeReprise(),
            ]);
        });

        if (! $rapport->estConforme()) {
            Log::critical('Caisse sécurisée : la vérification de l\'intégrité du registre fiscal a relevé des anomalies.', [
                'magasin_id' => $magasin->getKey(),
                'verification' => $rapport->complet ? 'complete' : 'incrementale',
                'nombre_anomalies' => $rapport->nombreDAnomalies(),
                'anomalies' => array_map(
                    static fn (AnomalieFiscale $anomalie): string => "[{$anomalie->code->value}] {$anomalie->message}",
                    $rapport->anomalies(),
                ),
            ]);
        }

        return $rapport;
    }

    /**
     * Les points de reprise de la dernière opération de contrôle du
     * magasin actif, si sa signature se vérifie avec une clé épinglée et
     * qu'ils sont lisibles ; sinon null, pour tout vérifier.
     *
     * @return array<string, array{sequence: int, signature: string}>|null
     */
    private function pointsDeRepriseDuDernierControle(): ?array
    {
        $controle = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::OperationDeControle->value)
            ->orderByDesc('sequence')
            ->first();

        if ($controle === null || ! $this->signataire->verifier($controle->contenu, $controle->signature, $controle->cle_id)) {
            return null;
        }

        $pointsDeReprise = $controle->contenuSigne()['donnees']['details']['points_de_reprise'] ?? null;

        if (! is_array($pointsDeReprise)) {
            return null;
        }

        foreach ($pointsDeReprise as $chaine => $pointDeReprise) {
            if (ChaineFiscale::tryFrom((string) $chaine) === null || ! is_int($pointDeReprise['sequence'] ?? null) || ! is_string($pointDeReprise['signature'] ?? null)) {
                return null;
            }
        }

        return $pointsDeReprise;
    }
}
