<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DestinataireOfficiel;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * L'envoi par l'éditeur d'un code de confirmation par courrier recommandé,
 * au nom du représentant légal de l'entreprise, à l'adresse officielle de
 * son siège (voir EnvoyerCodeDeConfirmationAction::parLEditeur()), sans
 * la limite d'un seul nouveau courrier qui vaut pour le propriétaire :
 * pour la vérification d'une inscription qui attend son code, ou pour un
 * motif des procédures postales de l'éditeur. Le code actif du même motif
 * s'annule ; le courrier de l'examen d'un SIREN part à l'entité du SIREN
 * correct (--siren), celui de la libération d'un SIRET au titulaire de ce
 * SIRET (--siret) : le résumé nomme alors l'entreprise et le destinataire
 * officiel à qui il part, lus au registre, et l'opérateur retape ce SIREN
 * ou ce SIRET, pour qu'une faute de frappe n'envoie jamais un recommandé
 * à une entreprise sans rapport avec l'affaire. Le code d'un courrier de
 * procédure se saisit sur la page de son motif, que le courrier indique :
 * sa saisie s'inscrit au registre des vérifications de la société et
 * prévient l'éditeur (voir SaisirCodeDeConfirmationAction::saisirUnCodeDeProcedure()).
 */
#[Signature('editeur:courrier:envoyer {societe : Identifiant ou uuid de la société} {--motif=verification : verification, recuperation, liberation_siret, revocation ou examen_siren} {--siren= : Pour le courrier de l\'examen d\'un SIREN, le SIREN de l\'entité correcte} {--siret= : Pour le courrier de la libération d\'un SIRET, le SIRET à libérer} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Envoie un code de confirmation par courrier recommandé au représentant légal d\'une entreprise')]
class EnvoyerUnCourrier extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $motif = 'Courrier recommandé au représentant légal, à l\'adresse officielle du siège, lus le jour même au registre national des entreprises, motif : '.($this->motif()?->getLabel() ?? 'inconnu').'.';
        $siren = $this->sirenDestinataire();

        if ($siren === null) {
            return [$motif];
        }

        try {
            $destinataire = 'destinataire officiel : '.implode(', ', app(DestinataireOfficiel::class)->du($siren)->lignes()).'.';
        } catch (VerificationRefuseeException $illisible) {
            $destinataire = "destinataire officiel illisible pour le moment ({$illisible->getMessage()}) : la procédure le refusera.";
        }

        $entreprise = $siren === (string) $societe->siren
            ? "Il part à la société elle-même (SIREN {$siren}), titulaire de ce SIRET"
            : "Il part à l'entreprise de SIREN {$siren}, et non à la société";

        return [$motif, "{$entreprise} ; {$destinataire}"];
    }

    protected function valeurAConfirmer(Societe $societe): array
    {
        $siren = $this->identifiant('siren');
        $siret = $this->identifiant('siret');

        return match (true) {
            $this->motif() === MotifCode::ExamenSiren && $siren !== null => ['le SIREN destinataire', $siren],
            $this->motif() === MotifCode::LiberationSiret && $siret !== null => ['le SIRET à libérer', $siret],
            default => parent::valeurAConfirmer($societe),
        };
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $motif = $this->motif() ?? throw new LogicException('Motif inconnu.');
        $code = app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe, $motif, $editeur, $this->identifiant('siren'), $this->identifiant('siret'));

        return "Courrier parti (référence {$code->reference_envoi}).";
    }

    /**
     * Le SIREN de l'entreprise à qui part un courrier qui ne va pas à la
     * société : l'entité correcte d'un examen, le titulaire d'un SIRET à
     * libérer ; null pour tout autre courrier.
     */
    private function sirenDestinataire(): ?string
    {
        $siret = $this->identifiant('siret');

        return match ($this->motif()) {
            MotifCode::ExamenSiren => $this->identifiant('siren'),
            MotifCode::LiberationSiret => $siret === null ? null : substr($siret, 0, 9),
            default => null,
        };
    }

    /**
     * L'option --siren ou --siret, sans espace ; null si elle n'est pas
     * donnée.
     */
    private function identifiant(string $option): ?string
    {
        $valeur = $this->option($option);
        $sansEspace = $valeur === null ? null : preg_replace('/\s+/', '', ValeursLues::texte($valeur));

        return $sansEspace === '' ? null : $sansEspace;
    }

    private function motif(): ?MotifCode
    {
        return MotifCode::tryFrom((string) $this->option('motif'));
    }
}
