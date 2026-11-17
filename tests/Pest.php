<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\ServeurPlaywright;
use Tests\Browser\TestCaseDuNavigateur;
use Tests\Browser\TestCaseDuNavigateurDeLaDemonstration;

/*
 * Les tests en navigateur (tests/Browser) : Pest 4 et son module browser,
 * un vrai Chromium piloté par Playwright, sur l'application servie depuis le
 * processus du test (voir TestCaseDuNavigateur). Ceux du site de
 * démonstration (tests/Browser/Demonstration) servent l'application en
 * mode démonstration, sans transaction de RefreshDatabase : leur base par
 * défaut est la base sentinelle, qui reste vide, et chaque copie vit dans
 * sa base (voir TestCaseDuNavigateurDeLaDemonstration). Les tests PHPUnit
 * de tests/Unit et tests/Feature restent des classes PHPUnit, que Pest
 * exécute telles quelles.
 */
$demonstration = __DIR__.'/Browser/Demonstration';

pest()->extend(TestCaseDuNavigateur::class)->use(RefreshDatabase::class)->in(...array_values(array_diff(glob(__DIR__.'/Browser/*') ?: [], [$demonstration])));

pest()->extend(TestCaseDuNavigateurDeLaDemonstration::class)->in($demonstration);

pest()->browser()->timeout(Navigation::delai() * 1000);

ServeurPlaywright::arreterALaFin();
