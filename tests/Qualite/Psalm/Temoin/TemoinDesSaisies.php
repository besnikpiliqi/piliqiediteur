<?php

namespace Tests\Qualite\Psalm\Temoin;

use Closure;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Le témoin de l'analyse des flux de données (voir
 * tests/Qualite/Psalm/SaisiesDesEcrans.php) : chaque ligne marquée
 * « attendu : » est un chemin d'une saisie d'un écran vers une requête SQL
 * ou une page, que Psalm doit voir ; aucune autre ligne n'en est un. La CI
 * l'analyse à part (psalm-temoin.xml) et compare ses chemins à ces marques
 * (php tests/Qualite/qualite.php flux-temoin). Jamais exécuté, jamais
 * chargé par l'application.
 */
final class TemoinDesSaisies extends Component
{
    public string $nom = '';

    /** @var array<string, string> */
    public array $data = [];

    #[Locked]
    public string $verrouille = '';

    protected string $interne = '';

    public function proprieteVersUneRequete(): void
    {
        DB::statement("DELETE FROM clients WHERE nom = '".$this->nom."'"); // attendu : TaintedSql
    }

    public function etatDuFormulaireVersUneRequete(): void
    {
        DB::statement("DELETE FROM clients WHERE nom = '".$this->data['nom']."'"); // attendu : TaintedSql
    }

    public function proprieteVersLaPage(): void
    {
        echo $this->nom; // attendu : TaintedHtml, TaintedTextWithQuotes
    }

    public function proprieteRecopieeVersUneRequete(): void
    {
        $nom = $this->nom;
        DB::select('SELECT * FROM clients WHERE nom = '.$nom); // attendu : TaintedSql
    }

    public function formulaireVersUneRequete(Form $formulaire): void
    {
        DB::statement("DELETE FROM clients WHERE nom = '".$formulaire->getState()['nom']."'"); // attendu : TaintedSql
    }

    public function actionVersUneRequete(): Closure
    {
        return function (array $data): void {
            DB::statement("DELETE FROM clients WHERE nom = '".$data['nom']."'"); // attendu : TaintedSql
        };
    }

    public function champVersLaPage(): Closure
    {
        return function (Get $get): void {
            echo $get('nom'); // attendu : TaintedHtml, TaintedTextWithQuotes
        };
    }

    public function sansSaisie(): void
    {
        DB::statement("DELETE FROM clients WHERE nom = '".$this->verrouille."'");
        DB::statement("DELETE FROM clients WHERE nom = '".$this->interne."'");
        DB::table('clients')->where('nom', $this->nom)->delete();
        echo e($this->nom);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
