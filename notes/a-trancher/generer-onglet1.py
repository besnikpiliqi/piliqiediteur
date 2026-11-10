import json, sys, importlib.util
src, dst = sys.argv[1], sys.argv[2]
spec = importlib.util.spec_from_file_location("g", sys.argv[3])
# reprend propre(), titre_fini(), ligne(), ENTETE du générateur sans l'exécuter en entier
code = open(sys.argv[3]).read().split("# Section « Le plus urgent »")[0]
ns = {}
sys_argv = sys.argv
sys.argv = [sys.argv[3], src, dst]
exec(code, ns)
sys.argv = sys_argv
points, tableau, propre, titre_fini = ns["points"], ns["tableau"], ns["propre"], ns["titre_fini"]
DEJA = ns["DEJA"]

def T(*nums):
    return ["T%d" % n for n in nums]

# Renvois expert-comptable : points d'autres sections qui passent aussi par lui
renvois = [("T38", "Juridique et administration"), ("T40", "Juridique et administration"),
           ("T50", "Vos décisions de produit"), ("T51", "Vos décisions de produit"), ("T58", "Vos décisions de produit"),
           ("T60", "Vos décisions de produit"), ("T66", "Vos décisions de produit"), ("T71", "Vos décisions de produit"),
           ("T75", "Vos décisions de produit"), ("T81", "Vos décisions de produit"), ("T91", "API et services extérieurs")]
lignes = ["### Vos autres points qui passent aussi par lui", "",
          "Ces points se décident d'abord dans leur section ; votre expert-comptable les confirmera ensuite.", "",
          "| N° | Le point | Où il est |", "|---|---|---|"]
for t, ou in renvois:
    lignes.append("| %s | %s | %s |" % (t, propre(points[t]["titre"]).rstrip(" ."), ou))
api = ["## API et services extérieurs", "",
       "Comptes à ouvrir, contrats à signer et clés à créer auprès des services dont PILIQI a besoin. Je ne vous demanderai jamais d'écrire un mot de passe ou une clé dans la conversation : vous les saisissez vous-même sur le serveur ou dans les réglages de l'environnement. " + DEJA, "",
       "### SUPER PDP, la plateforme de facture électronique", "", tableau(T(87, 88, 89, 90, 91, 92)), "",
       "### Registres et courrier : INSEE, INPI, La Poste, Ordre des experts-comptables", "", tableau(T(98, 99, 100, 101)), "",
       "### Autres services : e-mails, paiement en ligne, hébergement, signalement des erreurs", "", tableau(T(97, 72, 93, 78))]
open(dst + "/o1-renvois-et-api.md", "w").write("\n".join(lignes) + "\n\n" + "\n".join(api))
tech = ["## Technique et mise en production", "",
        "Ce qui reste à décider pour la mise en production, en dehors des services extérieurs. " + DEJA, "",
        tableau(T(94, 95, 96, 102, 103))]
open(dst + "/o1-technique.md", "w").write("\n".join(tech))
open(dst + "/o1-etapes-suivantes.md", "w").write(tableau(T(68, 69, 70, 71, 73, 74, 75, 76, 77, 79, 80, 81)))
