<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\ServeurPlaywright;
use Tests\Browser\TestCaseDuNavigateur;

/*
 * Les tests en navigateur (tests/Browser) : Pest 4 et son module browser,
 * un vrai Chromium piloté par Playwright, sur l'application servie depuis le
 * processus du test (voir TestCaseDuNavigateur). Les tests PHPUnit de
 * tests/Unit et tests/Feature restent des classes PHPUnit, que Pest exécute
 * telles quelles.
 */
pest()->extend(TestCaseDuNavigateur::class)->use(RefreshDatabase::class)->in('Browser');

pest()->browser()->timeout(Navigation::delai() * 1000);

ServeurPlaywright::arreterALaFin();
