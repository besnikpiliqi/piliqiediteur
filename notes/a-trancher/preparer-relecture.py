"""Prépare la relecture de la réécriture à « vous » et à « je ».

Lit le relevé d'origine (decisions/final.json) et le relevé rendu par le générateur (onglets23/releve-voix.json),
puis écrit verif/relecture-changements.json (chaque champ changé, avant et après) et verif/relecture-restes.json
(chaque phrase des champs rédigés par Claude où restent « l'éditeur », « Claude », « il », « lui », « son »,
« sa », « ses » : à juger un par un).

Usage : python3 -I preparer-relecture.py <final.json> <releve-voix.json> <dossier verif>
"""
import json
import os
import re
import sys

origine, rendu, sortie = sys.argv[1], sys.argv[2], sys.argv[3]
avant = {x["id"]: x for x in json.load(open(origine))["decisions"] + json.load(open(origine))["questions"]}
r = json.load(open(rendu))
apres = {x["id"]: x for x in r["decisions"] + r["questions"]}

CHAMPS = ("titre", "question", "decision", "statut_detail")
changements = []
for ident, x in apres.items():
    for champ in CHAMPS:
        if champ in x and ident in avant and x[champ] != avant[ident].get(champ):
            changements.append({"id": ident, "champ": champ, "avant": avant[ident][champ], "apres": x[champ]})
    if ident in avant and x["mots"] != avant[ident]["mots"]:
        changements.append({"id": ident, "champ": "mots", "avant": avant[ident]["mots"], "apres": x["mots"]})

MOTIF = re.compile(r"\b(l['’]éditeur|Claude|[Ii]ls?|[Ll]ui|[Ss]on|[Ss]a|[Ss]es|[Ll]eur|[Ll]eurs)\b")
GUILLEMETS = re.compile(r"«[^»]*»")
restes = []
for ident, x in apres.items():
    for champ in CHAMPS:
        texte = x.get(champ, "")
        if not texte:
            continue
        sans_citations = GUILLEMETS.sub(lambda m: " " * len(m.group(0)), texte)
        phrases = re.split(r"(?<=[.;:!?])\s+", sans_citations)
        position = 0
        for phrase in phrases:
            debut = sans_citations.find(phrase, position)
            position = debut + len(phrase)
            mots = sorted({m.group(1) for m in MOTIF.finditer(phrase)})
            if mots:
                restes.append({"id": ident, "champ": champ, "phrase": texte[debut:debut + len(phrase)], "mots": mots})

os.makedirs(sortie, exist_ok=True)
json.dump(changements, open(os.path.join(sortie, "relecture-changements.json"), "w"), ensure_ascii=False, indent=1)
json.dump(restes, open(os.path.join(sortie, "relecture-restes.json"), "w"), ensure_ascii=False, indent=1)
print("changements", len(changements), "restes", len(restes))
