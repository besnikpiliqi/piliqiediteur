<?php

declare(strict_types=1);

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentChiffre;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Exceptions\JournalAuditNonConfigureException;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La TVA d'un achat est celle qu'écrit le document du fournisseur
 * (décision de l'éditeur du 02/11/2026, T1 ; CGI, art. 271, II-1-a ; BOFiP
 * BOI-TVA-DED-40-10-10) : pour chaque taux d'une facture d'achat ou d'un
 * avoir fournisseur, PILIQI enregistre la base et la TVA de la facture ou
 * de la note d'avoir, qui prime (BOI-TVA-DECLA-30-20-20-20 § 260), et garde
 * celle qu'il calcule pour la preuve (voir TauxFactureAchat et
 * TauxAvoirFournisseur).
 *
 * Il accepte sans rien demander un HT de ligne à 0,02 € au plus de sa
 * proposition et une TVA à 1 centime par ligne du taux au plus (voir
 * EcartsDUnTaux). Au-delà, l'écart se confirme : sans la confirmation, rien
 * ne s'enregistre (voir ConfirmationDesEcartsRequiseException) ; confirmé,
 * il s'inscrit au journal d'audit (auteur, montants, écarts) et le document
 * est marqué « TVA à vérifier », marque visible de tous ceux qui voient les
 * achats, cabinet lié compris, qui n'a jamais accès au journal d'audit. Le
 * même écart, enregistré de nouveau, ne se reconfirme pas : sa
 * confirmation, son auteur et son instant restent, par l'empreinte de ce
 * qui a été confirmé ; deux confirmations simultanées du même écart, que
 * le verrou du document fait passer l'une après l'autre, n'en inscrivent
 * qu'une. La marque se lève quand le document est corrigé dans la
 * tolérance ; chaque pose et chaque levée s'inscrit au journal d'audit.
 *
 * L'action qui enregistre le document l'appelle en dernier, sous le verrou
 * du document, après tout autre verrou : le journal d'audit se verrouille
 * après tous les autres (voir JournalAudit).
 */
class TvaDuFournisseur
{
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * Les écarts de chaque taux d'un document chiffré, par taux écrit comme
     * sa colonne (« 20.00 »), dans l'ordre où il apparaît : chaque ligne
     * avec sa description, sa référence, son taux, son HT retenu
     * (montant_ht) et son HT proposé (montant_ht_calcule) ; chaque taux
     * avec sa base (montant_ht), sa TVA retenue (montant_tva) et sa TVA
     * proposée (montant_tva_calcule).
     *
     * @return array<string, EcartsDUnTaux>
     */
    public function ecarts(DocumentChiffre $document): array
    {
        $ecarts = [];

        foreach ($document->parTaux as $taux => $montants) {
            $lignes = array_filter($document->lignes, fn (array $ligne): bool => $this->calculateur->enDecimal($this->calculateur->enCentiemes($ligne['taux_tva'])) === $taux);
            $ecarts[$taux] = new EcartsDUnTaux(
                $this->calculateur->enCentiemes($taux),
                $this->calculateur->enCentiemes($montants['montant_ht']),
                $this->calculateur->enCentiemes($document->tvaProposee($taux)),
                $this->calculateur->enCentiemes($montants['montant_tva']),
                array_values(array_map(fn (array $ligne): array => [
                    'description' => ValeurSimple::enTexte($ligne['description']),
                    'reference' => ValeurSimple::enTexteOuNul($ligne['reference'] ?? null),
                    'ht_calcule' => $this->calculateur->enCentiemes($ligne['montant_ht_calcule']),
                    'ht_retenu' => $this->calculateur->enCentiemes($ligne['montant_ht']),
                ], $lignes)),
            );
        }

        return $ecarts;
    }

    /**
     * Les écarts au-delà de la tolérance que le document enregistré n'a pas
     * déjà confirmés : ceux que son enregistrement demanderait de confirmer
     * (voir retenir()), et que l'écran demande de confirmer.
     *
     * @return list<EcartsDUnTaux>
     */
    public function ecartsAConfirmer(DocumentChiffre $document, FactureAchat|AvoirFournisseur|null $enregistre): array
    {
        $confirmes = $enregistre?->taux()->whereNotNull('ecart_confirme_empreinte')->pluck('ecart_confirme_empreinte')->all() ?? [];

        return array_values(array_filter(
            $this->ecarts($document),
            fn (EcartsDUnTaux $ecart): bool => $ecart->depasseLaTolerance() && ! in_array($ecart->empreinte(), $confirmes, true),
        ));
    }

    /**
     * Enregistre la TVA de chaque taux du document chiffré, à la place de
     * celle qu'il avait : sa base, sa TVA retenue, sa TVA calculée et la TVA
     * saisie ; confirme les écarts nouveaux au-delà de la tolérance, que la
     * saisie doit confirmer, et pose ou lève la marque « TVA à vérifier ».
     *
     * @throws ConfirmationDesEcartsRequiseException
     * @throws JournalAuditNonConfigureException
     */
    public function retenir(User $compte, FactureAchat|AvoirFournisseur $document, DocumentChiffre $chiffre, MontantsDuFournisseur $montants): void
    {
        $ecarts = $this->ecarts($chiffre);
        $existants = $document->taux()->get()->keyBy(fn (Model $taux): string => ValeursLues::texte($taux->getAttribute('taux_tva')));
        $nouveaux = array_filter(
            $ecarts,
            fn (EcartsDUnTaux $ecart, string $taux): bool => $ecart->depasseLaTolerance() && $existants->get($taux)?->getAttribute('ecart_confirme_empreinte') !== $ecart->empreinte(),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($nouveaux !== [] && ! $montants->confirmation) {
            throw new ConfirmationDesEcartsRequiseException(array_values($nouveaux));
        }

        $maintenant = CarbonImmutable::now();

        $this->enregistrerLesTaux($document, $ecarts, $montants, function (Model $ligne, EcartsDUnTaux $ecart, string $taux) use ($compte, $nouveaux, $maintenant): void {
            if (! $ecart->depasseLaTolerance()) {
                $ligne->forceFill(['ecart_confirme_par' => null, 'ecart_confirme_le' => null, 'ecart_confirme_empreinte' => null]);
            } elseif (isset($nouveaux[$taux])) {
                $ligne->forceFill(['ecart_confirme_par' => $compte->getKey(), 'ecart_confirme_le' => $maintenant, 'ecart_confirme_empreinte' => $ecart->empreinte()]);
            }
        });

        if ($nouveaux !== []) {
            $this->journalAudit->inscrire(ActionAuditee::EcartsDuFournisseurConfirmes, $compte, $document->magasin, null, [
                'document' => self::designation($document),
                'ecarts' => array_values(array_map(fn (EcartsDUnTaux $ecart): array => $ecart->enTableau(), $nouveaux)),
            ], $document);
        }

        $this->marquer($compte, $document, array_filter($ecarts, fn (EcartsDUnTaux $ecart): bool => $ecart->depasseLaTolerance()) !== []);
    }

    /**
     * Enregistre, pour un avoir fournisseur encore brouillon, la TVA
     * provisoire de chaque taux et celle qu'écrit la note d'avoir, que son
     * émission retiendra (voir EmettreAvoirFournisseurAction) : aucune
     * confirmation ni marque avant l'émission, qui arrête les montants.
     */
    public function proposer(AvoirFournisseur $avoir, DocumentChiffre $chiffre, MontantsDuFournisseur $montants): void
    {
        $this->enregistrerLesTaux($avoir, $this->ecarts($chiffre), $montants);
    }

    /**
     * La TVA de chaque taux, à la place de celle que le document avait :
     * chaque ligne de taux existante se met à jour, une nouvelle s'ajoute,
     * celle d'un taux disparu s'efface ; $confirmation, quand le document
     * retient ses montants, pose ou retire la confirmation de chacune avant
     * son enregistrement (un brouillon d'avoir fournisseur n'en reçoit
     * aucune).
     *
     * @param  array<string, EcartsDUnTaux>  $ecarts
     * @param  (callable(Model, EcartsDUnTaux, string): void)|null  $confirmation
     */
    private function enregistrerLesTaux(FactureAchat|AvoirFournisseur $document, array $ecarts, MontantsDuFournisseur $montants, ?callable $confirmation = null): void
    {
        $existants = $document->taux()->get()->keyBy(fn (Model $taux): string => ValeursLues::texte($taux->getAttribute('taux_tva')));
        $ordre = 0;

        foreach ($ecarts as $taux => $ecart) {
            $ligne = $existants->pull($taux) ?? $document->taux()->make(['taux_tva' => $taux]);
            $ligne->fill([
                'base_ht' => $this->calculateur->enDecimal($ecart->base),
                'montant_tva' => $this->calculateur->enDecimal($ecart->tvaRetenue),
                'montant_tva_calcule' => $this->calculateur->enDecimal($ecart->tvaCalculee),
                'montant_tva_saisi' => isset($montants->tvaParTaux[$ecart->taux]) ? $this->calculateur->enDecimal($montants->tvaParTaux[$ecart->taux]) : null,
                'ordre' => $ordre++,
            ]);
            if ($confirmation !== null) {
                $confirmation($ligne, $ecart, $taux);
            }

            $ligne->save();
        }

        $existants->each(fn (Model $taux): ?bool => $taux->delete());
    }

    /**
     * Le document du fournisseur tel que le journal d'audit le désigne : sa
     * nature, notre numéro (aucun pour un brouillon) et son n° chez le
     * fournisseur.
     *
     * @return array{nature: string, numero: string|null, numero_fournisseur: string|null}
     */
    public static function designation(FactureAchat|AvoirFournisseur $document): array
    {
        return [
            'nature' => $document instanceof FactureAchat ? 'facture_achat' : 'avoir_fournisseur',
            'numero' => $document->numero,
            'numero_fournisseur' => $document->numero_fournisseur,
        ];
    }

    /**
     * Pose ou lève la marque « TVA à vérifier », et l'inscrit au journal
     * d'audit quand elle change. Un document qui vient d'être créé n'a pas
     * encore lu sa marque (null, la base la met à false) : jamais posée,
     * elle ne se lève pas.
     *
     * @throws JournalAuditNonConfigureException
     */
    private function marquer(User $compte, FactureAchat|AvoirFournisseur $document, bool $aVerifier): void
    {
        if ((bool) $document->tva_a_verifier === $aVerifier) {
            return;
        }

        $document->forceFill(['tva_a_verifier' => $aVerifier])->save();
        $this->journalAudit->inscrire($aVerifier ? ActionAuditee::TvaAVerifierPosee : ActionAuditee::TvaAVerifierLevee, $compte, $document->magasin, null, ['document' => self::designation($document)], $document);
    }
}
