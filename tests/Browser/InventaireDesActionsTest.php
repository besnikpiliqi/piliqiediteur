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
                    Infolists\Components\TextEntry::make('avoir.numero')->url('/avoirs/1'),
                    Infolists\Components\TextEntry::make('total_ttc')->money('EUR'),
                    Notifications\Actions\Action::make('voir')->url('/factures'),
                    Hash::make(self::normaliser('code')),
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
        'lien:avoir.numero',
        'action:voir',
    ]);
});

test('la lecture d’une vue trouve chaque clic, envoi, saisie, changement, formulaire et lien', function () {
    $vue = <<<'BLADE'
        <form wire:submit="encaisser">
            <x-filament::input type="search" wire:model.live.debounce.500ms="recherche" />
            <x-filament::input type="date" wire:model="jour" />
            <input wire:change="changerLaQuantite(1, $event.target.value)" />
            <x-filament::button wire:click="ajouterLaSelection">Ajouter</x-filament::button>
            <button type="button" x-on:click="$wire.voirPlus()">Voir plus</button>
            <x-filament::button type="submit">Encaisser</x-filament::button>
        </form>
        <form method="post" action="{{ filament()->getLogoutUrl() }}">
            <x-filament::link tag="button" type="submit">Me déconnecter</x-filament::link>
        </form>
        <a href="{{ $this->adresseDeLaPageDuCode() }}" class="underline">la page
            du code</a>
        <x-filament::link :href="$ligne['url']">{{ $facture->numero }}</x-filament::link>
        <x-filament::button tag="a" :href="$magasin['url']">Ouvrir le magasin</x-filament::button>
        BLADE;

    expect(ActionsDeLInterface::declareesDansLaVue($vue))->toBe([
        'clic:ajouterLaSelection',
        'clic:voirPlus',
        'envoi:encaisser',
        'saisie:recherche',
        'changement:changerLaQuantite',
        'formulaire:{{ filament()->getLogoutUrl() }}',
        'lien:la page du code',
        'lien:{{ $facture->numero }}',
        'lien:Ouvrir le magasin',
    ]);
});
