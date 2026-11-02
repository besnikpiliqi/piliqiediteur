<?php

use Tests\Browser\Support\ActionsDeLInterface;
use Tests\Browser\Support\Inventaire;

/*
 * L'inventaire des actions de l'interface (tests/Browser/inventaire-des-
 * actions.php) suit le code : chaque action que le code déclare y figure,
 * chaque entrée y correspond à une action du code, chacune a le lot qui
 * écrit ses tests en navigateur par rôle, et chaque test qu'il cite existe.
 * Ce test lit le code, sans navigateur.
 */

test('chaque action déclarée dans le code figure dans l’inventaire', function () {
    $inventaire = Inventaire::charger();
    $absentes = [];

    foreach (ActionsDeLInterface::lire() as $fichier => $actions) {
        foreach ($actions as $action) {
            if (! isset($inventaire[$fichier][$action])) {
                $absentes[] = $fichier.' : '.$action;
            }
        }
    }

    expect($absentes)->toBe([]);
});

test('chaque entrée de l’inventaire correspond à une action du code', function () {
    $code = ActionsDeLInterface::lire();
    $perimees = [];

    foreach (Inventaire::charger() as $fichier => $actions) {
        foreach (array_keys($actions) as $action) {
            if (! in_array($action, $code[$fichier] ?? [], true)) {
                $perimees[] = $fichier.' : '.$action;
            }
        }
    }

    expect($perimees)->toBe([]);
});

test('chaque entrée de l’inventaire nomme le lot de ses tests et ne cite que des tests qui existent', function () {
    $fautes = [];

    foreach (Inventaire::charger() as $fichier => $actions) {
        foreach ($actions as $action => $entree) {
            if (! in_array($entree['lot'] ?? null, Inventaire::LOTS, true)) {
                $fautes[] = $fichier.' : '.$action.' : lot inconnu';
            }

            foreach ($entree['tests'] ?? [] as $test) {
                [$chemin, $nom] = array_pad(explode(Inventaire::SEPARATEUR, $test, 2), 2, '');

                if (! in_array($nom, Inventaire::testsDuFichier($chemin), true)) {
                    $fautes[] = $fichier.' : '.$action.' : test introuvable « '.$test.' »';
                }
            }
        }
    }

    expect($fautes)->toBe([]);
});

test('la lecture du code trouve chaque action par son nom, son alias ou la classe qui la crée sans nom', function () {
    $code = <<<'PHP'
        <?php
        namespace App\Filament\Exemple;

        use Filament\Forms\Components\Actions\Action as ActionDeChamp;

        final class Exemple
        {
            public function actions(): array
            {
                return [
                    Action::make('emettre')->requiresConfirmation(),
                    ActionDeChamp::make('chercher'),
                    Tables\Actions\EditAction::make(),
                    Tables\Filters\Filter::make('a_encaisser'),
                    Tables\Actions\ActionGroup::make([]),
                    Forms\Components\Select::make('client_id')->searchable(),
                    Forms\Components\TextInput::make('nom')->required(),
                    Forms\Components\Repeater::make('lignes'),
                    Tables\Actions\Action::make('pdf')->url('/pdf'),
                ];
            }
        }
        PHP;

    expect(ActionsDeLInterface::declarees($code))->toBe([
        'action:emettre',
        'action:chercher',
        'action:edit',
        'filtre:a_encaisser',
        'champ:client_id',
        'repeteur:lignes',
        'action:pdf',
    ])->and(ActionsDeLInterface::declarees($code, seulementLesLiens: true))->toBe(['action:edit', 'action:pdf']);
});
