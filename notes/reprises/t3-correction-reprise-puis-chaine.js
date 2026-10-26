export const meta = {
  name: 't3-correction-reprise-puis-chaine',
  description: "Reprend la correction de relecture du lot T3 interrompue, pousse la branche, met à jour les notes de reprise, puis relance la chaîne serie8-lots.js avec PU, TA, EC, L1, A7 et 21",
  phases: [
    { title: 'Correction T3', detail: 'reprise du travail non commité, suites, commit' },
    { title: 'Poussée T3', detail: 'arbre propre, push sans forcer, notes de reprise' },
    { title: 'Chaîne', detail: 'serie8-lots.js : PU, TA, EC, L1, A7, 21' },
  ],
}

const S = '/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad'
const DEPOT = '/home/user/piliqiediteur'
const BRANCHE = 'claude/saas-invoice-editor-architecture-q6eizh'
const ANCIEN = '/root/.claude/projects/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/subagents/workflows/wf_8eaaa549-68e/agent-ae96fd3e86c34ad2e.jsonl'

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
const POUSSEE = {
  type: 'object',
  properties: { pousse: { type: 'boolean' }, tete: { type: 'string' }, detail: { type: 'string' } },
  required: ['pousse', 'tete', 'detail'],
}

phase('Correction T3')
const correction = await agent(`Ta consigne complète est dans le fichier ${S}/t3/consigne-correction-t3.md : lis-le en entier avant tout (règles impératives, cahier des charges du lot T3, et les 6 défauts confirmés par les deux relectures des commits c4c684a..5f86951). Applique-la exactement.

REPRISE : une première correction de relecture de T3 a été interrompue le 26/10/2026 vers 10:46 UTC par une interruption de la session (pas par un échec). D'après ses notes, elle avait fini ses corrections et ses tests de régression (vérifiés par mutation), puis passé pint, la suite SQLite et la suite PostgreSQL au code 0 sur son code final ; la grille faketime (17 instants, SQLite) tournait quand elle a été coupée, et aucun résultat n'en est sûr. Son travail non commité est resté dans l'arbre : lis git status et git diff (dont deux fichiers nouveaux, LibererLeSiret et LibererLeSiretAction). Son compte rendu brut, si besoin, est dans ${ANCIEN} (lis-en seulement la fin, le fichier est gros). Relis tout ce diff contre les 6 défauts et le cahier des charges : garde ce qui est juste, corrige ce qui ne l'est pas, termine ce qui manque, avec un test de régression pour chaque défaut réel, et explique pourquoi tu écartes les autres. Puis refais toutes les vérifications du protocole sur l'arbre final : php artisan migrate:fresh, suite complète SQLite puis PostgreSQL au code 0, suite SQLite sous faketime à chacun des instants de la grille du README, chacune au code 0, pint. Un seul nouveau commit (ne réécris pas l'historique), titré « Relecture du lot T3 de l’heure locale des magasins ». Ne pousse pas : la poussée est faite après toi.`, { label: 'T3:correction-reprise', phase: 'Correction T3', schema: RAPPORT })

phase('Poussée T3')
const poussee = await agent(`Dans ${DEPOT}, vérifie que l'arbre de travail est propre (git status --short vide ; sinon ne pousse rien et explique pourquoi), puis pousse la branche avec git push -u origin ${BRANCHE}. En cas d'échec réseau uniquement, réessaie jusqu'à 4 fois en attendant 2, 4, 8 puis 16 secondes. Ne modifie aucun fichier, ne crée aucun commit, ne force jamais. Renvoie si la poussée a réussi et le hash de la tête poussée.`, { label: 'T3:poussee', phase: 'Poussée T3', schema: POUSSEE, effort: 'low' })

if (!correction || !correction.commit || !poussee || !poussee.pousse) {
  log('T3 : correction ou poussée non faite, chaîne non relancée, arrêt pour inspection')
  return { correction, poussee, chaine: null }
}

await agent(`Lance exactement cette commande : bash ${S}/pilotage-sync.sh "Après le lot T3" ; puis renvoie les trois dernières lignes de sa sortie. Ne modifie aucun autre fichier, ne touche ni au dépôt ${DEPOT} ni à sa branche ${BRANCHE}. Si la commande échoue, renvoie son message d'erreur sans rien tenter d'autre.`, { label: 'T3:notes', phase: 'Poussée T3', effort: 'low' })

phase('Chaîne')
log(`T3 poussé (${poussee.tete}) ; relance de la chaîne : PU, TA, EC, L1, A7, 21`)
const chaine = await workflow({ scriptPath: `${S}/serie8-lots.js` }, { ouverts: ['PU', 'TA', 'EC', 'L1', 'A7', '21'] })
return { correction, poussee, chaine }
