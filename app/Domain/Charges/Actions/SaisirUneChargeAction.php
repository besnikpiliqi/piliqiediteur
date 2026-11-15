<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
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
 * « Écrire les chiffres » d'une charge qui n'est pas encore validée, ou
 * les corriger avant sa validation (décision de l'éditeur du 14/11/2026,
 * lot CHARGES), par qui voit sa charge : ses champs et ses lignes sont
 * réécrits (voir EcritureDUneCharge), et sa pièce remplacée quand une
 * autre est déposée. Écrite par le client, elle redevient « à valider »
 * (une charge renvoyée revient ainsi au comptable, son mot effacé), ou
 * « pièce sans chiffres » sans ses chiffres ; écrite par le comptable avec
 * ses chiffres, elle est validée, ses lignes et sa pièce écrites d'abord.
 * Une charge validée ne s'écrit plus que par « Corriger » (voir
 * CorrigerUneChargeAction). La saisie s'inscrit au journal d'audit, dans
 * la chaîne du magasin qu'elle a ensuite, avec les anciennes et les
 * nouvelles valeurs.
 */
class SaisirUneChargeAction
{
    public const REFUS_VALIDEE = 'Cette charge est validée : seul votre comptable la corrige encore, avec un motif.';

    public const REFUS_SANS_PIECE = 'Cette charge n\'a pas de pièce : déposez-la, ou écrivez ses chiffres.';

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
    public function handle(User $auteur, Societe $societe, string $charge, SaisieDeCharge $saisie, ?PieceDeposee $piece = null, ?string $jeton = null): Charge
    {
        $fichier = $piece === null ? null : $this->pieces->controler($auteur, $societe, null, $piece);
        $parametres = ['charge' => $charge, 'saisie' => $saisie->signature(), 'piece' => $piece === null ? null : PieceDeposee::signatures([$piece])];

        $resultat = $this->gestes->executer($auteur, $societe, ActionsUniques::SAISIR_UNE_CHARGE, $jeton, $parametres, function (AccesAuxCharges $acces) use ($charge, $saisie, $fichier): Charge {
            $relue = $this->gestes->chargeVerrouillee($acces, $charge);

            if ($relue->estValidee()) {
                throw new LogicException(self::REFUS_VALIDEE);
            }

            $avant = ChargesDeLaSociete::valeurs($relue);
            $chiffree = $this->ecriture->remplir($acces, $relue, $saisie);
            $aSaPiece = $fichier !== null || PieceDeCharge::query()->where('charge_id', $relue->getKey())->exists();
            $validee = $acces->estComptable() && $chiffree !== null;

            if (($chiffree === null || $validee) && ! $aSaPiece) {
                throw new InvalidArgumentException($validee ? DeposerUneChargeAction::REFUS_COMPTABLE_SANS_PIECE : self::REFUS_SANS_PIECE);
            }

            // Les lignes et la pièce s'écrivent tant que la charge n'est pas
            // validée : ensuite, la base ne les laisse plus changer que par
            // une correction (voir GardesDesCharges). La validation par le
            // comptable est la dernière écriture.
            $relue->forceFill([
                'etat' => $chiffree === null ? EtatDeLaCharge::PieceSansChiffres : EtatDeLaCharge::AValider,
                'motif_renvoi' => null,
                'renvoyee_par' => null,
                'renvoyee_le' => null,
            ])->save();
            $this->ecriture->ecrireLesLignes($relue, $chiffree, 0);

            if ($fichier !== null) {
                $this->pieces->ranger($acces->compte, $relue, $fichier);
            }

            if ($validee) {
                $relue->forceFill([
                    'etat' => EtatDeLaCharge::Validee,
                    'validee_par' => $acces->valideComme,
                    'validee_par_compte' => $acces->compte->getKey(),
                    'validee_le' => now(),
                ])->save();
            }

            $this->journalAudit->inscrire(ActionAuditee::ChargeSaisie, $acces->compte, $relue->magasin, $avant, ChargesDeLaSociete::valeurs($relue), $relue);

            return $relue;
        });

        return $resultat ?? throw new LogicException(GestesSurLesCharges::CHARGE_INTROUVABLE);
    }
}
