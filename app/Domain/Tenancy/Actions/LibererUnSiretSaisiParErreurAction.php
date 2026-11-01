<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use LogicException;

/**
 * La procédure de l'éditeur qui libère un SIRET saisi par erreur pour un
 * magasin d'une société validée (editeur:siret:liberer), procédure grave
 * lancée par deux opérateurs distincts, une fois remis le courrier de
 * libération envoyé au représentant légal, à l'adresse officielle du
 * siège (editeur:courrier:envoyer --motif=liberation_siret) : son code,
 * saisi sur sa page, en est la preuve. Un SIRET usurpé, lui, se libère
 * avec tous ceux de la société révoquée (voir RevoquerSocieteAction), et
 * celui d'un magasin dont le territoire a été mal lu, par sa propre
 * procédure (voir LibererLeSiretAction).
 *
 * Le magasin fautif garde ses documents et le SIRET qu'ils ont signé :
 * la société lui a d'abord donné son bon SIRET (voir ChangerSiretAction,
 * qui garde l'ancien réservé), ou l'a fermé pour cessation, son SIRET
 * libéré ne se reprenant alors plus (siret_libere_le) ; jamais le SIRET
 * d'un magasin ouvert ou fermé temporairement. Sous le verrou du registre
 * du magasin, puis de sa ligne et de sa réservation : la réservation se
 * libère avec son motif, et la libération s'inscrit au journal d'audit,
 * dans la chaîne du magasin, avec l'intervention de l'éditeur. Le SIRET
 * se réserve ensuite pour le magasin qui doit le porter.
 */
class LibererUnSiretSaisiParErreurAction
{
    public const MOTIF = 'SIRET saisi par erreur';

    public const MESSAGE_DEUX_OPERATEURS = 'La libération d\'un SIRET exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_SANS_CODE = 'Aucun code de libération de SIRET n\'a été saisi pour cette société : envoyez d\'abord le courrier (editeur:courrier:envoyer --motif=liberation_siret) au représentant légal, à l\'adresse officielle du siège ; son code, saisi sur sa page, prouve la remise.';

    public const MESSAGE_PAS_RESERVE = 'Ce SIRET n\'est réservé par aucun magasin de cette société.';

    public const MESSAGE_ENCORE_UTILISE = 'Ce SIRET est encore celui d\'un magasin ouvert ou fermé temporairement : la société lui donne d\'abord son bon SIRET, ou le ferme pour cessation.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws VerificationRefuseeException quand la preuve ou les opérateurs manquent
     * @throws LogicException quand ce SIRET ne se libère pas
     */
    public function handle(Societe $societe, string $siret, InterventionDeLEditeur $editeur): Magasin
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new VerificationRefuseeException(self::MESSAGE_DEUX_OPERATEURS);
        }

        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe, $siret, $editeur): Magasin {
            if (! CodeConfirmation::query()->where('motif', MotifCode::LiberationSiret->value)->whereNotNull('utilise_le')->exists()) {
                throw new VerificationRefuseeException(self::MESSAGE_SANS_CODE);
            }

            $reservation = SiretMagasin::query()->where('societe_id', $societe->getKey())->where('siret', $siret)->whereNull('libere_le')->first()
                ?? throw new LogicException(self::MESSAGE_PAS_RESERVE);
            $magasin = Magasin::query()->where('societe_id', $societe->getKey())->findOrFail($reservation->magasin_id);

            return app(TenantContext::class)->runAs($magasin, fn (): Magasin => $this->operateur->pendantLeSysteme(fn (): Magasin => $this->registre->sousVerrou($magasin, function () use ($magasin, $siret, $editeur): Magasin {
                $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();
                $relue = SiretMagasin::query()->where('magasin_id', $verrouille->getKey())->where('siret', $siret)->whereNull('libere_le')->lockForUpdate()->first()
                    ?? throw new LogicException(self::MESSAGE_PAS_RESERVE);

                if ($relue->retire_le === null && ! $verrouille->estFermePourCessation()) {
                    throw new LogicException(self::MESSAGE_ENCORE_UTILISE);
                }

                if ($relue->retire_le === null) {
                    $verrouille->forceFill(['siret_libere_le' => now()])->save();
                }

                $relue->forceFill(['libere_le' => now(), 'motif_liberation' => self::MOTIF])->save();
                $this->journalAudit->inscrire(ActionAuditee::SiretLibere, null, $verrouille, null, [
                    'siret' => $siret,
                    'motif' => self::MOTIF,
                ], $verrouille, $editeur);

                return $verrouille;
            })));
        });
    }
}
