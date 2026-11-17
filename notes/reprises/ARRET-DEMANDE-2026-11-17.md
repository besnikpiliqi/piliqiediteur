# Arrêt demandé par l'éditeur le 17/11/2026 vers 12 h (UTC 11 h 45) — état à la reprise

L'éditeur a demandé d'arrêter tout travail, de tout sauvegarder, puis de « recommencer » (consigne de reprise à préciser par lui).

## Branche et envoi
- Tête poussée : 7c5edc7a (DEMO-COPIE-3 : 33c56754 réparation de la CI de 52dab0ff, 7d521432 le lot, 7c5edc7a sa relecture). CI de 7c5edc7a : ROUGE (Larastan ~30 erreurs ; « Bases modèles » = Playwright non installé ; PHP 8.3/PG17 = 15 échecs). Détail : reprises/cahier-ER-PRO.pro-d76-17-11.md, section « CI rouge de 7c5edc7a ».
- Commits LOCAUX non poussés (DEVIS-RET) : a4ca0549 « Réparation de la CI rouge de 7c5edc7a » et 2b75fa23 « Devis : dépannage à domicile, rétractation et acceptation signée ». Sauvegardés : branche locale sauvegarde/devis-ret-sonnet-17-11, reprises/lot-DEVIS-RET-commits-locaux.patch.
- Travail NON commité de l'agent de correction (25 entrées, corrections des 14 défauts confirmés par les deux relectures Opus) : sauvegardé par reprises/lot-DEVIS-RET-travail-non-commite.patch et par la référence git refs/sauvegardes/devis-ret-travail-non-commite-17-11 (stash create f8e1c2a9). Arbre (write-tree) : 7934af76.
- Lot DEVIS-RET d'origine, avant les lots DEMO-COPIE : branche sauvegarde/devis-ret-a-relire (a3227944) et reprises/lot-DEVIS-RET-a3227944.patch.

## Course et modèles
- Course wf_a558d16d-efa (ouverts [DEVIS-RET]) ARRÊTÉE (TÂCHE wfcq0fe0h) ; l'implémentation (Sonnet 5.5, effort high), les deux relectures (Opus, xhigh) sont faites ; la CORRECTION (Opus, high) était en cours. Reprise possible : Workflow {scriptPath: nouvelle-session/serie9-rapide.js, resumeFromRunId: wf_a558d16d-efa, args {"ouverts":["DEVIS-RET"]}} ; l'agent de correction relit l'arbre non commité.
- serie9-rapide.js : effortDuLot = 'high' (au lieu de xhigh), relectures et correction en model 'opus'. Copies : serie9-rapide.js.avant-sonnet-5-5 (xhigh), serie9-rapide.js.avant-opus-relectures.
- Le modèle de la session est passé à Sonnet 5.5 sur demande de l'éditeur ; les agents d'écriture le suivent.

## Défauts à corriger au prochain lot (dans cahier-ER-PRO.pro-d76-17-11.md)
1. CI rouge de 7c5edc7a (trois causes) — déjà dans la tâche « Réparation de la CI rouge de 7c5edc7a » du commit a4ca0549 (à vérifier : tout y est-il ?).
2. runningConsoleCommand ne lit que argv[1] : `php artisan --env=demonstration journal-audit:epingler-la-cle` refusé.
3. QPDF_BINAIRE absent de .env.demonstration.example ; message « réessayez dans quelques minutes » trompeur.
4. DossiersDeLaDemonstration.php:66 appelle `cp -a` (Unix) : création de copie impossible sous Windows.
5. Rejouer en entier le parcours « lancer les deux sites sur un poste » sur Windows, dépôt neuf.
Contournements donnés à l'éditeur : option --env après la commande ; QPDF_BINAIRE=C:/qpdf/bin/qpdf.exe ; cp.exe de Git for Windows dans le PATH de la fenêtre du travailleur demonstration-admin.

## Poste de l'éditeur (Windows/XAMPP, PostgreSQL dans Docker piliqi-pg)
Trois modèles : « commerce » construit (en service, 42 Mo, 1 174 s) ; « services » et « cabinet » en cours/à refaire (la construction se relance par demonstration:construire-les-modeles --env=demonstration). Une copie de démonstration créée depuis le site réel échoue à la recopie des fichiers (cp) : voir défaut 4.

## Divers
- Questions de l'éditeur sans réponse : aucune en attente (qualité Sonnet/Opus : répartition choisie par Claude, validée « ce que tu penses meilleur »).
- Après DEVIS-RET : GRAPH-AN (maquette à montrer avant), MDP, IDENT, CESSION, NAF25, VIES-DEP, TVA27 (K2), RLS, VER.
- Le point de suivi planifié (trig_014YSbzohxASYadXrqRE6mWb) est DÉSACTIVÉ pendant l'arrêt.
