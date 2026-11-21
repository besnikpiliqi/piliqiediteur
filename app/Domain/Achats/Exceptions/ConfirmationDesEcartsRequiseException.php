<?php

namespace App\Domain\Achats\Exceptions;

use App\Domain\Achats\Services\EcartsDUnTaux;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Un HT de ligne ou une TVA de taux saisis au-delà de la tolérance, sans
 * la confirmation qui les garde (décision de l'éditeur du 02/11/2026, T1,
 * voir TvaDuFournisseur) : rien n'est enregistré, et l'écran, ou l'API,
 * dit chaque écart et ce qu'il faut faire. Jamais un refus définitif : la
 * facture existe et se paie à son montant ; confirmés, ces montants
 * s'enregistrent et le document est marqué « TVA à vérifier ».
 */
final class ConfirmationDesEcartsRequiseException extends LogicException
{
    public const CONSIGNE = 'Pour garder les montants de la facture, confirmez-les : le document sera marqué « TVA à vérifier ».';

    /**
     * @param  list<EcartsDUnTaux>  $ecarts
     */
    public function __construct(public readonly array $ecarts)
    {
        parent::__construct(implode(' ', [...array_merge(...array_map(fn (EcartsDUnTaux $ecart): array => $ecart->avertissements(), $ecarts)), self::CONSIGNE]));
    }

    /**
     * Demandée par l'API, la réponse est une erreur 422 qui dit chaque
     * écart ; ailleurs, l'écran la montre (voir GereLesExceptionsMetier).
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'ecarts' => array_map(fn (EcartsDUnTaux $ecart): array => $ecart->enTableau(), $this->ecarts),
        ], 422);
    }
}
