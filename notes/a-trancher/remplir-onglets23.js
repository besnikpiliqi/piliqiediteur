export const meta = {
  name: 'piliqi-remplir-onglets-decisions-et-questions',
  description: "Remplir, bloc par bloc, les onglets « Vos décisions » et « Mes questions et vos réponses » du document de l'éditeur de PILIQI, depuis des fichiers Markdown déjà vérifiés",
  phases: [{ title: 'Remplissage', detail: 'un agent par bloc en attente' }],
}

const RETOUR = {
  type: 'object',
  properties: {
    cle: { type: 'string' },
    ecrit: { type: 'boolean' },
    rev: { type: 'integer' },
    premier_bloc: { type: 'string' },
    dernier_bloc: { type: 'string' },
    octets_envoyes: { type: 'integer' },
    remarques: { type: 'string' },
  },
  required: ['cle', 'ecrit', 'rev', 'premier_bloc', 'dernier_bloc', 'octets_envoyes', 'remarques'],
}

function consigne(b) {
  return [
    "Tu recopies un fichier Markdown dans un document Claude Docs, à la place d'un bloc « en attente ». Rien d'autre : tu n'écris aucun autre fichier, tu ne touches pas au dépôt /home/user/piliqiediteur, tu n'envoies aucun message.",
    '',
    "1. Charge l'outil : ToolSearch avec la requête « select:mcp__Claude_Docs__update » (max_results 1).",
    `2. Lis le fichier ${b.fichier} en entier avec l'outil Read (${b.octets} octets ; s'il est coupé, relis la suite avec offset jusqu'à la fin). Ne le modifie pas.`,
    '3. Appelle mcp__Claude_Docs__update une seule fois, avec exactement :',
    `   ref = {"object":"node","id":"${b.corps}"}, engine = "prose", container = {"kind":"project","id":"${args.document}"},`,
    `   payload = {"ops":[{"op":"replace","target":{"kind":"blocks","ids":["${b.attente}"]},"ifHash":"${b.hash}","ifRev":${b.rev},"with":{"as":"markdown","from":{"kind":"inline","content":<LE CONTENU DU FICHIER>}}}]}`,
    "   <LE CONTENU DU FICHIER> est le texte du fichier, recopié À L'IDENTIQUE, caractère par caractère : mêmes mots, mêmes fautes de frappe (ce sont les mots exacts de l'éditeur, on ne les corrige jamais), mêmes guillemets « » et apostrophes ’ ou ', mêmes accents, mêmes barres obliques inverses devant | (\\|), mêmes accents graves, mêmes retours à la ligne, sans les numéros de ligne affichés par Read. Ne résume rien, ne reformule rien, n'ajoute rien, ne retire rien, ne change pas l'ordre des lignes du tableau.",
    "4. Si la réponse est un refus : guard_mismatch ou block_gone → n'insiste pas et ne force rien, rends ecrit=false avec le refus dans « remarques ». Un refus de taille (413) → rends ecrit=false avec le refus. Toute autre erreur de forme → corrige la forme de l'appel (pas le contenu) et réessaie une fois.",
    `5. Rends le résumé : cle « ${b.cle} », ecrit, le rev donné par la réponse, l'identifiant du premier et du dernier bloc écrits (lus dans le xml de la réponse), le nombre d'octets du contenu envoyé, et tes remarques (vide si rien à signaler).`,
  ].join('\n')
}

phase('Remplissage')
const resultats = await parallel(args.blocs.map(b => () =>
  agent(consigne(b), { label: `remplir:${b.cle}`, phase: 'Remplissage', schema: RETOUR })))
const manques = resultats.map((r, i) => (r && r.ecrit) ? null : args.blocs[i].cle).filter(Boolean)
if (manques.length) log(`Blocs non écrits : ${manques.join(', ')}`)
return { resultats, manques }
