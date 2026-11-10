export const meta = {
  name: 'piliqi-relire-la-reecriture-a-vous',
  description: "Relire la réécriture à « vous » et à « je » du relevé des décisions de PILIQI : sens gardé, personne juste, restes de troisième personne",
  phases: [{ title: 'Relecture', detail: 'changements ; restes de troisième personne' }],
}

const RESUME = {
  type: 'object',
  properties: {
    fichier: { type: 'string' },
    lus: { type: 'integer' },
    corrections: { type: 'integer' },
    resume: { type: 'string' },
  },
  required: ['fichier', 'lus', 'corrections', 'resume'],
}

const COMMUN = [
  "Contexte : un document privé, écrit pour l'éditeur de PILIQI (un logiciel de facturation et de caisse), liste ses décisions et ses réponses aux questions de Claude. Le document le vouvoie (« vous ») et Claude y parle à la première personne (« je »). Le champ « mots » contient les mots exacts de l'éditeur : on n'y touche jamais, et toute citation entre « » dans un autre champ reste telle quelle, fautes comprises.",
  "Règles de la réécriture : l'éditeur, quand il est la personne qui décide ou qui parle, devient « vous/votre/vos » ; Claude devient « je/me/mon/ma/mes » ; « l'éditeur » reste quand il nomme un rôle ou une chose du logiciel (procédure de l'éditeur, attestation de l'éditeur, administration de l'éditeur, opérateurs de l'éditeur, comptes de l'éditeur, une règle de CLAUDE.md qui parle de l'éditeur) ; « il », « lui », « son », « sa », « ses », « leur » qui désignent autre chose (un produit, un document, un compte, un magasin, le propriétaire d'une société cliente, un vendeur…) restent tels quels. Aucun fait, aucune date, aucun numéro, aucun nom de lot ne change.",
  "Tu lis des fichiers et tu écris un seul fichier de résultat ; tu ne touches à rien d'autre, et jamais au dépôt /home/user/piliqiediteur (lecture seule, par exemple CLAUDE.md pour vérifier qu'un terme est un rôle du logiciel).",
].join('\n')

const changements = [
  COMMUN,
  '',
  `Fichier à relire : ${args.changements} : une liste de champs réécrits, chacun avec « avant » (texte d'origine, à la troisième personne) et « apres » (texte réécrit). Relis-les TOUS, un par un. Pour chacun, vérifie : (1) le sens est le même, aucun fait ajouté ni retiré, aucune date, heure, aucun numéro ni nom de lot changé ; (2) la personne est juste partout (« vous » pour l'éditeur, « je » pour Claude), sans « vous » mis à tort sur une autre personne (le propriétaire d'une société cliente, un vendeur, un cabinet…) ; (3) l'accord des verbes et des participes et le français sont justes ; (4) aucune citation entre « » n'a bougé. Un champ « mots » changé n'est permis que pour une coupe […] : vérifie alors que les mots gardés sont dans l'ordre d'origine.`,
  '',
  `Écris dans ${args.sortie_changements} un JSON valide : {"lus": <nombre de champs relus>, "corrections": [{"id": "V12", "champ": "decision", "texte": "<le texte complet corrigé du champ, à partir de « apres »>", "raison": "…"}]}. Ne propose une correction que pour un vrai défaut (sens changé, mauvaise personne, faute d'accord ou de français, citation touchée) ; pas de reformulation de goût. Vérifie que le fichier se relit avec python3 -I, puis rends le résumé demandé.`,
].join('\n')

const restes = [
  COMMUN,
  '',
  `Fichier à juger : ${args.restes} : chaque phrase des textes rédigés par Claude (titre, question, décision, où elle en est) où restent « l'éditeur », « Claude », « il(s) », « lui », « son », « sa », « ses », « leur(s) », hors citations. Le texte complet de chaque entrée est dans ${args.releve} (« decisions » et « questions », par « id »). Juge-les TOUTES, une par une : le mot désigne-t-il l'éditeur ou Claude, alors qu'il devrait être « vous » ou « je » ? Si oui, propose le texte complet corrigé du champ (pris dans ${args.releve}, et non la seule phrase), avec la personne corrigée et les accords faits, sans rien changer d'autre.`,
  '',
  `Écris dans ${args.sortie_restes} un JSON valide : {"lus": <nombre de phrases jugées>, "corrections": [{"id": "Q40", "champ": "question", "texte": "<texte complet corrigé du champ>", "raison": "…"}]}. Plusieurs phrases du même champ → une seule correction, qui les corrige toutes. Vérifie que le fichier se relit avec python3 -I, puis rends le résumé demandé.`,
].join('\n')

phase('Relecture')
const parts = args.parts_restes.map((p, i) => () =>
  agent(restes.split(args.restes).join(p.entree).split(args.sortie_restes).join(p.sortie),
    { label: `relecture:restes-${i + 1}`, phase: 'Relecture', schema: RESUME }))
const [a, ...b] = await parallel([
  () => agent(changements, { label: 'relecture:changements', phase: 'Relecture', schema: RESUME }),
  ...parts,
])
return { changements: a, restes: b }
