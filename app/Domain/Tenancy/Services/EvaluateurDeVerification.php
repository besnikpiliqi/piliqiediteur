<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dit où en est la vérification d'une société (S6, S7, voir
 * EtatDeVerification), à partir des seules lignes de son registre des
 * vérifications et de trois réponses lues sur son SIREN :
 *
 *   validable ⇔ A ∧ (B1 ∨ B2 ∨ B3 ∨ (B4 ∧ C)) ∧ aucun signal actif
 *               ∧ aucune autre société du SIREN validée
 *   A  : l'entreprise active au répertoire Sirene, et l'établissement de
 *        chaque magasin réel actif vérifié ;
 *   B1 : l'identité du propriétaire vérifiée, rapprochée d'un dirigeant du
 *        registre national des entreprises ;
 *   B2 : son identité vérifiée, et le mandat d'un dirigeant lui-même
 *        vérifié et rapproché ;
 *   B3 : sa connexion vérifiée à la plateforme agréée au même SIREN
 *        (étape 2) ;
 *   B4 : les pièces examinées par l'éditeur, qui ne valent qu'avec C ;
 *   C  : le code d'un courrier recommandé à l'adresse officielle, ou d'une
 *        lettre recommandée électronique qualifiée remise à un dirigeant
 *        identifié, saisi dans le compte.
 * Chaque signal de risque se lève par ce qu'il exige (voir
 * SignauxDeRisque). Les preuves B ne valent que pour le propriétaire
 * actuel : celles d'un autre compte ne prouvent rien de lui.
 *
 * Les seules lectures hors de la société, sur son SIREN et limitées à des
 * booléens, jamais à l'identité d'une autre société : une autre société
 * du SIREN est-elle validée, a-t-elle prouvé sa qualité de représentant,
 * une société de ce SIREN a-t-elle été révoquée ?
 */
class EvaluateurDeVerification
{
    public function __construct(private readonly SignauxDeRisque $signaux) {}

    public function evaluer(Societe $societe): EtatDeVerification
    {
        return app(TenantContext::class)->runAsSociete($societe, function () use ($societe): EtatDeVerification {
            $lignes = VerificationSociete::query()->orderBy('id')->get();
            $proprietaire = $societe->proprietaire()->first();
            $magasinsReelsActifs = $societe->magasinsReels()->where('statut', StatutMagasin::Actif->value)->orderBy('id')->get();
            [$autreValidee, $autrePreuve, $revocation] = $this->lecturesDuSiren($societe);

            $preuves = $this->preuvesDuProprietaire($lignes, $proprietaire);
            $adresseControlee = SignauxDeRisque::adresseControlee($lignes, TypeVerification::Courrier) || SignauxDeRisque::adresseControlee($lignes, TypeVerification::Lre);

            return new EtatDeVerification(
                entrepriseEtEtablissements: $this->entrepriseActive($societe, $lignes) && $magasinsReelsActifs->every(fn ($magasin): bool => $magasin->etablissement_verifie_le !== null),
                preuveDeQualite: match (true) {
                    $preuves['b1'] => EtatDeVerification::PREUVE_IDENTITE_RAPPROCHEE,
                    $preuves['b2'] => EtatDeVerification::PREUVE_MANDAT,
                    $preuves['b3'] => EtatDeVerification::PREUVE_PLATEFORME,
                    $preuves['b4'] && $adresseControlee => EtatDeVerification::PREUVE_PIECES_ET_COURRIER,
                    default => null,
                },
                piecesExaminees: $preuves['b4'],
                identiteVerifiee: $preuves['identite'],
                adresseControlee: $adresseControlee,
                signauxActifs: $this->signaux->actifs($societe, $lignes, $proprietaire, $magasinsReelsActifs, $autrePreuve, $revocation),
                autreSocieteValidee: $autreValidee,
                validee: $societe->estValidee(),
                refusee: $societe->estRefusee(),
                active: $societe->statut === StatutSociete::Active,
            );
        });
    }

    /**
     * @param  Collection<int, VerificationSociete>  $lignes
     * @return array{identite: bool, b1: bool, b2: bool, b3: bool, b4: bool}
     */
    private function preuvesDuProprietaire(Collection $lignes, ?User $proprietaire): array
    {
        $concluantes = $proprietaire === null ? collect() : $lignes->filter(fn (VerificationSociete $ligne): bool => $ligne->estConcluante() && (int) $ligne->beneficiaire_id === $proprietaire->getKey());
        $a = fn (TypeVerification $type): bool => $concluantes->contains(fn (VerificationSociete $ligne): bool => $ligne->type === $type && ($type !== TypeVerification::Manuelle || $ligne->signal === null));
        $identite = $a(TypeVerification::Identite);

        return [
            'identite' => $identite,
            'b1' => $a(TypeVerification::RneRepresentants),
            'b2' => $identite && $a(TypeVerification::Mandat),
            'b3' => $a(TypeVerification::PdpKyb),
            'b4' => $a(TypeVerification::Manuelle),
        ];
    }

    /**
     * Le dernier contrôle de l'entreprise de son SIREN actuel est-il
     * concluant ?
     *
     * @param  Collection<int, VerificationSociete>  $lignes
     */
    private function entrepriseActive(Societe $societe, Collection $lignes): bool
    {
        return $lignes->last(fn (VerificationSociete $ligne): bool => $ligne->type === TypeVerification::SireneUnite && ($ligne->details['siren'] ?? null) === $societe->siren)
            ?->estConcluante() ?? false;
    }

    /**
     * Les trois réponses lues sur le SIREN, hors de la société : une autre
     * société validée et non révoquée ; une autre société, ni révoquée ni
     * refusée, qui a prouvé sa qualité de représentant (identité rapprochée,
     * mandat, plateforme, ou pièces examinées avec le code du courrier) ;
     * une société révoquée. Des booléens seulement.
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private function lecturesDuSiren(Societe $societe): array
    {
        $autres = fn () => Societe::query()->where('siren', $societe->siren)->whereKeyNot($societe->getKey());

        $autrePreuve = DB::table('verifications_societe as preuve')
            ->whereIn('preuve.societe_id', $autres()->where('statut', '<>', StatutSociete::Revoquee->value)->whereNull('refusee_le')->select('id'))
            ->where('preuve.resultat', ResultatVerification::Concluante->value)
            ->where(fn ($preuves) => $preuves
                ->whereIn('preuve.type', [TypeVerification::RneRepresentants->value, TypeVerification::Mandat->value, TypeVerification::PdpKyb->value])
                ->orWhere(fn ($pieces) => $pieces
                    ->where('preuve.type', TypeVerification::Manuelle->value)
                    ->whereNull('preuve.signal')
                    ->whereExists(fn ($courrier) => $courrier->from('verifications_societe as courrier')
                        ->whereColumn('courrier.societe_id', 'preuve.societe_id')
                        ->whereIn('courrier.type', [TypeVerification::Courrier->value, TypeVerification::Lre->value])
                        ->where('courrier.resultat', ResultatVerification::Concluante->value))))
            ->exists();

        return [
            $autres()->whereNotNull('validee_le')->where('statut', '<>', StatutSociete::Revoquee->value)->exists(),
            $autrePreuve,
            $autres()->where('statut', StatutSociete::Revoquee->value)->exists(),
        ];
    }
}
