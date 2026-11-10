import json, re, sys
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

src, dst = sys.argv[1], sys.argv[2]
f = json.load(open(src))
points = {p["id"]: p for p in f["points"]}

CODE = ["superpdp_directory_entry_identifier", "superpdp_send_and_receive", "MAIL_MAILER=log",
        "EDITEUR_OPERATEURS", "PILIQI_EDITEUR_EMAIL", "editeur:*", "CONSERVATION_*", ".env.example",
        "caisse:diagnostiquer", "caisse:generer-cle", "editeur:cabinet:verifier-ordre", "composer run dev"]

def propre(texte):
    t = " ".join(texte.split()).replace("|", "\\|")
    for c in CODE:
        t = t.replace(c, "`" + c + "`")
    return t

def majuscule(texte):
    t = propre(texte)
    return t[:1].upper() + t[1:] if t else t

def titre_fini(texte):
    t = propre(texte).rstrip(" .")
    return t if t.endswith(("?", "!")) else t + "."

def ligne(p):
    prop = propre(p["proposition"])
    if p["etat"] == "applique-a-confirmer":
        prop = "**Déjà appliqué.** " + prop
    return "| %s | **%s** %s | %s | %s | %s |  |" % (
        p["id"], titre_fini(p["titre"]), propre(p["question"]), majuscule(p["qui"]), prop, majuscule(p["bloquant"]))

ENTETE = "| N° | Question | Qui répond | Ma proposition | Bloque à partir de | Votre réponse |\n|---|---|---|---|---|---|"

def tableau(ids):
    return ENTETE + "\n" + "\n".join(ligne(points[i]) for i in ids)

def plage(a, b):
    return ["T%d" % n for n in range(a, b + 1)]

DEJA = "« Déjà appliqué » : le logiciel suit déjà cette proposition, et vous pouvez la changer."

# Section « Le plus urgent »
urgent = list(f["urgent"])
ajout_t43 = {"id": "T43", "pourquoi": "Le lot NAV-2 applique ces choix d'écran : dites-le avant lui si vous préférez autre chose."}
pos = [u["id"] for u in urgent].index("T42") + 1
urgent.insert(pos, ajout_t43)
s2 = ["## Le plus urgent", "",
      "Ces points arrivent bientôt dans la chaîne, ou demandent du temps pour trouver qui répond. Sans réponse, j'applique ma proposition au moment indiqué ; changer d'avis ensuite demanderait un lot de plus. Le détail de chaque point est dans sa section, plus bas.", ""]
for n, u in enumerate(urgent, 1):
    p = points[u["id"]]
    s2.append("%d. **%s — %s** %s" % (n, u["id"], titre_fini(p["titre"]), propre(u["pourquoi"])))
open(dst + "/s2.md", "w").write("\n".join(s2))

# Section expert-comptable
s3 = ["## À voir avec votre expert-comptable", "",
      "Vous n'avez pas encore d'expert-comptable : ces questions attendent celui ou celle que vous choisirez, ou la revue fiscale proposée avant la mise en production (T38), qui peut toutes les confirmer d'un coup. " + DEJA, "",
      "### Pour l'étape 2 (facture électronique et e-reporting)", "", tableau(plage(1, 8)), "",
      "### Pour l'étape 5 (comptabilité et déclaration de TVA)", "", tableau(plage(9, 16)), "",
      "### Avant la mise en production", "", tableau(plage(17, 26)), "",
      "### Le jour où le cas se présente", "", tableau(plage(27, 29))]
open(dst + "/s3.md", "w").write("\n".join(s3))

# Section juridique
s4 = ["## Juridique et administration", "",
      "Textes officiels à relire, obligations légales, et avis d'un juriste ou d'un avocat. " + DEJA, "",
      tableau(plage(30, 40))]
open(dst + "/s4.md", "w").write("\n".join(s4))

# Section produit
s5 = ["## Vos décisions de produit", "",
      "Ce que fait le logiciel, que vous seul décidez. Beaucoup de ces points sont des choix que j'ai faits pour avancer ; vous pouvez les contester à tout moment, et un choix contesté se corrige par un lot. " + DEJA, "",
      "### Avant les prochains lots de la chaîne (NAV-2, EC2)", "", tableau(plage(41, 48)), "",
      "### Au compte rendu du lot 21, le dernier de la chaîne", "", tableau(plage(49, 57)), "",
      "### Chaîne TVA, campagne de tests et étape 2", "", tableau(plage(58, 67)), "",
      "### Étapes suivantes", "", tableau(plage(68, 81)), "",
      "### Déjà appliqué, sans rien bloquer", "", tableau(plage(82, 86))]
open(dst + "/s5.md", "w").write("\n".join(s5))

# Section technique et comptes
s6 = ["## Décisions techniques et comptes à ouvrir", "",
      "Comptes à ouvrir auprès des services extérieurs, contrats, clés et hébergement. Je ne vous demanderai jamais d'écrire un mot de passe ou une clé dans la conversation : vous les saisissez vous-même sur le serveur ou dans les réglages de l'environnement. " + DEJA, "",
      "### SUPER PDP (étape 2)", "", tableau(plage(87, 92)), "",
      "### Mise en production", "", tableau(plage(93, 103))]
open(dst + "/s6.md", "w").write("\n".join(s6))

# Section « Déjà tranché »
PARIS = ZoneInfo("Europe/Paris")
def date_paris(texte):
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", texte)
    a, mo, j = map(int, m.groups())
    h = re.search(r"(\d{1,2}) h(?: (\d{2}))?", texte)
    if h and "UTC" in texte:
        instant = datetime(a, mo, j, int(h.group(1)), int(h.group(2) or 0), tzinfo=timezone.utc).astimezone(PARIS)
        return instant.strftime("%d/%m/%Y")
    return "%02d/%02d/%04d" % (j, mo, a)

MOTS = {"Réponses écrites dans reponses-v2.md, sans citation mot pour mot": "Vos réponses aux points du lot V2, sans citation mot pour mot"}
s7 = ["## Déjà tranché", "",
      "Vos réponses depuis le 09/10/2026, les plus récentes en haut, pour ne pas les trancher deux fois. Les décisions plus anciennes sont déjà écrites dans les règles du projet (CLAUDE.md). Chaque nouvelle réponse que vous me donnez arrive ici.", "",
      "| Date | Décision | Vos mots |", "|---|---|---|"]
for t in f["tranche"]:
    mots = MOTS.get(t["mots_editeur"], t["mots_editeur"])
    s7.append("| %s | **%s** %s | %s |" % (date_paris(t["date"]), titre_fini(t["titre"]), propre(t["decision"]), propre(mots)))
open(dst + "/s7.md", "w").write("\n".join(s7))
