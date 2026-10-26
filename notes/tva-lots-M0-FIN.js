export const meta = {
  name: 'moteur-tva-charges-complements-M0-FIN',
  description: "Construit le moteur de TVA centralisé, l'espace « Charges du magasin » et les compléments 1 à 16 selon le plan d'exécution relu (lots M0 à FIN), chaque lot implémenté, relu par deux relecteurs adversariaux, corrigé, testé sur SQLite et PostgreSQL, commité et poussé",
  phases: [
    { title: 'Règles et compléments', detail: 'M0, C-a' },
    { title: 'Ventes', detail: 'M1, M2, M3, C-b, M4, M8' },
    { title: 'Achats', detail: 'M5a à M5e, M6' },
    { title: 'Charges', detail: 'CH1, CH2, CH4, CH5, CH6' },
    { title: 'Stock et fin', detail: 'M7, FIN' },
  ],
}

const S = '/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad'
const DEPOT = '/home/user/piliqiediteur'
const BRANCHE = 'claude/saas-invoice-editor-architecture-q6eizh'
const PLAN = `${S}/tva/plan-execution.md`
const DECISIONS = `${S}/tva/plan-execution-decisions.md`

const REGLES = `Contexte : dépôt Laravel 13 / Filament 3.3 / PHP 8.3 ${DEPOT}, branche ${BRANCHE}. PostgreSQL en production, SQLite en développement ; suites et CI sur les deux moteurs. La chaîne société (lots A0 à A7 et 21) est terminée et poussée. Tu construis le moteur de TVA centralisé demandé par l'éditeur, l'espace « Charges du magasin » et le lot de compléments déjà décidé, lot par lot. Documents (lis ceux qui concernent ton lot, en entier) :
- ${PLAN} : le plan d'exécution qui fait foi (relu par trois critiques, 42 défauts corrigés). Section 0 : règles communes à chaque lot ; section 1 : décisions appliquées ; section 2 : textes de CLAUDE.md (lot M0) ; sections 3 et 4 : conception de référence du moteur et des charges ; section 5 : les lots ; annexes A (tests existants touchés), B (codes et messages), D (défauts traités).
- ${DECISIONS} : les réponses de l'éditeur aux points ouverts du plan (arrondis « comme les ventes, partout », charges d'un magasin fermé, avoir lié sur une charge, pas de charges récurrentes) ; elles priment sur le plan : les portes M5c, M5e et CH5 sont levées, le lot CH3 n'existe pas, le lot CH6 existe.
- Décisions verbatim : ${S}/tva/reponses-serie7.md (série 7 ; TVA9 = jamais de cartes cadeaux), ${S}/tva/charges-demande-et-decisions.md (charges), ${S}/decisions/reponses-complements-14-20.md (compléments 1 à 16), ${S}/societe/reponses-serie6.md (organisation de l'équipe, déjà appliquée par la chaîne société).
- CLAUDE.md du dépôt (protocole de rigueur, décisions de l'éditeur, tel que la chaîne société l'a réécrit).
Les lots précédents de cette chaîne et la chaîne société ont changé le code : relis toujours le code actuel (git log, fichiers) avant de modifier ; les numéros de ligne du plan sont indicatifs.

Règles impératives :
- Respecte CLAUDE.md : protocole de rigueur, une seule migration de création par table modifiée sur place puis php artisan migrate:fresh (sur les deux moteurs), réécrire proprement, supprimer ce qui devient inutile (aucun code, colonne, test ou commentaire de compatibilité), isolation des magasins et des sociétés vérifiée à chaque modification (toute lecture ou écriture hors du magasin explicite, filtrée, limitée aux champs nécessaires, testée et déclarée dans la puce 2 de « Produit »). Lis .ai/rules/index.md s'il existe et les règles des fichiers touchés. Lis le skill testing-best-practices (sous **/skills/**) avant d'écrire des tests.
- Montants : calculs en entiers ; les règles d'arrondi validées (prix TTC arrondi une seule fois, R4 répartition par cumul, retours par différence de cumul, achats HT = quantité × PU HT) ne changent pas ; chaque scénario chiffré du plan est testé au centime.
- Caisse sécurisée : documents numérotés signés et inaltérables, registre fiscal, JET, vérificateur d'intégrité ; le format signé ne change qu'au lot que le plan désigne ; le vérificateur reste sans anomalie sur les deux moteurs.
- Droits contrôlés côté serveur dans chaque action, relus sous verrou au moment d'écrire, selon l'organisation de l'équipe de CLAUDE.md (propriétaire, administrateurs, responsables, vendeurs, observateurs, experts-comptables).
- Fournisseurs externes (VIES, INSEE, SUPER PDP…) : derrière une interface avec une implémentation factice ; aucune vraie requête réseau dans les tests ; aucune requête vers SUPER PDP ; clés seulement par variables d'environnement.
- Aucune dépendance ajoutée ou retirée. Ne supprime ni n'affaiblis aucun test pour le faire passer ; un test qui affirme une règle que l'éditeur a changée se réécrit pour affirmer la nouvelle règle, en gardant son intention et en la nommant dans le message de commit (annexe A du plan). Si un échec révèle une règle métier non tranchée (calcul, arrondi, obligation légale), arrête-toi sur ce point et décris-le dans le compte rendu au lieu de choisir.
- Vérification : tests ciblés pendant le travail ; avant chaque commit, php artisan migrate:fresh puis la suite complète sur SQLite PUIS sur PostgreSQL avec les commandes exactes du point 3 du protocole de CLAUDE.md (serveur PostgreSQL local, sinon service postgresql start ; variable PILIQI_PG_MOT_DE_PASSE lue par bash -lc), et contrôle chaque CODE DE SORTIE : il doit valoir 0. Puis vendor/bin/pint --dirty --format agent.
- Fichiers jetables seulement dans tests/Feature/Scratch/ (exclu de git), supprimés ensuite.
- Commits : un ou plusieurs commits pour le lot (chacun vert sur les deux moteurs), messages en français, titre court au présent, corps en puces, puis une ligne vide et exactement ces deux lignes :
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01K3593fBCSfG8EcbWrZryb6
  Aucun identifiant de modèle. Ne pousse pas (un agent dédié pousse après la relecture). Ne coche rien dans CLAUDE.md sauf au lot FIN.
- Compte rendu final : ce qui est fait, ce qui est laissé et pourquoi, fichiers touchés, résultat des deux suites (nombre de tests, codes de sortie), et dans « commit » la plage « premier..dernier » des hashes de tes commits (ou le hash unique).`

const RAPPORT = {
  type: 'object',
  properties: {
    commit: { type: 'string' },
    tests: { type: 'string' },
    faits: { type: 'array', items: { type: 'string' } },
    laisses: { type: 'array', items: { type: 'string' } },
    resume: { type: 'string' },
  },
  required: ['commit', 'tests', 'faits', 'laisses', 'resume'],
}
const REVUE = {
  type: 'object',
  properties: {
    defauts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          titre: { type: 'string' }, fichier: { type: 'string' }, scenario: { type: 'string' },
          correction: { type: 'string' }, gravite: { type: 'string', enum: ['bloquant', 'important', 'mineur'] },
        },
        required: ['titre', 'fichier', 'scenario', 'correction', 'gravite'],
      },
    },
  },
  required: ['defauts'],
}
const POUSSEE = {
  type: 'object',
  properties: { pousse: { type: 'boolean' }, tete: { type: 'string' }, detail: { type: 'string' } },
  required: ['pousse', 'tete', 'detail'],
}

const OPTIQUES = [
  "Optique FIDÉLITÉ, CALCULS ET DROIT : chaque exigence du lot dans le plan et chaque décision de l'éditeur qu'il applique sont-elles appliquées exactement, sans rien de plus ni de moins ? Chaque scénario chiffré du lot donne-t-il le bon montant au centime (TVA facturée, autoliquidée, déductible, dû au tiers, coût du stock, totaux de charges) ? Règles d'arrondi validées inchangées ? Mentions, catégories et circuits conformes au plan ? Caisse sécurisée intacte (format signé changé seulement au lot prévu, vérificateur sans anomalie) ? Points de relecture du lot, un par un.",
  "Optique SÉCURITÉ, ISOLATION ET QUALITÉ : fuite entre sociétés ou entre magasins (requêtes hors scope, totaux de société, charges communes, pièces justificatives, salaires), droits non contrôlés côté serveur ou non relus sous verrou, élévation de privilèges (vendeur, observateur, expert-comptable qui écrit, administrateur hors portée), actions Filament ou Livewire appelées directement, courses (deux processus réels sous PostgreSQL quand c'est pertinent), jetons R3, pièces téléversées (type, taille, accès), vraies requêtes réseau dans les tests, tests qui ne prouvent rien ou affaiblis, code mort, calcul parallèle laissé hors du moteur, non-respect de CLAUDE.md.",
]

const LOTS = [
  { id: 'M0', phase: 'Règles et compléments', section: '5.1', titre: 'règles écrites dans CLAUDE.md (textes de la section 2, réponses de la série 7 et du plan-execution-decisions)' },
  { id: 'C-a', phase: 'Règles et compléments', section: '5.2', titre: 'compléments 1, 2, 3, 4, 6 et 16' },
  { id: 'M1', phase: 'Ventes', section: '5.3', titre: 'calculatrice et VIES dans le domaine, sans changer un résultat' },
  { id: 'M2', phase: 'Ventes', section: '5.4', titre: 'moteur des ventes, sans changer un centime ni un octet signé' },
  { id: 'M3', phase: 'Ventes', section: '5.5', titre: 'profils, géographie, circuit, gestes ; format signé changé une seule fois' },
  { id: 'C-b', phase: 'Ventes', section: '5.6', titre: 'facture demandée par un particulier et « Transformer en facture »' },
  { id: 'M4', phase: 'Ventes', section: '5.7', titre: 'écrans de vente (panneau du régime, questions, application d office, aucune carte cadeau)' },
  { id: 'M8', phase: 'Ventes', section: '5.8', titre: 'ventes à distance : compteur du seuil de la société, refus au-delà' },
  { id: 'M5a', phase: 'Achats', section: '5.9', titre: 'régime de TVA daté de la société (O7)' },
  { id: 'M5b', phase: 'Achats', section: '5.10', titre: 'fournisseurs et VIES des fournisseurs' },
  { id: 'M5c', phase: 'Achats', section: '5.11', titre: 'moteur des achats (pur), arrondis comme les ventes' },
  { id: 'M5d', phase: 'Achats', section: '5.12', titre: 'achats : schéma, validation et décision figée' },
  { id: 'M5e', phase: 'Achats', section: '5.13', titre: 'devises, TVA étrangère, saisie manuelle, avoirs fournisseur' },
  { id: 'M6', phase: 'Achats', section: '5.14', titre: 'achats : écrans et API' },
  { id: 'CH1', phase: 'Charges', section: '5.15', titre: 'pièces justificatives' },
  { id: 'CH2', phase: 'Charges', section: '5.16', titre: 'charges du magasin (y compris les charges d un magasin fermé par la société, décision 6.1 a)' },
  { id: 'CH4', phase: 'Charges', section: '5.18', titre: 'salaires en totaux mensuels' },
  { id: 'CH5', phase: 'Charges', section: '5.19', titre: 'charges communes réparties par cumul au centime' },
  { id: 'CH6', phase: 'Charges', section: '5.20', titre: 'avoirs reçus sur des charges (avoir lié, décision 6.1 b)' },
  { id: 'M7', phase: 'Stock et fin', section: '5.21', titre: 'coût et valeur du stock' },
  { id: 'FIN', phase: 'Stock et fin', section: '5.22', titre: 'étape 1 cochée (vérification transverse, matrice décision → test, README à jour)', reprise: `COMPLÉMENT : ajoute aussi au README les points de ${S}/a-faire-apres-chaine.md qui concernent l'installation (clé du journal d'audit, note Windows/XAMPP : extensions PHP dont pdo_pgsql, OPENSSL_CONF, ne jamais lancer composer update) et améliore le message d'erreur de génération de clé de la caisse et caisse:diagnostiquer comme ce fichier l'indique, avec leurs tests ; le point 2 de ce fichier (composer run dev) n'est fait que si l'éditeur l'a confirmé, ce qui n'est pas le cas.` },
]

const resultats = []
let arret = false
let phaseCourante = null
for (const lot of LOTS) {
  if (arret) {
    break
  }
  if (lot.phase !== phaseCourante) {
    phase(lot.phase)
    phaseCourante = lot.phase
  }
  const intitule = `LOT ${lot.id} DU MOTEUR DE TVA, DES CHARGES ET DES COMPLÉMENTS`
  const consigne = `${intitule} : ${lot.titre}. Applique en entier la section ${lot.section} de ${PLAN} pour ce lot (et les sections qu'elle cite : conception de référence en 3 et 4, tests de référence en 3.23 et 4.13, codes et messages en annexe B, tests existants touchés en annexe A), avec ses tests nommés, ses critères de fin et ses points de relecture, en tenant compte de ${DECISIONS}, qui prime sur le plan.${lot.reprise ? `\n\n${lot.reprise}` : ''}`
  const rapport = await agent(`${REGLES}\n\n${consigne}`, { label: `${lot.id}:implementation`, phase: lot.phase, schema: RAPPORT })
  if (!rapport || !rapport.commit) {
    log(`Lot ${lot.id} : aucun commit, arrêt de la chaîne pour inspection`)
    resultats.push({ lot: lot.id, rapport, revues: null, correction: null, poussee: null })
    arret = true
    break
  }
  const revues = (await parallel(OPTIQUES.map((optique, i) => () => agent(`Relecture adversariale du ou des commits ${rapport.commit} dans ${DEPOT} (${intitule} : ${lot.titre}).\n\nConsignes du lot :\n${REGLES}\n\n${consigne}\n\n${optique}\n\nLis le diff (git show, ou git diff sur la plage) et le code autour. Reproduis chaque défaut par un test jetable dans tests/Feature/Scratch/ (exclu de git) que tu supprimes ensuite, par un essai réel (base PostgreSQL jetable que tu supprimes ensuite), ou par une lecture de code irréfutable. Ne signale que ce que tu as confirmé. Ne modifie aucun fichier suivi par git.`, { label: `${lot.id}:relecture${i + 1}`, phase: lot.phase, schema: REVUE })))).filter(Boolean)
  const defauts = revues.flatMap(r => r.defauts || [])
  let correction = null
  if (defauts.length > 0) {
    correction = await agent(`${REGLES}\n\n${consigne}\n\nCORRECTION DE RELECTURE de ce lot (commits ${rapport.commit}).\n\nDeux relectures adversariales ont confirmé ces défauts (des doublons sont possibles) :\n${JSON.stringify(defauts, null, 2)}\n\nVérifie chacun, corrige ceux qui sont réels avec un test de régression, et explique pourquoi tu écartes les autres. Fais un nouveau commit (ne réécris pas l'historique) titré « Relecture du lot ${lot.id} du moteur de TVA ».`, { label: `${lot.id}:correction`, phase: lot.phase, schema: RAPPORT })
  }
  const poussee = await agent(`Dans ${DEPOT}, vérifie que l'arbre de travail est propre (git status --short vide ; sinon ne pousse rien et explique pourquoi), puis pousse la branche avec git push -u origin ${BRANCHE}. En cas d'échec réseau uniquement, réessaie jusqu'à 4 fois en attendant 2, 4, 8 puis 16 secondes. Ne modifie aucun fichier, ne crée aucun commit, ne force jamais. Renvoie si la poussée a réussi et le hash de la tête poussée.`, { label: `${lot.id}:poussee`, phase: lot.phase, schema: POUSSEE, effort: 'low' })
  resultats.push({ lot: lot.id, rapport, revues, correction, poussee })
}
return resultats
