<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Casts\Instant;
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
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * La procédure de l'éditeur qui examine le SIREN d'une société validée
 * découvert erroné ou usurpé après sa validation
 * (editeur:societe:examiner-siren), sur justificatifs de l'entité
 * correcte, une fois remis le courrier d'examen envoyé au représentant
 * légal de cette entité, à l'adresse officielle de son siège
 * (editeur:courrier:envoyer --motif=examen_siren --siren=<SIREN correct>) :
 * son code, saisi sur sa page depuis moins de trente jours, en est la
 * preuve, pour autant d'examens que l'éditeur en reprend dans ce délai
 * (voir CodeConfirmation::preuveDeLaRemise()). Pas de correction
 * systématique : l'examen passe en revue, magasin par magasin, les
 * documents émis concernés (type, numéro, date, client, montant TTC,
 * montant réglé ou, pour un avoir, crédit imputé, en centimes, SIREN
 * signé), sans en modifier aucun, chacun rendu à qui le demande
 * ($pourChaqueDocument : la commande les affiche), et inscrit au journal
 * d'audit sa décision motivée : une erreur, que la correction répare
 * ensuite (voir CorrigerSirenAction) ; une usurpation, que la révocation
 * traite (voir RevoquerSocieteAction) ; rien à régulariser, qui le clôt.
 *
 * L'instant de l'examen se prend sous les verrous des registres de tous
 * ses magasins réels : le premier des instants que ces verrous notent
 * (voir RegistreFiscal::verrouiller()), ou maintenant sans magasin réel.
 * Une émission en cours s'est alors validée, et toute émission qui suit
 * la levée de ces verrous prend un instant qui n'est pas antérieur à celui
 * que le verrou de son registre a noté, donc à celui de l'examen, quelle
 * que soit l'horloge du serveur qui la fait. L'examen porte sur les
 * documents émis avant cet instant, tous validés quand il les lit, jamais
 * sur un document émis dans sa seconde ou après, qui n'est pas de sa liste
 * (voir RegularisationDUneFacture::estDesignee()) : une facture à un
 * professionnel émise dans cette seconde, avant comme après lui, rend la
 * correction impossible jusqu'à un nouvel examen (voir
 * CorrigerSirenAction).
 *
 * Les documents se lisent par lots (LOT), jamais tous en mémoire, et
 * l'entrée d'audit n'en garde qu'un résumé, quel que soit leur nombre :
 * l'instant avant lequel ils ont été émis (documents_emis_avant, en UTC),
 * et, pour chaque magasin et chaque type, leur nombre, leur premier et
 * leur dernier numéro, avec l'empreinte SHA-256 de la liste entière (une
 * ligne « code du magasin, type, numéro » séparés par des tabulations,
 * dans l'ordre de l'examen : magasin par magasin, par identifiant, ses
 * factures et tickets puis ses avoirs, chacun par date d'émission puis
 * identifiant). Ces documents, qui ne changent jamais, se retrouvent ainsi
 * à l'identique. Lecture des documents des magasins réels de cette seule
 * société, chacun sous son magasin : la lecture « l'examen exceptionnel
 * d'un SIREN par l'éditeur » de CLAUDE.md.
 */
class ExaminerSirenAction
{
    public const MESSAGE_PAS_VALIDEE = 'Cette société n\'est pas validée : une inscription faite sous un SIREN erroné se clôt (editeur:societe:clore), ce qui libère ce SIREN.';

    public const MESSAGE_REVOQUEE = 'Cette société est révoquée : son SIREN ne s\'examine ni ne se corrige plus.';

    public const MESSAGE_SIREN = 'Le SIREN correct est un SIREN valide, différent de celui de la société.';

    public const MESSAGE_SANS_CODE = 'Aucun code d\'examen n\'a été saisi pour ce SIREN depuis moins de trente jours : envoyez d\'abord le courrier (editeur:courrier:envoyer --motif=examen_siren --siren=<SIREN correct>) au représentant légal de l\'entité correcte, à l\'adresse officielle de son siège ; son code, saisi sur sa page, prouve la remise.';

    /**
     * Le nombre de documents lus à la fois.
     */
    private const LOT = 500;

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
    ) {}

    /**
     * @param  (Closure(array{magasin: string, type: string, numero: string, date: string, client: string|null, montant_ttc: int, regle: int, siren_signe: string|null}): void)|null  $pourChaqueDocument  reçoit chaque document émis concerné, ses montants en centimes
     * @return int le nombre de documents émis concernés
     *
     * @throws VerificationRefuseeException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Societe $societe, string $sirenCorrect, DecisionExamenSiren $decision, InterventionDeLEditeur $editeur, ?Closure $pourChaqueDocument = null): int
    {
        $sirenCorrect = (string) preg_replace('/\s+/', '', $sirenCorrect);

        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $sirenCorrect, $decision, $editeur, $pourChaqueDocument): int {
            self::exigerUneSocieteValideeNonRevoquee($societe);

            if (! IdentifiantsInsee::sirenValide($sirenCorrect) || $sirenCorrect === (string) $societe->siren) {
                throw new VerificationRefuseeException(self::MESSAGE_SIREN);
            }

            if (CodeConfirmation::preuveDeLaRemise(MotifCode::ExamenSiren, siren: $sirenCorrect) === null) {
                throw new VerificationRefuseeException(self::MESSAGE_SANS_CODE);
            }

            $magasins = $societe->magasinsReels()->orderBy('id')->get();
            $avant = $this->registre->sousVerrouDesMagasins($magasins, fn (): CarbonImmutable => $magasins
                ->map(fn (Magasin $magasin): CarbonImmutable => app(TenantContext::class)->runAs($magasin, fn (): CarbonImmutable => $this->registre->verrouiller($magasin)->utc))
                ->min() ?? CarbonImmutable::now()->startOfSecond());
            $resume = [];
            $empreinte = hash_init('sha256');
            $nombre = 0;

            foreach ($magasins as $magasin) {
                app(TenantContext::class)->runAs($magasin, function () use ($magasin, $avant, $pourChaqueDocument, &$resume, $empreinte, &$nombre): void {
                    foreach ($this->documentsDuMagasin($magasin, $avant) as $document) {
                        $cle = "{$document['magasin']}\t{$document['type']}";
                        $resume[$cle] ??= ['magasin' => $document['magasin'], 'type' => $document['type'], 'nombre' => 0, 'premier' => $document['numero'], 'dernier' => $document['numero']];
                        $resume[$cle]['nombre']++;
                        $resume[$cle]['dernier'] = $document['numero'];
                        hash_update($empreinte, "{$cle}\t{$document['numero']}\n");
                        $nombre++;

                        if ($pourChaqueDocument !== null) {
                            $pourChaqueDocument($document);
                        }
                    }
                });
            }

            DB::transaction(fn () => $this->journalAudit->inscrire(ActionAuditee::SirenExamine, null, null, null, [
                'siren_correct' => $sirenCorrect,
                'decision' => $decision->value,
                'documents_emis_avant' => Instant::enBase($avant),
                'documents' => array_values($resume),
                'empreinte_des_documents' => hash_final($empreinte),
            ], $societe, $editeur));

            return $nombre;
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
     * Les documents du magasin actif émis avant cet instant, factures et
     * tickets puis avoirs, chacun dans l'ordre de leur émission, lus par
     * lots, avec leurs montants enregistrés, en centimes, et le SIREN que
     * leur contenu signé porte.
     *
     * @return LazyCollection<int, array{magasin: string, type: string, numero: string, date: string, client: string|null, montant_ttc: int, regle: int, siren_signe: string|null}>
     */
    private function documentsDuMagasin(Magasin $magasin, CarbonImmutable $avant): LazyCollection
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

        return Facture::query()->whereNotNull('numero')->where('date_emission', '<', $avant)->with(['allocationsPaiement', 'allocationsAvoir'])->orderBy('date_emission')->orderBy('id')->lazy(self::LOT)
            ->map(fn (Facture $facture): array => $ligne($facture, $facture->type_client === TypeClient::Particulier ? 'ticket' : 'facture', $facture->regleEnCentimes()))
            ->concat(Avoir::query()->whereNotNull('numero')->where('date_emission', '<', $avant)->orderBy('date_emission')->orderBy('id')->lazy(self::LOT)
                ->map(fn (Avoir $avoir): array => $ligne($avoir, 'avoir', $this->calculateur->enCentiemes($avoir->total_ttc) - $avoir->creditEnCentimes())));
    }
}
