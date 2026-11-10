export const meta = {
  name: 'piliqi-releve-des-avis-a-demander',
  description: "Relever, depuis le 02/10/2026, toutes les questions que Claude a demandé à l'éditeur de PILIQI de faire vérifier par un professionnel (expert-comptable, juriste, administration, prestataire), même tranchées autrement, puis fusionner et contrôler",
  phases: [
    { title: 'Relevé', detail: '8 lecteurs, chacun sur ses sources' },
    { title: 'Fusion', detail: 'une ligne par question, groupée par professionnel' },
    { title: 'Contrôle', detail: 'exhaustivité ; justesse et vie privée' },
    { title: 'Final', detail: 'corrections appliquées, numéros P1…' },
  ],
}

const S = args.scratch
const N = `${S}/nouvelle-session`
const SORTIE = `${S}/a-trancher/avis`

const LIGNE = [
  'Chaque ligne relevée est un objet JSON avec ces champs (chaînes, « » vides quand on ne sait pas) :',
  '- "id" : ta clé suivie d\'un numéro (ex. "r3-012") ;',
  '- "date_utc" : date et heure (AAAA-MM-JJ HH:MM) où Claude a soulevé la question ou donné l\'avertissement, en UTC ; "date_paris" : la même en heure de Paris (JJ/MM/AAAA à HH h MM ; UTC+2 jusqu\'au 25/10/2026 à 01:00 UTC, UTC+1 ensuite) ;',
  '- "professionnel" : qui doit répondre, parmi "expert-comptable", "juriste ou avocat", "administration" (DGFiP, BOFiP, CNIL, INSEE, INPI, Ordre des experts-comptables, Légifrance…), "prestataire" (SUPER PDP, La Poste, hébergeur, prestataire de sécurité, de paiement, d\'e-mails…), "autre" ; "qui_precis" : le nom précis (ex. "DGFiP (BOFiP)", "SUPER PDP", "CNIL") ;',
  '- "theme" : un thème parmi ' + args.themes.join(', ') + ' ;',
  '- "question" : la question à poser au professionnel, en français simple, telle qu\'il la lira, avec ce qu\'il faut lui montrer (une phrase ou deux) ;',
  '- "avertissement" : ce que Claude a dit à l\'éditeur (son conseil, sa mise en garde, sa recommandation), résumé fidèlement, avec ses mots exacts entre « » quand ils sont conservés ;',
  '- "reponse_editeur" : ce que l\'éditeur a répondu ou décidé sur ce point (ses mots exacts entre « » quand ils sont conservés, avec leur date), ou « » s\'il n\'a pas répondu ;',
  '- "etat" : "ouvert" (pas encore tranché), "tranche-comme-conseille" (il a suivi le conseil, la confirmation du professionnel reste à obtenir), "tranche-autrement" (il a répondu autrement que le conseil, ou sans attendre l\'avis demandé), "regle-a-confirmer" (une règle déjà dans le logiciel ou dans CLAUDE.md, marquée à faire confirmer par le professionnel), "confirme" (le professionnel a déjà répondu ; rare) ;',
  '- "ce_que_fait_piliqi" : ce que fait le logiciel aujourd\'hui ou ce qui est prévu (lot, étape), en une phrase ;',
  '- "renvois" : liste des numéros liés : T… (onglet « Ce qui reste à trancher », fichier resultat/final.json), V… ou Q… (relevé des décisions, onglets23/releve-voix.json), D… (DIRECTIVES.md), codes de questions (TVA3, Q-EC1…) ;',
  '- "sources" : liste de chemins avec ligne ou numéro de message, relatifs au dossier ' + S + ' (ex. "nouvelle-session/tva/reglementation.md:42", "a-trancher/conversation.txt:5120", "nouvelle-session/historique/messages-editeur.md, message 120", "CLAUDE.md:161" pour le dépôt).',
].join('\n')

const REGLES = [
  "Ce que l'on cherche : chaque fois que Claude a demandé, conseillé ou prévu que l'éditeur fasse vérifier, confirmer ou trancher un point par un professionnel ou un organisme (« à voir avec votre expert-comptable », « à faire confirmer par l'expert-comptable de la société », « un avocat », « un juriste », « la CNIL », « la DGFiP », « le BOFiP », « SUPER PDP doit confirmer », « relecture extérieure », « revue fiscale », « rescrit »…), Y COMPRIS quand l'éditeur a ensuite répondu autrement, a tranché lui-même sans attendre l'avis, ou a dit qu'il n'avait pas d'expert-comptable. Ce sont ces cas-là que l'éditeur veut voir réunis dans un tableau, pour poser toutes ces questions à ses professionnels.",
  "Ce que l'on ne relève pas : le mot « expert-comptable » quand il désigne une fonction du logiciel (les cabinets d'expertise comptable, leurs comptes, leurs liens avec les clients, l'export comptable qu'ils téléchargent) et non un avis à demander ; une simple mention d'un texte officiel déjà vérifié par Claude sans rien demander à personne ; une question purement technique que Claude tranche seul.",
  "Pour une même question relevée plusieurs fois dans tes sources, une seule ligne, avec toutes les sources et la date la plus ancienne.",
  "Vie privée : aucun nom de personne réelle, aucune adresse, aucun numéro de SIREN, SIRET, TVA, téléphone ou e-mail réel, aucun secret (clé, jeton, mot de passe) ; le nom d'un organisme public, d'un service (SUPER PDP, La Poste, Mailtrap…) ou des entreprises fictives du bac à sable est permis.",
  "Tu lis seulement ; tu n'écris qu'un fichier de résultat ; tu ne touches jamais au dépôt /home/user/piliqiediteur (lecture seule) ni aux notes. Utilise python3 -I et grep pour chercher (sans accents aussi : « expert comptable », « experte-comptable », « comptable »…), puis lis le contexte de chaque passage trouvé.",
].join('\n')

const LECTEURS = [
  { cle: 'r1', titre: 'registre de l\'onglet 1', sources: `${S}/a-trancher/resultat/final.json : « points » (T1 à T103, chacun avec titre, question, qui, proposition, état, sources), « tranche » (61 lignes déjà tranchées) et « ecartes » (106 points écartés avec leur raison : relis-les tous, un point écarté parce que « déjà tranché » ou « déjà appliqué » peut être une question à faire confirmer par un professionnel). Pour chaque point dont la réponse doit venir d'un professionnel ou d'un organisme, une ligne ; pour T…, mets « T… » dans renvois et l'état "ouvert" s'il est encore ouvert.` },
  { cle: 'r2', titre: 'relevé des décisions et des réponses', sources: `${S}/a-trancher/onglets23/releve-voix.json : « decisions » (V1 à V235, prises par l'éditeur de lui-même) et « questions » (Q1 à Q386, ses réponses aux questions de Claude). Lis-les toutes : cherche dans question, decision, statut_detail et mots chaque avis à demander, chaque règle « à faire confirmer », et surtout chaque cas où l'éditeur a tranché autrement que le conseil de Claude ou sans attendre l'avis d'un professionnel (par exemple : « je n'ai pas d'expert-comptable », un choix contraire à la recommandation, une réponse qui écarte la vérification). Renvois : l'id V… ou Q… et le code de la question.` },
  { cle: 'r3', titre: 'règles écrites', sources: `/home/user/piliqiediteur/CLAUDE.md (lecture seule ; toutes les règles marquées à faire confirmer ou valider par l'expert-comptable de la société, un professionnel ou l'administration, par exemple « cette règle est à faire confirmer par l'expert-comptable de la société le jour où le cas se présente », « une fois la règle confirmée par l'expert-comptable », « validée par l'expert-comptable de la société »), ${N}/historique/DIRECTIVES.md (toutes les directives D1 à D59 et les tableaux : chaque directive où Claude a prévenu, conseillé un avis ou prévu une confirmation) et ${N}/decisions-editeur.md (arbitrages A1 à K10 du 09/10 et leurs notes).` },
  { cle: 'r4', titre: 'conversation, première moitié', sources: `${S}/a-trancher/conversation.txt, de la ligne 1 à la ligne ${args.milieu} (conversation complète, messages de Claude et de l'éditeur, du 01/11 au 05/11 environ). Lis chaque message de Claude où il conseille ou demande l'avis d'un professionnel, puis la réponse de l'éditeur qui suit. Les messages de l'éditeur portent sa date ; date ta ligne à celle du message de Claude.` },
  { cle: 'r5', titre: 'conversation, seconde moitié', sources: `${S}/a-trancher/conversation.txt, de la ligne ${args.milieu} à la fin (du 05/11 environ au 10/11). Même consigne : chaque message de Claude où il conseille ou demande l'avis d'un professionnel, puis la réponse de l'éditeur ; le dernier message de l'éditeur (10/11 à 23 h 08 UTC) demande justement ce relevé et n'est pas lui-même une question à poser.` },
  { cle: 'r6', titre: 'historique d\'octobre', sources: `${N}/historique/messages-editeur.md (342 messages de l'éditeur du 02/10 au 01/11 ; ses réponses), ${N}/historique/resumes-de-session.md (résumés des sessions d'octobre : ce que Claude a dit, conseillé et demandé), ${N}/historique/HISTORIQUE.md et ${N}/pilotage-reprise/notes/historique/resumes-de-session.md (sessions de novembre). Relie chaque conseil de Claude à la réponse de l'éditeur.` },
  { cle: 'r7', titre: 'notes fiscales et de calcul', sources: `les notes fiscales de ${N} : tva/ (reglementation.md, plan-execution.md, conception-finale.md, resume-editeur.md et les autres fichiers du dossier), regles-tva-international.md, calculs/ (normes.md, synthese.md, tva-deductible-et-ca3.md, decision-calc.md, sans-virgule-flottante.md, et les autres), serie8/caisse-clotures-jet-ereporting.md, superpdp-plan.md s'il existe, et tout autre fichier de ${N} sur la TVA, la caisse, l'e-reporting, la facture électronique ou la comptabilité (cherche-les avec grep -ril). N'ouvre pas le dossier pilotage-reprise/notes (copie des mêmes notes).` },
  { cle: 'r8', titre: 'notes société, comptes et cabinets', sources: `les notes de ${N} sur la société, les comptes, la vérification par courrier, les cabinets et les données personnelles : societe/ (avenant-A4-serie6.md, conception-v2-finale.md et les autres), serie8/ (avenant-cabinets.md, avenant-S8V-A4c.md, analyse-avenant-comptes.md, analyse-avenant-ventes.md, plan-serie8.md, equipe-et-achats.md, ecrans-de-vente.md, reponse-editeur.md et les autres), serie9/ (analyse-a-faire.md, critique.md…), ec2/ (cahier-des-charges.definitif.md, decision-expert-comptable-societe.md : attention, le mot « expert-comptable » y désigne surtout la fonction du logiciel ; ne relève que les avis à demander), audits-externes/, fichiers/, postgresql/, et tout autre fichier de ${N} qui parle d'avocat, de juriste, de CNIL, de RGPD, de conservation, de responsabilité, de contrat ou d'attestation (grep -ril). N'ouvre pas le dossier pilotage-reprise/notes (copie des mêmes notes).` },
]

const RESUME = {
  type: 'object',
  properties: { fichier: { type: 'string' }, lignes: { type: 'integer' }, resume: { type: 'string' } },
  required: ['fichier', 'lignes', 'resume'],
}

phase('Relevé')
const releves = await parallel(LECTEURS.map(l => () => agent([
  `Relevé « ${l.titre} » pour l'éditeur de PILIQI (logiciel de facturation et de caisse), qui veut un tableau de toutes les questions que Claude lui a demandé de faire vérifier par un professionnel.`,
  '', REGLES, '', `Tes sources : ${l.sources}`, '', LIGNE, '',
  `Écris un tableau JSON valide de ces lignes dans ${SORTIE}/${l.cle}.json (clé de tes lignes : "${l.cle}"), vérifie qu'il se relit avec python3 -I, puis rends le résumé demandé (fichier, nombre de lignes, et ce que tu as écarté ou dont tu doutes).`,
].join('\n'), { label: `releve:${l.cle}`, phase: 'Relevé', schema: RESUME })))

phase('Fusion')
const fusion = await agent([
  "Fusion d'un relevé pour l'éditeur de PILIQI : toutes les questions que Claude lui a demandé de faire vérifier par un professionnel, même celles qu'il a tranchées autrement.",
  '', REGLES, '',
  `Entrées : les fichiers ${LECTEURS.map(l => `${SORTIE}/${l.cle}.json`).join(', ')} (lis-les tous en entier avec python3 -I). Les mêmes questions y reviennent souvent : fusionne en une seule ligne chaque question qui demande la même chose au même professionnel (même point de droit, de TVA, de caisse…), en gardant toutes les sources et tous les renvois, la date la plus ancienne, l'avertissement le plus complet, la réponse de l'éditeur la plus récente (et l'ancienne si elle a changé), et l'état qui correspond à la situation actuelle. Une question large et une question précise sur le même sujet restent deux lignes si le professionnel doit répondre aux deux.`,
  '', LIGNE, '',
  `Écris dans ${SORTIE}/fusion.json un objet JSON valide {"lignes": [...], "ecartees": [{"id": "rX-NNN", "raison": "…"}]} : chaque ligne garde les champs ci-dessus, avec en plus "ids_releves" (les id des lignes fusionnées). Chaque id relevé doit se retrouver soit dans une ligne, soit dans "ecartees" (avec la raison : doublon exact, ce n'est pas un avis à demander, etc.). Vérifie-le par un script python3 -I avant de rendre le résumé.`,
].join('\n'), { label: 'fusion', phase: 'Fusion', schema: RESUME })

phase('Contrôle')
const [completude, justesse] = await parallel([
  () => agent([
    "Contrôle d'exhaustivité du relevé des questions à faire vérifier par un professionnel (logiciel PILIQI).",
    '', REGLES, '',
    `Relevé à contrôler : ${SORTIE}/fusion.json. Cherche toi-même, dans TOUTES les sources des lecteurs (${S}/a-trancher/resultat/final.json, ${S}/a-trancher/onglets23/releve-voix.json, /home/user/piliqiediteur/CLAUDE.md, ${N}/historique/DIRECTIVES.md, ${N}/decisions-editeur.md, ${S}/a-trancher/conversation.txt, ${N}/historique/messages-editeur.md, ${N}/historique/resumes-de-session.md, ${N}/pilotage-reprise/notes/historique/resumes-de-session.md et toutes les notes de ${N} hors pilotage-reprise/notes), avec grep et des mots variés (expert-comptable, expert comptable, comptable, avocat, juriste, juridique, CNIL, DGFiP, BOFiP, impots.gouv, rescrit, administration fiscale, confirmer, faire valider, relecture extérieure, revue fiscale, certificateur, Ordre, INPI, INSEE, La Poste, SUPER PDP, prestataire, assureur, hébergeur), chaque passage où Claude demande ou conseille un avis professionnel, et vérifie qu'il est couvert par une ligne. Fais attention aux cas où l'éditeur a répondu autrement ou a dit ne pas avoir d'expert-comptable : ils doivent y être.`,
    '', LIGNE, '',
    `Écris dans ${SORTIE}/controle-completude.json un objet JSON valide {"manquantes": [lignes complètes au format ci-dessus, id "c1-NNN"], "a_completer": [{"id": "<id de fusion.json>", "champ": "…", "texte": "…", "raison": "…"}], "verifie": "<ce que tu as cherché et lu>"}, puis rends le résumé (lignes = nombre de manquantes).`,
  ].join('\n'), { label: 'controle:completude', phase: 'Contrôle', schema: RESUME }),
  () => agent([
    "Contrôle de justesse du relevé des questions à faire vérifier par un professionnel (logiciel PILIQI).",
    '', REGLES, '',
    `Relevé à contrôler : ${SORTIE}/fusion.json. Pour CHAQUE ligne : (1) ouvre ses sources et vérifie la date, l'avertissement de Claude et la réponse de l'éditeur ; chaque citation entre « » doit se retrouver mot pour mot dans sa source (aux espaces et guillemets près), sinon corrige-la ou retire les guillemets ; (2) vérifie l'état : "tranche-autrement" seulement si l'éditeur a vraiment répondu autrement que le conseil ou sans attendre l'avis ; "ouvert" seulement si rien n'est tranché ; (3) vérifie « ce que fait PILIQI » contre CLAUDE.md (lecture seule) ; (4) aucune donnée personnelle d'un tiers ni secret ; (5) le professionnel est le bon.`,
    '',
    `Écris dans ${SORTIE}/controle-justesse.json un objet JSON valide {"corrections": [{"id": "<id de fusion.json>", "champ": "…", "texte": "<nouveau texte complet du champ>", "raison": "…"}], "a_retirer": [{"id": "…", "raison": "…"}], "verifie": "<combien de lignes et de citations vérifiées>"}, puis rends le résumé (lignes = nombre de corrections et de retraits).`,
  ].join('\n'), { label: 'controle:justesse', phase: 'Contrôle', schema: RESUME }),
])

phase('Final')
const final = await agent([
  "Version finale du relevé des questions à faire vérifier par un professionnel (logiciel PILIQI).",
  '', REGLES, '',
  `Entrées : ${SORTIE}/fusion.json, ${SORTIE}/controle-completude.json et ${SORTIE}/controle-justesse.json. Applique chaque correction et chaque complément après l'avoir vérifié toi-même dans sa source (refuse ce qui est faux, en le disant) ; ajoute les lignes manquantes vérifiées ; retire les lignes à retirer vérifiées. Puis numérote P1, P2… dans cet ordre : par professionnel (expert-comptable, juriste ou avocat, administration, prestataire, autre), puis par thème, puis par date (la plus ancienne d'abord).`,
  '', LIGNE, '',
  `Écris dans ${SORTIE}/final.json un objet JSON valide {"lignes": [ ... chaque ligne avec "id" = "P…" et "ids_releves" ... ], "journal": "<ce que tu as appliqué, refusé, ajouté, retiré>"}, vérifie-le avec python3 -I (champs présents, états valides, aucune adresse e-mail ni nombre de 9 chiffres), puis rends le résumé (lignes = nombre de lignes finales, et dans le résumé le compte par professionnel et par état).`,
].join('\n'), { label: 'final', phase: 'Final', schema: RESUME })

return { releves, fusion, completude, justesse, final }
