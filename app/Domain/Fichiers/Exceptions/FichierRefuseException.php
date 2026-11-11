<?php

namespace App\Domain\Fichiers\Exceptions;

use App\Domain\Fichiers\Enums\ContenuActif;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Un fichier reçu refusé par ses contrôles (voir ControleDesFichiers) :
 * rien n'est enregistré, et l'écran, ou l'API, dit pourquoi et quoi faire.
 * Il garde, pour le journal d'audit, ce que la personne a déposé : le nom
 * tel que reçu, comme donnée, l'empreinte SHA-256 et la taille du fichier
 * reçu, jamais son contenu, et ce qu'un PDF refusé pour son code actif
 * contient, ou la menace que l'antivirus y a trouvée.
 */
final class FichierRefuseException extends InvalidArgumentException
{
    /**
     * @param  list<ContenuActif>  $contenus
     */
    public function __construct(
        public readonly RaisonDuRefus $raison,
        public readonly string $nomRecu,
        public readonly string $empreinteRecue,
        public readonly int $tailleRecue,
        public readonly array $contenus = [],
        public readonly ?string $menace = null,
    ) {
        parent::__construct($raison->message(NomDeFichier::pourUnMessage($nomRecu), $contenus));
    }

    /**
     * Le refus de ce fichier déposé, pour cette raison.
     *
     * @param  list<ContenuActif>  $contenus
     */
    public static function pour(PieceDeposee $piece, RaisonDuRefus $raison, array $contenus = [], ?string $menace = null): self
    {
        return new self($raison, $piece->nom, hash('sha256', $piece->contenu), strlen($piece->contenu), $contenus, $menace);
    }

    /**
     * Ce que le journal d'audit retient du refus : sa raison, ce qu'un PDF
     * contient ou la menace trouvée, et le fichier reçu (nom, empreinte,
     * taille).
     *
     * @return array{raison: string, detail: list<string>|string|null, nom_recu: string, empreinte_recue: string, taille_recue: int}
     */
    public function valeursAuditees(): array
    {
        return [
            'raison' => $this->raison->value,
            'detail' => $this->contenus !== [] ? array_map(fn (ContenuActif $contenu): string => $contenu->value, $this->contenus) : $this->menace,
            'nom_recu' => $this->nomRecu,
            'empreinte_recue' => $this->empreinteRecue,
            'taille_recue' => $this->tailleRecue,
        ];
    }

    /**
     * Demandé par l'API, le refus est une erreur 422 qui dit sa raison ;
     * ailleurs, l'écran le montre (voir GereLesExceptionsMetier).
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'raison' => $this->raison->value,
        ], 422);
    }
}
