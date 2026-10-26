export const meta = {
  name: 't2-correction-reprise-puis-chaine',
  description: "Reprend la correction de relecture du lot T2 interrompue, pousse la branche, puis relance la chaîne serie8-lots.js avec T3, PU, TA, EC, L1, A7 et 21",
  phases: [
    { title: 'Correction T2', detail: 'reprise du travail non commité, suites, CLAUDE.md approuvé, commit' },
    { title: 'Poussée T2', detail: 'arbre propre, push sans forcer' },
    { title: 'Chaîne', detail: 'serie8-lots.js : T3, PU, TA, EC, L1, A7, 21' },
  ],
}

const S = '/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad'
const DEPOT = '/home/user/piliqiediteur'
const BRANCHE = 'claude/saas-invoice-editor-architecture-q6eizh'
const ANCIEN = '/root/.claude/projects/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/subagents/workflows/wf_78d33c79-816/agent-aaf588a21dbc7a0d2.jsonl'

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

phase('Correction T2')
const correction = await agent(`Ta consigne complète est dans le fichier ${S}/t2/consigne-correction-t2.md : lis-le en entier avant tout (règles impératives, cahier des charges du lot T2, et les 7 défauts confirmés par les deux relectures des commits c0acd28..c723e1f). Applique-la exactement.

REPRISE : une première correction de relecture de T2 a été interrompue le 25/10/2026 vers 23:40 UTC par une interruption de la session (pas par un échec), pendant ses suites sous faketime (deux instants avaient fini au code 0 sur 4517 tests, le troisième a été coupé). Son travail non commité est resté dans l'arbre : lis git status et git diff (cast Instant sur expire_le dans VerificationSociete ; HeureRepeteeTest, HorodatageEnUtcTest, NuitDeParisTest, ReglesDuCalendrierTest). Ses notes disaient seulement « les défauts 1 et 2 sont réels » ; son compte rendu brut, si besoin, est dans ${ANCIEN} (lis-en seulement la fin, le fichier est gros). Pour chacun des 7 défauts, établis ce que le diff fait déjà, garde ce qui est juste, corrige ce qui ne l'est pas et termine le reste, avec un test de régression pour chaque défaut réel, et explique pourquoi tu écartes les autres. Le dossier non suivi resources/views/vendor/mail/ est apparu à 23:07-23:08 pendant ce travail : trouve d'où il vient ; s'il n'est qu'un sous-produit (vendor:publish, essai, test), supprime-le ; ne le committe que s'il est voulu par le lot et justifié dans ton compte rendu.

EN PLUS : le point 4 de ${S}/t2/reponses-t2.md (lis tout ce fichier) : l'éditeur a approuvé le texte de la puce « Outillage » de CLAUDE.md proposé par le lot T2. Écris-le tel quel dans CLAUDE.md, à la place de la dernière phrase actuelle de cette puce (« Aucun test ne dépend du hasard… changements d'heure. »), sans rien changer d'autre, dans le même commit.

Puis refais toutes les vérifications du protocole depuis le début, sur l'arbre final : php artisan migrate:fresh, suite complète SQLite puis PostgreSQL au code 0, suite SQLite sous faketime à chacun des instants exigés par le cahier des charges de T2 (ceux du README), chacune au code 0, pint. Un seul nouveau commit (ne réécris pas l'historique), titré « Relecture du lot T2 des horodatages ». Ne pousse pas : la poussée est faite après toi.`, { label: 'T2:correction-reprise', phase: 'Correction T2', schema: RAPPORT })

phase('Poussée T2')
const poussee = await agent(`Dans ${DEPOT}, vérifie que l'arbre de travail est propre (git status --short vide ; sinon ne pousse rien et explique pourquoi), puis pousse la branche avec git push -u origin ${BRANCHE}. En cas d'échec réseau uniquement, réessaie jusqu'à 4 fois en attendant 2, 4, 8 puis 16 secondes. Ne modifie aucun fichier, ne crée aucun commit, ne force jamais. Renvoie si la poussée a réussi et le hash de la tête poussée.`, { label: 'T2:poussee', phase: 'Poussée T2', schema: POUSSEE, effort: 'low' })

if (!correction || !correction.commit || !poussee || !poussee.pousse) {
  log('T2 : correction ou poussée non faite, chaîne non relancée, arrêt pour inspection')
  return { correction, poussee, chaine: null }
}

phase('Chaîne')
log(`T2 poussé (${poussee.tete}) ; relance de la chaîne : T3, PU, TA, EC, L1, A7, 21`)
const chaine = await workflow({ scriptPath: `${S}/serie8-lots.js` }, { ouverts: ['T3', 'PU', 'TA', 'EC', 'L1', 'A7', '21'] })
return { correction, poussee, chaine }
