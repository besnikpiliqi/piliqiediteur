"""Écrit l'onglet « À faire vérifier par un professionnel » (D59) depuis avis/final.json.

Usage : python3 -I generer-onglet4.py <final.json> <dossier de sortie>
Écrit intro.md (texte d'entrée, posé par l'opération qui remplace le bloc en attente), b01.md, b02.md… (blocs de
30 000 octets au plus, chacun une ou plusieurs sections) et plan.json (clé, fichier, octets, intention de chaque bloc).
"""
import json
import os
import re
import sys

PLAFOND = 30000
ORDRE = ["expert-comptable", "juriste ou avocat", "administration", "prestataire"]
TITRES = {
    "expert-comptable": "Votre expert-comptable",
    "juriste ou avocat": "Un juriste ou un avocat",
    "administration": "L'administration (DGFiP, CNIL, Ordre des experts-comptables…)",
    "prestataire": "Les prestataires (SUPER PDP, La Poste, INPI…)",
}
ETATS = {
    "ouvert": "Ouverte.",
    "regle-a-confirmer": "Règle appliquée en attendant, à faire confirmer.",
    "tranche-comme-conseille": "Tranchée comme je le conseillais, à faire confirmer.",
    "tranche-autrement": "Tranchée autrement, malgré mon avertissement.",
}
ENTETE = ("| N° | Question à poser | Ce que je vous ai dit | Votre réponse | Ce que fait PILIQI | Réponse du professionnel |\n"
          "|---|---|---|---|---|---|")


def propre(texte):
    t = (texte or "").strip()
    t = re.sub(r"\s*\n\s*", " / ", t)
    t = t.replace("\\", "\\\\").replace("|", "\\|").replace("*", "\\*").replace("<", "‹").replace(">", "›")
    t = re.sub(r"(?<!\w)_|_(?!\w)", "\\_", t)
    return re.sub(r"[ \t]+", " ", t)


def octets(t):
    return len(t.encode("utf-8"))


def rang(x):
    return int(x["id"][1:])


def ligne(x):
    refs = [r for r in x.get("renvois", []) if re.fullmatch(r"[TQVD]\d+[a-z]?", r)]
    numero = f"{x['id']} ({x['date_paris']})"
    question = f"**À qui :** {propre(x['qui_precis'])}. {propre(x['question'])}"
    if refs:
        question += f" Voir aussi : {', '.join(refs)}."
    reponse = propre(x["reponse_editeur"]) or "Pas encore de réponse."
    votre = f"**{ETATS[x['etat']]}** {reponse}"
    return f"| {numero} | {question} | {propre(x['avertissement'])} | {votre} | {propre(x['ce_que_fait_piliqi'])} |  |"


def sections(lignes):
    """Une section par (professionnel, thème), dans l'ordre des numéros P."""
    par = {}
    for x in sorted(lignes, key=rang):
        par.setdefault((x["professionnel"], x["theme"]), []).append(x)
    return sorted(par.items(), key=lambda kv: rang(kv[1][0]))


def main():
    source, sortie = sys.argv[1], sys.argv[2]
    L = json.load(open(source, encoding="utf-8"))["lignes"]
    assert [rang(x) for x in sorted(L, key=rang)] == list(range(1, len(L) + 1))
    compte = {p: sum(1 for x in L if x["professionnel"] == p) for p in ORDRE}
    assert sum(compte.values()) == len(L)
    etats = {e: sum(1 for x in L if x["etat"] == e) for e in ETATS}
    autrement = [x for x in L if x["etat"] == "tranche-autrement"]
    os.makedirs(sortie, exist_ok=True)
    intro = (
        f"Ici, toutes les questions et demandes que je vous ai faites depuis le 2 octobre pour que vous demandiez l'avis "
        f"d'un professionnel : {len(L)} questions, rangées par professionnel, puis par sujet. Elles y sont même quand vous "
        "les avez déjà tranchées, y compris autrement que je ne le conseillais. Chaque nouvelle question de ce genre "
        "s'y ajoute le jour même.\n\n"
        f"Où en est chacune : {etats['ouvert']} ouvertes, sans décision ; {etats['regle-a-confirmer']} règles que PILIQI "
        f"applique en attendant l'avis ; {etats['tranche-comme-conseille']} tranchées comme je le conseillais, mais à faire "
        f"confirmer ; {etats['tranche-autrement']} tranchées autrement, malgré mon avertissement : "
        + ", ".join(x["id"] for x in autrement) + ".\n\n"
        "« Votre réponse » reprend exactement vos mots, fautes de frappe comprises, et une barre oblique (/) marque un "
        "retour à la ligne. La dernière colonne, « Réponse du professionnel », est vide : elle est pour vous, quand vous "
        "aurez son avis. Les dates sont celles de Paris.\n"
    )
    open(os.path.join(sortie, "intro.md"), "w", encoding="utf-8").write(intro)
    morceaux = []  # (professionnel, texte, intention)
    for (pro, theme), xs in sections(L):
        titre = f"### {theme}"
        lignes = [ligne(x) for x in xs]
        groupe = []
        def rendu(g, suite):
            t = f"{titre}{' (suite)' if suite else ''}\n\n{ENTETE}\n" + "\n".join(g)
            return t
        suite = False
        for l in lignes:
            if groupe and octets(rendu(groupe + [l], suite)) > PLAFOND - 600:
                morceaux.append((pro, rendu(groupe, suite)))
                groupe, suite = [], True
            groupe.append(l)
        morceaux.append((pro, rendu(groupe, suite)))
    blocs = []
    for pro, texte in morceaux:
        entete_pro = f"## {TITRES[pro]} : {compte[pro]} question{'s' if compte[pro] > 1 else ''}\n\n"
        nouveau_pro = not blocs or blocs[-1]["pro"] != pro
        ajout = (entete_pro if nouveau_pro else "") + texte
        if blocs and blocs[-1]["pro"] == pro and octets(blocs[-1]["texte"] + "\n\n" + ajout) <= PLAFOND:
            blocs[-1]["texte"] += "\n\n" + ajout
        else:
            if blocs and blocs[-1]["pro"] != pro:
                ajout = entete_pro + texte
            blocs.append({"pro": pro, "texte": ajout})
    plan = []
    for n, b in enumerate(blocs, 1):
        assert octets(b["texte"]) <= PLAFOND, (n, octets(b["texte"]))
        cle = f"b{n:02d}"
        chemin = os.path.join(sortie, cle + ".md")
        open(chemin, "w", encoding="utf-8").write(b["texte"] + "\n")
        ids = re.findall(r"^\| (P\d+) \(", b["texte"], re.M)
        plan.append({"cle": cle, "fichier": chemin, "octets": octets(b["texte"] + "\n"), "pro": b["pro"],
                     "intention": f"{TITRES[b['pro']]} : questions {ids[0]} à {ids[-1]}", "ids": ids})
    tous = [i for p in plan for i in p["ids"]]
    assert tous == [x["id"] for x in sorted(L, key=rang)], "ordre ou couverture"
    json.dump(plan, open(os.path.join(sortie, "plan.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    for p in plan:
        print(p["cle"], p["octets"], p["intention"])
    print("intro", octets(intro), etats)


main()
