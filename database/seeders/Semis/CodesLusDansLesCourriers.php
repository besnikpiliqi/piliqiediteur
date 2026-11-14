<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Services\CodesDeConfirmation;
use LogicException;

/**
 * Les codes des courriers recommandés du semis : le vrai générateur (voir
 * CodesDeConfirmation), qui tire chaque code et n'en garde que
 * l'empreinte, et qui retient en plus, le temps du semis, le dernier code
 * tiré, comme le destinataire le lit sur le courrier que le prestataire
 * factice a reçu. Le semis le saisit ensuite sur la page du code (voir
 * SaisirCodeDeConfirmationAction), comme la personne qui ouvre le pli.
 */
final class CodesLusDansLesCourriers extends CodesDeConfirmation
{
    private ?string $dernier = null;

    public function generer(): string
    {
        return $this->dernier = parent::generer();
    }

    /**
     * Le code du dernier courrier parti.
     *
     * @throws LogicException quand aucun courrier n'est encore parti
     */
    public function dernier(): string
    {
        return $this->dernier ?? throw new LogicException('Aucun courrier recommandé n\'est encore parti pendant le semis.');
    }
}
