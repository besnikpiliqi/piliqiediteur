<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/**
 * Un magasin dont les réglages communs à sa société ne sont pas ceux du
 * magasin principal de son SIREN (J2, K5) : il ne les enregistre pas, ne
 * quitte pas sa société, ne la rejoint pas, et n'émet ni facture ni
 * ticket. Le message, rédigé par ReglagesCommunsDeLaSociete, dit chaque
 * réglage qui diffère : avec la valeur du principal à prendre pour un
 * magasin de la société, sans elle pour un magasin qui la rejoint ; jamais
 * le nom ni aucune autre donnée du magasin principal.
 */
class ReglagesCommunsDifferentsException extends LogicException {}
