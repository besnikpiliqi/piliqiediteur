<?php

namespace Tests\Qualite\Psalm;

use PhpParser\Node\Expr\Variable;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\ClassLikeStorage;

/**
 * Les saisies des écrans, sources de l'analyse des flux de données de
 * Psalm (décision de l'éditeur du 07/11/2026, D54 ; voir psalm.xml) : presque
 * tout ce qui entre dans PILIQI arrive par un écran Livewire ou Filament,
 * que l'extension Laravel de Psalm ne suit pas. Une donnée est une saisie
 * de la personne, comme une donnée de formulaire :
 *
 * - chaque propriété publique d'un composant Livewire (Livewire\Component et
 *   ses sous-classes : pages et ressources de Filament, l'état d'un
 *   formulaire « $data » compris, propriétés héritées comprises) : le
 *   navigateur en envoie la valeur à chaque requête. Une propriété
 *   verrouillée (#[Locked]), que Livewire refuse de recevoir du navigateur,
 *   n'en est pas une. La source est posée sur la propriété elle-même, et
 *   non sur chacune de ses lectures, que Psalm ne présente pas toutes aux
 *   extensions (une propriété dont il connaît déjà le type) ;
 * - les paramètres « $data » et « $state » d'une fonction anonyme d'un
 *   écran (une classe d'App\Filament ou un composant Livewire) : Filament y
 *   injecte, par leur nom, les données saisies dans la fenêtre d'une action
 *   et l'état d'un champ ;
 * - l'état d'un formulaire de Filament (getState(), getRawState()) et la
 *   lecture d'un autre champ ($get) : déclarés dans
 *   tests/Qualite/Psalm/filament.stub.
 *
 * Le témoin tests/Qualite/Psalm/Temoin, que la CI analyse à part, prouve que
 * chaque chemin d'une de ces saisies vers une requête SQL ou une page se
 * voit (php tests/Qualite/qualite.php flux-temoin).
 */
final class SaisiesDesEcrans implements AddTaintsInterface, AfterClassLikeAnalysisInterface
{
    private const COMPOSANT_LIVEWIRE = 'Livewire\Component';

    private const VERROU_LIVEWIRE = 'Livewire\Attributes\Locked';

    private const PARAMETRES_INJECTES = ['data', 'state'];

    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();
        $classe = $event->getClasslikeStorage();

        if ($codebase->taint_flow_graph === null || ! isset($classe->parent_classes[strtolower(self::COMPOSANT_LIVEWIRE)])) {
            return null;
        }

        foreach (self::proprietesRecuesDuNavigateur($codebase, $classe) as $propriete) {
            $codebase->taint_flow_graph->addSource(
                DataFlowNode::getForPropertyFetch($classe->name.'::$'.$propriete)->setTaints($codebase->getInputTaints()),
            );
        }

        return null;
    }

    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        $expression = $event->getExpr();
        $contexte = $event->getContext();

        if ($contexte->self === null
            || ! $expression instanceof Variable
            || ! is_string($expression->name)
            || ! in_array($expression->name, self::PARAMETRES_INJECTES, true)
        ) {
            return 0;
        }

        return self::parametreInjecteDUnEcran($event, $contexte->self, $expression->name) ? $event->getCodebase()->getInputTaints() : 0;
    }

    /**
     * Les propriétés publiques, non statiques et non verrouillées de ce
     * composant, héritées comprises.
     *
     * @return list<string>
     */
    private static function proprietesRecuesDuNavigateur(Codebase $codebase, ClassLikeStorage $classe): array
    {
        $recues = [];

        foreach ($classe->declaring_property_ids as $nom => $declarante) {
            $propriete = $codebase->classlike_storage_provider->get($declarante)->properties[$nom] ?? null;

            if ($propriete === null || $propriete->is_static === true || $propriete->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            foreach ($propriete->attributes as $attribut) {
                if ($attribut->fq_class_name === self::VERROU_LIVEWIRE) {
                    continue 2;
                }
            }

            $recues[] = $nom;
        }

        return $recues;
    }

    /**
     * $data ou $state, paramètre de la fonction anonyme où il se lit, dans
     * une classe d'App\Filament ou un composant Livewire.
     */
    private static function parametreInjecteDUnEcran(AddRemoveTaintsEvent $event, string $classe, string $nom): bool
    {
        $codebase = $event->getCodebase();
        $source = $event->getStatementsSource();
        $fonction = $source instanceof StatementsAnalyzer ? $source->getSource() : null;

        if (! $fonction instanceof ClosureAnalyzer) {
            return false;
        }

        $ecran = str_starts_with($classe, 'App\\Filament\\')
            || ($codebase->classOrInterfaceExists($classe) && $codebase->classExtends($classe, self::COMPOSANT_LIVEWIRE));

        if (! $ecran) {
            return false;
        }

        foreach ($fonction->getFunctionLikeStorage($source)->params as $parametre) {
            if ($parametre->name === $nom) {
                return true;
            }
        }

        return false;
    }
}
