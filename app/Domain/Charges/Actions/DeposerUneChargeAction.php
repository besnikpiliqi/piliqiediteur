<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\EcritureDUneCharge;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use LogicException;

/**
 * Ajouter une charge (décision de l'éditeur du 14/11/2026, lot CHARGES,
 * fenêtre « Ajouter une charge », modèle C2), par qui voit les charges de
 * la société (voir DroitsSurLesCharges) : sa pièce, contrôlée comme une
 * pièce du fournisseur (voir PiecesDesCharges), et, s'il les écrit, ses
 * chiffres (voir EcritureDUneCharge). Déposée par le client, elle naît « à
 * valider », ou « pièce sans chiffres » quand il n'a déposé que la pièce ;
 * ajoutée par le comptable avec ses chiffres et sa pièce, elle naît
 * validée, une TVA ne se déduisant pas sans pièce. Le dépôt s'inscrit au
 * journal d'audit de la société, avec toutes ses valeurs. Un seul dépôt
 * pour un jeton (voir GestesSurLesCharges).
 */
class DeposerUneChargeAction
{
    public const REFUS_SANS_RIEN = 'Déposez la pièce de la charge, ou écrivez ses chiffres.';

    public const REFUS_COMPTABLE_SANS_PIECE = 'Une charge que vous ajoutez est validée à l\'enregistrement : joignez sa pièce, une TVA ne se déduit pas sans pièce.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly EcritureDUneCharge $ecriture,
        private readonly PiecesDesCharges $pieces,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ConfirmationRequiseException
     * @throws FichierRefuseException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, SaisieDeCharge $saisie, ?PieceDeposee $piece = null, ?string $jeton = null): Charge
    {
        if (! $saisie->aDesChiffres() && $piece === null) {
            throw new InvalidArgumentException(self::REFUS_SANS_RIEN);
        }

        $fichier = $piece === null ? null : $this->pieces->controler($auteur, $societe, null, $piece);
        $parametres = ['saisie' => $saisie->signature(), 'piece' => $piece === null ? null : PieceDeposee::signatures([$piece])];

        $charge = $this->gestes->executer($auteur, $societe, ActionsUniques::DEPOSER_UNE_CHARGE, $jeton, $parametres, function (AccesAuxCharges $acces) use ($saisie, $fichier): Charge {
            $charge = new Charge;
            $chiffree = $this->ecriture->remplir($acces, $charge, $saisie);
            $valideeALEnregistrement = $acces->estComptable() && $chiffree !== null;

            if ($valideeALEnregistrement && $fichier === null) {
                throw new InvalidArgumentException(self::REFUS_COMPTABLE_SANS_PIECE);
            }

            $charge->forceFill([
                'etat' => match (true) {
                    $chiffree === null => EtatDeLaCharge::PieceSansChiffres,
                    $valideeALEnregistrement => EtatDeLaCharge::Validee,
                    default => EtatDeLaCharge::AValider,
                },
                'origine' => $acces->origine(),
                'deposee_par' => $acces->compte->getKey(),
            ]);

            if ($valideeALEnregistrement) {
                $charge->forceFill(['validee_par' => $acces->valideComme, 'validee_par_compte' => $acces->compte->getKey(), 'validee_le' => now()]);
            }

            $charge->save();
            $this->ecriture->ecrireLesLignes($charge, $chiffree, 0);
            $this->journalAudit->inscrire(ActionAuditee::ChargeDeposee, $acces->compte, $charge->magasin, null, ChargesDeLaSociete::valeurs($charge), $charge);

            if ($fichier !== null) {
                $this->pieces->ranger($acces->compte, $charge, $fichier);
            }

            return $charge;
        });

        return $charge ?? throw new LogicException(GestesSurLesCharges::CHARGE_INTROUVABLE);
    }
}
