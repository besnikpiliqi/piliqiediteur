"""Construit les onglets « Vos décisions » et « Mes questions et vos réponses » du document D57/D58.

Entrées : decisions/final.json (relevé du workflow wf_b897639c-407) et voix/*.json (textes réécrits à
« vous » pour l'éditeur et à « je » pour Claude, seulement les champs changés). Sortie : un dossier
onglets23/ avec un fichier Markdown par bloc à remplir et plan.json qui les décrit.

Usage : python3 -I generer-onglets23.py <final.json> <dossier voix> <dossier de sortie>
"""
import glob
import json
import os
import re
import sys
from datetime import date, timedelta

source, dossier_voix, sortie = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(sortie, exist_ok=True)

releve = json.load(open(source))
decisions = {x["id"]: dict(x) for x in releve["decisions"]}
questions = {x["id"]: dict(x) for x in releve["questions"]}

CHAMPS_REECRITS = {"V": {"titre", "decision", "statut_detail"}, "Q": {"question", "decision", "statut_detail"}}

for chemin in sorted(glob.glob(os.path.join(dossier_voix, "[DQ][0-9]*.json"))):
    for entree in json.load(open(chemin)):
        ident = entree["id"]
        prefixe = ident[0]
        cible = decisions if prefixe == "V" else questions
        if ident not in cible:
            raise SystemExit(f"{chemin} : identifiant inconnu {ident}")
        for champ, texte in entree.items():
            if champ == "id":
                continue
            if champ not in CHAMPS_REECRITS[prefixe]:
                raise SystemExit(f"{chemin} : champ {champ} interdit pour {ident}")
            if not isinstance(texte, str) or not texte.strip():
                raise SystemExit(f"{chemin} : texte vide pour {ident}.{champ}")
            cible[ident][champ] = texte

HEURES_DE_PARIS = {
    ("V32", "statut_detail"): [("le 03/10 à 22 h 56", "le 04/10 à 00 h 56")],
    ("V81", "decision"): [("06/10 à 07 h 05 UTC", "06/10 à 09 h 05")],
    ("V187", "statut_detail"): [("le 25/10 vers 18 h 30", "le 25/10 vers 19 h 30")],
    ("V213", "decision"): [("à 22 h 46", "à 23 h 46")],
    ("V222", "statut_detail"): [("le 02/11 à 23 h 40", "le 03/11 à 00 h 40")],
    ("V234", "statut_detail"): [("du 10/11 à 18 h 16", "du 10/11 à 19 h 16")],
    ("Q288", "statut_detail"): [("à 18 h 55", "à 19 h 55")],
    ("Q341", "statut_detail"): [("le 02/11 à 10 h 00", "le 02/11 à 11 h 00")],
    ("Q366", "statut_detail"): [("le 09/11 à 09 h 19", "le 09/11 à 10 h 19")],
    ("Q367", "statut_detail"): [("le 09/11 à 09 h 19", "le 09/11 à 10 h 19"), ("le 09/11 à 23 h 18", "le 10/11 à 00 h 18")],
    ("Q368", "statut_detail"): [("le 09/11 à 09 h 19", "le 09/11 à 10 h 19")],
    ("Q380", "statut_detail"): [("le 09/11 à 23 h 18", "le 10/11 à 00 h 18")],
}
for (ident, champ), remplacements in HEURES_DE_PARIS.items():
    cible = decisions if ident.startswith("V") else questions
    for ancien, nouveau in remplacements:
        if cible[ident][champ].count(ancien) != 1:
            raise SystemExit(f"heure introuvable ou en double : {ident}.{champ} « {ancien} »")
        cible[ident][champ] = cible[ident][champ].replace(ancien, nouveau)

dossier_surcharges = os.path.join(os.path.dirname(os.path.abspath(source)), "..", "surcharges.json")
if os.path.exists(dossier_surcharges):
    surcharges = json.load(open(dossier_surcharges))
    for genre, cible in (("decisions", decisions), ("questions", questions)):
        for ajout in surcharges.get("ajouts", {}).get(genre, []):
            if ajout["id"] in cible:
                raise SystemExit(f"surcharges : {ajout['id']} existe déjà")
            cible[ajout["id"]] = dict(ajout)
    for ident, champs in surcharges.get("champs", {}).items():
        cible = decisions if ident.startswith("V") else questions
        if ident not in cible:
            raise SystemExit(f"surcharges : identifiant inconnu {ident}")
        for champ, valeur in champs.items():
            if champ not in cible[ident]:
                raise SystemExit(f"surcharges : champ inconnu {ident}.{champ}")
            cible[ident][champ] = valeur
    for ident in surcharges.get("retraits", []):
        (decisions if ident.startswith("V") else questions).pop(ident)

CODE = ["superpdp_directory_entry_identifier", "superpdp_send_and_receive", "MAIL_MAILER=log",
        "EDITEUR_OPERATEURS", "PILIQI_EDITEUR_EMAIL", "editeur:*", "CONSERVATION_*", ".env.example",
        "caisse:diagnostiquer", "caisse:generer-cle", "editeur:cabinet:verifier-ordre", "composer run dev"]


def propre(texte, code=True):
    """Une ligne de cellule : espaces resserrés, barres échappées, identifiants techniques entre accents graves."""
    t = " / ".join(" ".join(ligne.split()) for ligne in texte.strip().splitlines() if ligne.strip())
    t = t.replace("|", "\\|").replace("<", "‹").replace(">", "›")
    if code:
        for c in CODE:
            t = t.replace(c, "`" + c + "`")
    return t


def cite(mots):
    """Les mots de l'éditeur, entre guillemets, sans rien y changer d'autre que la mise sur une ligne."""
    return "« " + propre(mots, code=False) + " »"


def fini(texte):
    t = propre(texte).rstrip(" .")
    return t if t.endswith(("?", "!", "…")) else t + "."


ETAT = {"appliquee": "Appliquée", "a-appliquer": "À appliquer", "remplacee": "Remplacée",
        "abandonnee": "Abandonnée", "inconnu": "À vérifier"}


def ou_en_est(x):
    etiquette = ETAT[x["statut"]]
    detail = propre(x["statut_detail"])
    if not detail:
        return f"**{etiquette}.**"
    if detail.lower().startswith(etiquette.lower()):
        return f"**{detail[:len(etiquette)]}**{detail[len(etiquette):]}"
    return f"**{etiquette}.** {detail[:1].upper()}{detail[1:]}"


def jour(date_paris):
    j, m, a = (int(v) for v in date_paris[:10].split("/"))
    return date(a, m, j)


def jj_mm(date_paris):
    return date_paris[:5]


MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre",
        "novembre", "décembre"]


def nom_jour(d):
    return "1er" if d.day == 1 else str(d.day)


def intitule_periode(debut, fin):
    if debut == fin:
        return f"Le {nom_jour(debut)} {MOIS[debut.month - 1]}"
    return f"Du {nom_jour(debut)} au {nom_jour(fin)} {MOIS[fin.month - 1]}"


blocs = []


def ecrire(cle, texte, intention):
    chemin = os.path.join(sortie, cle + ".md")
    open(chemin, "w").write(texte.rstrip("\n") + "\n")
    blocs.append({"cle": cle, "fichier": chemin, "octets": len(texte.encode()), "intention": intention})


# Onglet « Vos décisions » : un mois par section, une semaine (du lundi au dimanche, coupée au mois) par bloc.
liste_d = sorted(decisions.values(), key=lambda x: (x["date_utc"], int(x["id"][1:])))
compte_d = {e: sum(1 for x in liste_d if x["statut"] == e) for e in ETAT}
ENTETE_D = "| Date | Décision | Vos mots | Où elle en est |\n|---|---|---|---|"


def ligne_d(x):
    quand = jj_mm(x["date_paris"]) + (" à " + x["heure_paris"] if x["heure_paris"] else "")
    code = f" ({x['code']})" if x["code"] else ""
    decision = f"**{fini(x['titre'])}** {propre(x['decision'])}{code}"
    return f"| {quand} | {decision} | {cite(x['mots'])} | {ou_en_est(x)} |"


semaines = {}
for x in liste_d:
    d = jour(x["date_paris"])
    lundi = d - timedelta(days=d.weekday())
    cle = (d.year, d.month, lundi)
    semaines.setdefault(cle, []).append(x)

plan_d = []
for (annee, mois, lundi) in sorted(semaines, reverse=True):
    lignes = sorted(semaines[(annee, mois, lundi)], key=lambda x: (x["date_utc"], int(x["id"][1:])), reverse=True)
    debut = max(lundi, date(annee, mois, 1))
    fin = min(lundi + timedelta(days=6), (date(annee + (mois == 12), mois % 12 + 1, 1) - timedelta(days=1)))
    jours = sorted(jour(x["date_paris"]) for x in lignes)
    debut, fin = max(debut, jours[0]), min(fin, jours[-1])
    titre = intitule_periode(debut, fin)
    cle = f"o2-{annee}-{mois:02d}-{lundi.isoformat()}"
    texte = f"### {titre}\n\n{ENTETE_D}\n" + "\n".join(ligne_d(x) for x in lignes)
    ecrire(cle, texte, f"Vos décisions, {titre[0].lower() + titre[1:]} {annee} : {len(lignes)} décisions")
    plan_d.append({"cle": cle, "mois": f"{MOIS[mois - 1].capitalize()} {annee}", "lignes": len(lignes),
                   "ids": [x["id"] for x in lignes]})

intro_d = (
    "Ici, toutes les décisions que vous avez prises de vous-même, sans que je vous pose de question, "
    f"du 2 octobre au 10 novembre 2026 : {len(liste_d)} décisions. Vos réponses à mes questions sont dans l'onglet "
    "<?claude block questions?>. La plus récente est en haut ; les dates et les heures sont celles de Paris. "
    "« Vos mots » reprend exactement ce que vous avez écrit, fautes de frappe comprises ; […] marque un passage coupé, "
    "et une barre oblique, un retour à la ligne.\n\n"
    "« Où elle en est » : **Appliquée** (le logiciel la suit déjà), **À appliquer** (le lot ou l'étape qui la fera est "
    "nommé), **Remplacée** (une décision plus récente l'a changée, elle est nommée), **Abandonnée**, ou **À vérifier**. "
    f"Aujourd'hui : {compte_d['appliquee']} appliquées, {compte_d['a-appliquer']} à appliquer, "
    f"{compte_d['remplacee']} remplacées, {compte_d['abandonnee']} abandonnée et {compte_d['inconnu']} à vérifier.\n\n"
    "Si vous changez d'avis sur l'une d'elles, dites-le-moi dans la conversation ou dans un commentaire : j'écris votre "
    "nouvelle décision en haut, avec sa date et vos mots, je note l'ancienne comme remplacée, et je l'applique."
)

# Onglet « Mes questions et vos réponses » : un mois par section, une série par sous-section.
ordre_serie = {}
for x in releve["questions"]:
    ordre_serie.setdefault(x["serie"], len(ordre_serie))
inconnues = {x["serie"] for x in questions.values()} - set(ordre_serie)
if inconnues:
    raise SystemExit(f"séries inconnues : {sorted(inconnues)}")
liste_q = sorted(questions.values(), key=lambda x: (ordre_serie[x["serie"]], int(x["id"][1:])))
compte_q = {e: sum(1 for x in liste_q if x["statut"] == e) for e in ETAT}
CODES_DE_DIRECTIVE = {"Q1", "Q144", "Q383"}
ENTETE_Q = "| N° | Ma question | Votre réponse | Décision | Où elle en est |\n|---|---|---|---|---|"

series = []
for x in liste_q:
    if not series or series[-1]["nom"] != x["serie"]:
        series.append({"nom": x["serie"], "lignes": []})
    series[-1]["lignes"].append(x)
noms = [s["nom"] for s in series]
if len(noms) != len(set(noms)):
    raise SystemExit("une série revient après une autre : l'ordre des questions est à revoir")


def quand_q(x):
    return x["date_paris"].replace("/2026", "")


def section_serie(s):
    lignes = s["lignes"]
    jours = {jour(x["date_paris"]) for x in lignes}
    moments = {quand_q(x) for x in lignes}
    if len(moments) == 1:
        quand = "Votre réponse : le " if len(lignes) == 1 else "Vos réponses : le "
        chapeau = quand + next(iter(moments)) + "."
        par_ligne = False
    elif len(jours) == 1:
        heures = sorted(quand_q(x)[9:] for x in lignes if " à " in x["date_paris"])
        chapeau = f"Vos réponses : le {jj_mm(lignes[0]['date_paris'])}" + (
            f", de {heures[0]} à {heures[-1]}." if heures and heures[0] != heures[-1] else ".")
        par_ligne = False
    else:
        premier, dernier = min(jours), max(jours)
        chapeau = f"Vos réponses : du {premier:%d/%m} au {dernier:%d/%m} ; la date de chacune suit votre réponse."
        par_ligne = True
    rangs = []
    for rang, x in enumerate(lignes, 1):
        code = x["code"].strip()
        numero = str(rang) if (not code or x["id"] in CODES_DE_DIRECTIVE) else code
        if x["mots"]:
            reponse = cite(x["mots"])
        else:
            reponse = "Choix coché, sans mots écrits."
        if par_ligne:
            reponse += f" (le {quand_q(x)})"
        directive = f" ({x['code']})" if x["id"] in CODES_DE_DIRECTIVE else ""
        rangs.append(f"| {propre(numero)} | {propre(x['question'])} | {reponse} | "
                     f"{propre(x['decision'])}{directive} | {ou_en_est(x)} |")
    return f"### {propre(s['nom'])}\n\n{chapeau}\n\n{ENTETE_Q}\n" + "\n".join(rangs)


PLAFOND = 42000
plan_q = []
groupes = []
chemin_figes = os.path.join(os.path.dirname(os.path.abspath(__file__)), "groupes-figes.json")
figes = json.load(open(chemin_figes)) if os.path.exists(chemin_figes) else None
if figes is not None:
    par_nom = {s["nom"]: s for s in series}
    if sorted(n for g in figes for n in g) != sorted(par_nom):
        raise SystemExit("groupes-figes.json ne couvre pas exactement les séries")
    for noms_du_groupe in figes:
        membres = [par_nom[n] for n in noms_du_groupe]
        d = jour(membres[0]["lignes"][0]["date_paris"])
        textes = [section_serie(s) for s in membres]
        groupes.append({"mois": (d.year, d.month), "series": membres, "textes": textes,
                        "octets": sum(len(t.encode()) for t in textes)})
for s in (reversed(series) if figes is None else []):
    premier = s["lignes"][0]
    d = jour(premier["date_paris"])
    mois = (d.year, d.month)
    texte = section_serie(s)
    octets = len(texte.encode())
    if groupes and groupes[-1]["mois"] == mois and groupes[-1]["octets"] + octets <= PLAFOND:
        groupes[-1]["series"].append(s)
        groupes[-1]["textes"].append(texte)
        groupes[-1]["octets"] += octets
    else:
        groupes.append({"mois": mois, "series": [s], "textes": [texte], "octets": octets})

for n, g in enumerate(groupes, 1):
    annee, mois = g["mois"]
    cle = f"o3-{n:02d}-{annee}-{mois:02d}"
    lignes = sum(len(s["lignes"]) for s in g["series"])
    ecrire(cle, "\n\n".join(g["textes"]),
           f"Mes questions et vos réponses : {len(g['series'])} séries, {lignes} réponses ({MOIS[mois - 1]} {annee})")
    plan_q.append({"cle": cle, "mois": f"{MOIS[mois - 1].capitalize()} {annee}", "series": [s["nom"] for s in g["series"]],
                   "lignes": lignes, "ids": [x["id"] for s in g["series"] for x in s["lignes"]]})

intro_q = (
    "Ici, chaque question que je vous ai posée depuis le 2 octobre, avec votre réponse et la décision qui en est "
    f"sortie : {len(liste_q)} réponses, rangées par série de questions, la plus récente en haut. Dans une série, les "
    "questions gardent l'ordre où je les ai posées. Les décisions que vous avez prises de vous-même sont dans l'onglet "
    "<?claude block decisions?>.\n\n"
    "« N° » est le numéro que portait la question quand je l'ai posée (A1, TVA3, Q-EC1…), ou son rang dans la série "
    "quand elle n'en avait pas. « Votre réponse » reprend exactement vos mots, fautes de frappe comprises ; […] marque "
    "un passage coupé, et une barre oblique, un retour à la ligne. « Choix coché » veut dire que vous avez répondu en "
    "cochant un choix sur une page de questions, sans écrire de mots : la colonne « Décision » dit lequel. Les dates et "
    "les heures sont celles de Paris.\n\n"
    "« Où elle en est » : **Appliquée** (le logiciel la suit déjà), **À appliquer** (le lot ou l'étape qui la fera est "
    "nommé), **Remplacée** (une réponse plus récente l'a changée, elle est nommée). "
    f"Aujourd'hui : {compte_q['appliquee']} appliquées, {compte_q['a-appliquer']} à appliquer et "
    f"{compte_q['remplacee']} remplacées."
)

plan = {
    "decisions": {"intro": intro_d, "mois": sorted({b["mois"] for b in plan_d}, key=lambda m: plan_d.index(
        next(b for b in plan_d if b["mois"] == m))), "blocs": plan_d, "compte": compte_d, "total": len(liste_d)},
    "questions": {"intro": intro_q, "blocs": plan_q, "compte": compte_q, "total": len(liste_q)},
    "fichiers": blocs,
}
json.dump(plan, open(os.path.join(sortie, "plan.json"), "w"), ensure_ascii=False, indent=1)
json.dump({"decisions": [decisions[x["id"]] for x in liste_d], "questions": liste_q},
          open(os.path.join(sortie, "releve-voix.json"), "w"), ensure_ascii=False, indent=1)
for b in blocs:
    print(f"{b['cle']}\t{b['octets']}\t{b['intention']}")
print("total", sum(b["octets"] for b in blocs))
