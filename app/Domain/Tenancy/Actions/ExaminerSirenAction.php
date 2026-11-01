<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Support\Facades\DB;

/**
 * La procédure de l'éditeur qui examine le SIREN d'une société validée
 * découvert erroné ou usurpé après sa validation
 * (editeur:societe:examiner-siren), sur justificatifs de l'entité
 * correcte, une fois remis le courrier d'examen envoyé au représentant
 * légal de cette entité, à l'adresse officielle de son siège
 * (editeur:courrier:envoyer --motif=examen_siren --siren=<SIREN correct>) :
 * son code, saisi sur sa page, en est la preuve. Pas de correction
 * systématique : l'examen liste, magasin par magasin, les documents émis
 * concernés (type, numéro, date, client, montant TTC, montant réglé ou,
 * pour un avoir, crédit imputé, en centimes, SIREN signé), sans en
 * modifier aucun, et inscrit au journal d'audit sa décision motivée : une
 * erreur, que la correction répare ensuite (voir CorrigerSirenAction) ;
 * une usurpation, que la révocation traite (voir RevoquerSocieteAction) ;
 * rien à régulariser, qui le clôt. Lecture des documents des magasins réels de
 * cette seule société, chacun sous son magasin : la lecture « l'examen
 * exceptionnel d'un SIREN par l'éditeur » de CLAUDE.md.
 */
class ExaminerSirenAction
{
    public const MESSAGE_PAS_VALIDEE = 'Cette société n\'est pas validée : une inscription faite sous un SIREN erroné se clôt (editeur:societe:clore), ce qui libère ce SIREN.';

    public const MESSAGE_REVOQUEE = 'Cette société est révoquée : son SIREN ne s\'examine ni ne se corrige plus.';

    public const MESSAGE_SIREN = 'Le SIREN correct est un SIREN valide, différent de celui de la société.';

    public const MESSAGE_SANS_CODE = 'Aucun code d\'examen n\'a été saisi pour ce SIREN : envoyez d\'abord le courrier (editeur:courrier:envoyer --motif=examen_siren --siren=<SIREN correct>) au représentant légal de l\'entité correcte, à l\'adresse officielle de son siège ; son code, saisi sur sa page, prouve la remise.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @return list<array{magasin: string, type: string, numero: string, date: string, client: string|null, montant_ttc: int, regle: int, siren_signe: string|null}> les documents émis concernés, leurs montants en centimes
     *
     * @throws VerificationRefuseeException
     */
    public function handle(Societe $societe, string $sirenCorrect, DecisionExamenSiren $decision, InterventionDeLEditeur $editeur): array
    {
        $sirenCorrect = (string) preg_replace('/\s+/', '', $sirenCorrect);

        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $sirenCorrect, $decision, $editeur): array {
            self::exigerUneSocieteValideeNonRevoquee($societe);

            if (! IdentifiantsInsee::sirenValide($sirenCorrect) || $sirenCorrect === (string) $societe->siren) {
                throw new VerificationRefuseeException(self::MESSAGE_SIREN);
            }

            if (! CodeConfirmation::query()->where('motif', MotifCode::ExamenSiren->value)->where('siren', $sirenCorrect)->whereNotNull('utilise_le')->exists()) {
                throw new VerificationRefuseeException(self::MESSAGE_SANS_CODE);
            }

            $documents = $societe->magasinsReels()->orderBy('id')->get()
                ->flatMap(fn (Magasin $magasin): array => app(TenantContext::class)->runAs($magasin, fn (): array => $this->documentsDuMagasin($magasin)))
                ->values()
                ->all();

            DB::transaction(fn () => $this->journalAudit->inscrire(ActionAuditee::SirenExamine, null, null, null, [
                'siren_correct' => $sirenCorrect,
                'decision' => $decision->value,
                'documents' => array_map(static fn (array $document): array => ['magasin' => $document['magasin'], 'type' => $document['type'], 'numero' => $document['numero']], $documents),
            ], $societe, $editeur));

            return $documents;
        });
    }

    /**
     * Seule une société validée, et jamais révoquée, a un SIREN qui
     * s'examine et se corrige par l'éditeur (voir CorrigerSirenAction) :
     * une inscription faite sous un SIREN erroné se clôt, ce qui le libère,
     * et une société révoquée a déjà rendu le sien.
     *
     * @throws VerificationRefuseeException
     */
    public static function exigerUneSocieteValideeNonRevoquee(Societe $societe): void
    {
        if (! $societe->estValidee()) {
            throw new VerificationRefuseeException(self::MESSAGE_PAS_VALIDEE);
        }

        if ($societe->statut === StatutSociete::Revoquee) {
            throw new VerificationRefuseeException(self::MESSAGE_REVOQUEE);
        }
    }

    /**
     * Les documents émis du magasin actif, factures et tickets puis avoirs,
     * dans l'ordre de leur émission, avec leurs montants enregistrés, en
     * centimes, et le SIREN que leur contenu signé porte.
     *
     * @return list<array{magasin: string, type: string, numero: string, date: string, client: string|null, montant_ttc: int, regle: int, siren_signe: string|null}>
     */
    private function documentsDuMagasin(Magasin $magasin): array
    {
        $calendrier = $magasin->calendrier();
        $ligne = fn (Facture|Avoir $document, string $type, int $regleEnCentimes): array => [
            'magasin' => (string) $magasin->code,
            'type' => $type,
            'numero' => (string) $document->numero,
            'date' => $calendrier->jourDe($document->date_emission),
            'client' => $document->client_nom,
            'montant_ttc' => $this->calculateur->enCentiemes($document->total_ttc),
            'regle' => $regleEnCentimes,
            'siren_signe' => $document->emetteur()->siren,
        ];

        return [
            ...Facture::query()->whereNotNull('numero')->with(['allocationsPaiement', 'allocationsAvoir'])->orderBy('date_emission')->orderBy('id')->get()
                ->map(fn (Facture $facture): array => $ligne($facture, $facture->type_client === TypeClient::Particulier ? 'ticket' : 'facture', $facture->regleEnCentimes()))
                ->all(),
            ...Avoir::query()->whereNotNull('numero')->orderBy('date_emission')->orderBy('id')->get()
                ->map(fn (Avoir $avoir): array => $ligne($avoir, 'avoir', $this->calculateur->enCentiemes($avoir->total_ttc) - $avoir->creditEnCentimes()))
                ->all(),
        ];
    }
}
