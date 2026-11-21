<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Le nom de base d'une copie, lu au registre, n'est pas du motif d'une copie
 * (^demo_copie_[a-z0-9]{26}$ ; lot DEMO-COPIE, C.6.6) : la requête ou le
 * travail s'arrête avant tout usage de ce nom. La garde des copies au
 * registre (copies_nom_du_motif) l'empêche ; ce refus est la seconde
 * barrière, si le registre avait été modifié à la main.
 */
final class NomDeBaseHorsDuMotif extends RuntimeException {}
