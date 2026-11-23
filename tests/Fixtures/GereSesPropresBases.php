<?php

namespace Tests\Fixtures;

/**
 * Une classe de tests qui crée, remplit et supprime elle-même ses bases
 * (le registre et les copies du site de démonstration, voir
 * DemonstrationTestCase), hors de toute transaction de RefreshDatabase :
 * ses écritures durables sont voulues, et elle les efface à la fin de
 * chaque test.
 */
interface GereSesPropresBases {}
