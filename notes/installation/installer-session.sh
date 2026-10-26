#!/usr/bin/env bash
# installer-session.sh : installe, ou vérifie sans rien modifier, l'environnement
# de travail de PILIQI dans un conteneur Claude Code (Ubuntu 24.04, root).
#
# Ce qu'il met en place, dans l'ordre :
#   1. les paquets système qui manquent (PostgreSQL 16, faketime, tzdata, PHP 8.3
#      et ses extensions, Composer, git, unzip, curl) ;
#   2. le cluster PostgreSQL « main » en UTF8 et C.UTF-8, démarré, port 5432 ;
#   3. le mot de passe de la base de test locale dans ~/.profile
#      (export PILIQI_PG_MOT_DE_PASSE=…, tiré au hasard s'il n'existe pas, jamais affiché) ;
#   4. le rôle piliqi (LOGIN CREATEDB, pas superutilisateur) et la base piliqi_test
#      (propriétaire piliqi, UTF8, C.UTF-8), comme le README du dépôt et la CI ;
#   5. l'exclusion locale des tests jetables (tests/Feature/Scratch*) dans .git/info/exclude ;
#   6. les dépendances Composer (composer install, jamais composer update) ;
#   7. .env (copie de .env.example) et APP_KEY s'ils manquent ;
#   puis il lance la vérification complète.
#
# Usage :
#   bash installer-session.sh                  installe ce qui manque (idempotent)
#   bash installer-session.sh --sans-composer  idem, sans l'étape Composer
#   bash installer-session.sh --verifier       contrôle seulement : ne modifie rien
#   bash installer-session.sh --aide           affiche cette aide
#
# Variable facultative : PILIQI_DEPOT (par défaut /home/user/piliqiediteur).
# Codes de sortie : 0 tout est en place ; 1 il manque quelque chose ; 2 erreur
# d'usage ou étape d'installation impossible.
#
# Garanties : le script ne supprime jamais un cluster ou une base qui contient des
# données, ne lance jamais composer update, n'affiche jamais le mot de passe, ne
# lance ni la suite de tests ni caisse:generer-cle, ne change jamais de branche et ne
# touche pas aux fichiers suivis par git. Le mode --verifier n'écrit aucun fichier : il lit ~/.profile sans
# l'exécuter, lit la version de Composer dans son fichier sans le lancer, n'essaie
# pas faketime (libfaketime crée des objets dans /dev/shm) et ne fait que des
# lectures dans PostgreSQL. Seule exception, hors de root : « sudo -n true ».

set -uo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

DEPOT="${PILIQI_DEPOT:-/home/user/piliqiediteur}"
MAISON="${HOME:-/root}"
PROFIL="$MAISON/.profile"
VERSION_PG_PREFEREE=16
VERSION_PHP_ATTENDUE=8.3
ROLE_PG=piliqi
BASE_PG=piliqi_test
PORT_PG=5432
VARIABLE_MDP=PILIQI_PG_MOT_DE_PASSE
BRANCHE_TRAVAIL=claude/saas-invoice-editor-architecture-q6eizh
LIGNES_EXCLUSION=('tests/Feature/Scratch*' 'tests/Feature/Scratch/')
PAQUETS_PHP=(php8.3-cli php8.3-common php8.3-curl php8.3-gd php8.3-intl php8.3-mbstring
             php8.3-pgsql php8.3-sqlite3 php8.3-xml php8.3-zip php8.3-readline php8.3-opcache)
FUSEAUX_REQUIS=(Europe/Paris America/Guadeloupe America/Martinique Indian/Reunion)

aide() {
  cat <<'FIN_AIDE'
installer-session.sh : environnement de travail de PILIQI (Claude Code, Ubuntu 24.04).

  bash installer-session.sh                  installe ce qui manque (idempotent)
  bash installer-session.sh --sans-composer  idem, sans l'étape Composer
  bash installer-session.sh --verifier       contrôle seulement : ne modifie rien
  bash installer-session.sh --aide           affiche cette aide

Variable facultative : PILIQI_DEPOT (par défaut /home/user/piliqiediteur).
Codes de sortie : 0 tout est en place ; 1 il manque quelque chose ; 2 erreur.
FIN_AIDE
}

MODE=installer
AVEC_COMPOSER=1
for argument in "$@"; do
  case "$argument" in
    --verifier) MODE=verifier ;;
    --sans-composer) AVEC_COMPOSER=0 ;;
    --aide|-h|--help) aide; exit 0 ;;
    *) printf 'Option inconnue : %s (voir --aide)\n' "$argument" >&2; exit 2 ;;
  esac
done

MANQUES=0
ATTENTIONS=0
titre()     { printf '\n== %s\n' "$*"; }
etape()     { printf '\n>> %s\n' "$*"; }
ok()        { printf '  [ok]        %s\n' "$*"; }
info()      { printf '  [info]      %s\n' "$*"; }
attention() { printf '  [attention] %s\n' "$*"; ATTENTIONS=$((ATTENTIONS + 1)); }
manque()    { printf '  [MANQUE]    %s\n' "$*"; MANQUES=$((MANQUES + 1)); }
echec()     { printf '\nÉCHEC : %s\n' "$*" >&2; exit 2; }

EST_ROOT=0
[ "$(id -u)" = 0 ] && EST_ROOT=1

droits_root_disponibles() {
  [ "$EST_ROOT" = 1 ] || sudo -n true 2>/dev/null
}

en_root() {
  if [ "$EST_ROOT" = 1 ]; then "$@"; else sudo -n "$@"; fi
}

# Lance une commande sous le compte postgres, depuis / (postgres ne peut pas entrer dans /root).
en_postgres() {
  if [ "$EST_ROOT" = 1 ]; then
    (cd / && runuser -u postgres -- "$@")
  else
    (cd / && sudo -n -u postgres "$@")
  fi
}

# Pourquoi une étape qui a besoin du projet ne peut pas se faire : dépôt absent, ou
# branche actuelle sans le projet (main ne contient que le commit initial). $1 = fichier.
raison_sans_projet() {
  if [ ! -d "$DEPOT/.git" ] && [ ! -f "$DEPOT/.git" ]; then
    printf 'dépôt absent (%s)' "$DEPOT"
    return 0
  fi
  local branche
  branche=$(git -C "$DEPOT" branch --show-current 2>/dev/null)
  printf '%s absent de la branche %s (main ne contient que le commit initial) : extraire la branche de travail %s (fiche de reprise, § 4.1), puis relancer ce script' \
    "$1" "${branche:-(détachée)}" "$BRANCHE_TRAVAIL"
}

# --- PostgreSQL : lectures -------------------------------------------------------

versions_pg_installees() {
  ls -1 /usr/lib/postgresql 2>/dev/null | grep -E '^[0-9]+$' | sort -n
}

version_pg_choisie() {
  local versions
  versions=$(versions_pg_installees)
  if printf '%s\n' "$versions" | grep -qx "$VERSION_PG_PREFEREE"; then
    printf '%s\n' "$VERSION_PG_PREFEREE"
  else
    printf '%s\n' "$versions" | tail -n 1
  fi
}

# Ligne de pg_lsclusters du cluster « main » de la version donnée (vide s'il n'existe pas).
ligne_cluster() {
  command -v pg_lsclusters >/dev/null 2>&1 || return 0
  pg_lsclusters -h 2>/dev/null | awk -v v="$1" '$1 == v && $2 == "main"'
}

port_cluster()   { ligne_cluster "$1" | awk '{print $3}'; }
statut_cluster() { ligne_cluster "$1" | awk '{print $4}'; }

# Requête SQL lue par le compte postgres, sur le port de PILIQI. $1 = requête, $2 = base.
pg_lire() {
  en_postgres psql -X -A -t -q -v ON_ERROR_STOP=1 -p "$PORT_PG" -d "${2:-postgres}" -c "$1" 2>/dev/null </dev/null
}

serveur_pret() {
  command -v pg_isready >/dev/null 2>&1 && pg_isready -q -h 127.0.0.1 -p "$PORT_PG" >/dev/null 2>&1
}

# Vrai si le cluster ne contient aucune donnée : aucune base ni aucun rôle ajoutés,
# aucune table, vue ou séquence dans postgres et template1.
cluster_vide() {
  local bases roles objets_postgres objets_template1 requete_objets
  requete_objets="select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind in ('r','p','v','m','S','f') and n.nspname not in ('pg_catalog','information_schema') and n.nspname !~ '^pg_toast'"
  bases=$(pg_lire "select count(*) from pg_database where datname not in ('postgres','template0','template1')")
  roles=$(pg_lire "select count(*) from pg_roles where rolname <> 'postgres' and rolname !~ '^pg_'")
  objets_postgres=$(pg_lire "$requete_objets" postgres)
  objets_template1=$(pg_lire "$requete_objets" template1)
  [ "$bases" = 0 ] && [ "$roles" = 0 ] && [ "$objets_postgres" = 0 ] && [ "$objets_template1" = 0 ]
}

# --- Mot de passe de la base de test locale ---------------------------------------

# Valeur que voient les commandes lancées sous bash -lc (jamais affichée). Elle exécute
# les fichiers de connexion (/etc/profile.d, ~/.profile) : réservée à l'installation.
mdp_vu_par_bash_l() {
  bash -lc 'printf "\n__PILIQI_MDP__%s\n" "${PILIQI_PG_MOT_DE_PASSE:-}"' 2>/dev/null </dev/null \
    | sed -n 's/^__PILIQI_MDP__//p' | tail -n 1
}

# Valeur du dernier « export PILIQI_PG_MOT_DE_PASSE=… » de ~/.profile, lue sans exécuter
# le fichier (sans guillemets, entre apostrophes ou entre guillemets). Jamais affichée.
mdp_du_profil() {
  [ -f "$PROFIL" ] || return 0
  sed -n "s/^[[:space:]]*export[[:space:]]\{1,\}${VARIABLE_MDP}=//p" "$PROFIL" | tail -n 1 \
    | sed -E "s/^'(.*)'[[:space:]]*\$/\1/; t; s/^\"(.*)\"[[:space:]]*\$/\1/; t; s/[[:space:]]+#.*\$//; s/[[:space:]]+\$//"
}

mdp_ecrit_dans_profil() {
  [ -f "$PROFIL" ] && grep -Eq "^[[:space:]]*export[[:space:]]+${VARIABLE_MDP}=." "$PROFIL"
}

# Vrai si bash -l lit ~/.profile : bash ne lit que le premier de ~/.bash_profile,
# ~/.bash_login et ~/.profile, donc un des deux premiers doit alors lire ~/.profile.
profil_lu_par_bash_l() {
  local fichier
  for fichier in "$MAISON/.bash_profile" "$MAISON/.bash_login"; do
    if [ -f "$fichier" ]; then
      grep -Eq '(^|[[:space:];])(\.|source)[[:space:]]+"?(\$HOME|~|\$\{HOME\}|'"$MAISON"')/\.profile' "$fichier"
      return
    fi
  done
  return 0
}

# Version de Composer lue dans son fichier, sans l'exécuter (Composer écrit dans /tmp).
version_composer() {
  local chemin
  chemin=$(command -v composer) || return 0
  grep -a -m 1 -oE "const VERSION = '[0-9]+\.[0-9]+\.[0-9]+'" "$chemin" 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+'
}

bibliotheque_faketime() {
  local chemin
  for chemin in /usr/lib/x86_64-linux-gnu/faketime/libfaketime.so.1 /usr/lib/aarch64-linux-gnu/faketime/libfaketime.so.1 /usr/lib/faketime/libfaketime.so.1; do
    [ -f "$chemin" ] && { printf '%s\n' "$chemin"; return 0; }
  done
  return 0
}

connexion_piliqi_reussie() {
  [ -n "${1:-}" ] || return 1
  PGPASSWORD="$1" PGCONNECT_TIMEOUT=5 psql -X -A -t -q -h 127.0.0.1 -p "$PORT_PG" \
    -U "$ROLE_PG" -d "$BASE_PG" -c 'select 1' 2>/dev/null </dev/null | grep -qx 1
}

# --- PHP ---------------------------------------------------------------------------

# Affiche deux lignes : les extensions requises, puis celles qui manquent.
extensions_php() {
  local fichier_lock="$DEPOT/composer.lock"
  [ -f "$fichier_lock" ] || fichier_lock=""
  php -r '
    $requises = ["openssl", "pdo_pgsql", "zip", "pdo", "pdo_sqlite", "sqlite3", "pcntl", "curl",
                 "intl", "mbstring", "dom", "xml", "libxml", "gd", "iconv", "fileinfo", "tokenizer",
                 "ctype", "json", "simplexml", "xmlreader", "xmlwriter", "filter", "session", "phar"];
    if (($argv[1] ?? "") !== "") {
        $lock = json_decode((string) file_get_contents($argv[1]), true) ?: [];
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $paquet) {
            foreach (array_keys($paquet["require"] ?? []) as $exigence) {
                if (str_starts_with($exigence, "ext-")) { $requises[] = substr($exigence, 4); }
            }
        }
        foreach (array_keys($lock["platform"] ?? []) as $exigence) {
            if (str_starts_with($exigence, "ext-")) { $requises[] = substr($exigence, 4); }
        }
    }
    $requises = array_values(array_unique(array_map("strtolower", $requises)));
    sort($requises);
    $absentes = array_values(array_filter($requises, fn ($e) => ! extension_loaded($e)));
    echo implode(" ", $requises), "\n", implode(" ", $absentes), "\n";
  ' "$fichier_lock" 2>/dev/null
}

php_complet() {
  command -v php >/dev/null 2>&1 || return 1
  local absentes
  absentes=$(extensions_php | sed -n '2p')
  [ -z "$absentes" ]
}

# --- Composer : vendor conforme à composer.lock ------------------------------------

# Affiche : nombre absents, nombre différents, liste absents, liste différents.
ecart_vendor_lock() {
  php -r '
    $lock = json_decode((string) file_get_contents($argv[1]), true) ?: [];
    $installe = json_decode((string) file_get_contents($argv[2]), true) ?: [];
    $installe = $installe["packages"] ?? $installe;
    $versions = [];
    foreach ($installe as $paquet) { $versions[$paquet["name"]] = $paquet["version"]; }
    $absents = []; $differents = [];
    foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $paquet) {
        $nom = $paquet["name"];
        if (! isset($versions[$nom])) { $absents[] = $nom; }
        elseif ($versions[$nom] !== $paquet["version"]) { $differents[] = $nom." ".$versions[$nom]." au lieu de ".$paquet["version"]; }
    }
    echo count($absents), "\n", count($differents), "\n",
         implode(", ", array_slice($absents, 0, 8)), "\n", implode(", ", array_slice($differents, 0, 8)), "\n";
  ' "$DEPOT/composer.lock" "$DEPOT/vendor/composer/installed.json" 2>/dev/null
}

# ==================================================================================
# Vérification (lecture seule)
# ==================================================================================

verifier() {
  MANQUES=0
  ATTENTIONS=0

  titre "Système"
  if [ -r /etc/os-release ]; then
    local systeme
    systeme=$(. /etc/os-release && printf '%s' "$PRETTY_NAME")
    case "$systeme" in
      "Ubuntu 24.04"*) ok "système : $systeme" ;;
      *) attention "système : $systeme (l'ancien conteneur : Ubuntu 24.04.4 LTS ; les noms de paquets du script supposent Ubuntu 24.04)" ;;
    esac
  else
    attention "système inconnu (/etc/os-release illisible)"
  fi
  if droits_root_disponibles; then
    ok "droits root disponibles ($( [ "$EST_ROOT" = 1 ] && echo 'root' || echo 'sudo sans mot de passe' ))"
  else
    manque "ni root ni sudo sans mot de passe : l'installation des paquets et de PostgreSQL est impossible"
  fi
  local libre_ko
  libre_ko=$(df -Pk / 2>/dev/null | awk 'NR == 2 {print $4}')
  if [ -n "$libre_ko" ] && [ "$libre_ko" -lt 10485760 ]; then
    attention "espace libre sur / : $((libre_ko / 1048576)) Go (moins de 10 Go : vendor installé depuis les sources git, cache Composer, bases des tests parallèles et journaux ; le disque plein a déjà fait échouer une suite, errno=28)"
  elif [ -n "$libre_ko" ]; then
    ok "espace libre sur / : $((libre_ko / 1048576)) Go"
  fi

  titre "PHP"
  if command -v php >/dev/null 2>&1; then
    local version_php
    version_php=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
    if [ "$version_php" = "$VERSION_PHP_ATTENDUE" ]; then
      ok "PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
    elif php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
      attention "PHP $(php -r 'echo PHP_VERSION;') (l'ancien conteneur avait 8.3.6 ; composer.json exige ^8.3 ; la CI teste 8.3, 8.4 et 8.5)"
    else
      manque "PHP $(php -r 'echo PHP_VERSION;') trop ancien : composer.json exige ^8.3 (apt-get install ${PAQUETS_PHP[*]})"
    fi
    local lignes_ext requises absentes
    lignes_ext=$(extensions_php)
    requises=$(printf '%s\n' "$lignes_ext" | sed -n '1p')
    absentes=$(printf '%s\n' "$lignes_ext" | sed -n '2p')
    if [ -z "$requises" ]; then
      manque "impossible de lire les extensions de PHP"
    elif [ -n "$absentes" ]; then
      manque "extensions PHP absentes : $absentes (apt-get install ${PAQUETS_PHP[*]})"
    else
      ok "extensions PHP requises chargées ($(printf '%s' "$requises" | wc -w) : composer.json, composer.lock, CI et PDF)"
    fi
    local memoire
    memoire=$(php -r 'echo ini_get("memory_limit");' 2>/dev/null)
    if [ "$memoire" = "-1" ]; then
      ok "memory_limit de PHP en ligne de commande : -1 (sans limite, comme l'ancien conteneur)"
    else
      attention "memory_limit de PHP en ligne de commande : $memoire (l'ancien conteneur : -1, valeur d'Ubuntu pour la ligne de commande)"
    fi
  else
    manque "PHP absent (apt-get install ${PAQUETS_PHP[*]})"
  fi

  titre "Composer"
  if command -v composer >/dev/null 2>&1; then
    local composer_lu
    composer_lu=$(version_composer)
    case "$composer_lu" in
      2.*) ok "Composer $composer_lu ($(command -v composer) ; ancien conteneur : 2.8.12)" ;;
      "") info "Composer présent ($(command -v composer)), version non lue sans l'exécuter" ;;
      *) attention "Composer $composer_lu (Composer 2 attendu)" ;;
    esac
  else
    manque "Composer absent (l'image le fournit en /usr/local/bin/composer ; sinon apt-get install composer)"
  fi

  titre "PostgreSQL"
  local version_pg
  version_pg=$(version_pg_choisie)
  if [ -z "$version_pg" ]; then
    manque "PostgreSQL absent (apt-get install postgresql-16)"
  else
    if [ "$version_pg" = "$VERSION_PG_PREFEREE" ]; then
      ok "PostgreSQL $version_pg installé (comme l'ancien conteneur ; la CI teste 17, la production sera en 17 ou 18)"
    else
      attention "PostgreSQL $version_pg installé (l'ancien conteneur : 16 ; la CI : 17 ; accepté si la suite passe)"
    fi
    if [ -z "$(ligne_cluster "$version_pg")" ]; then
      manque "aucun cluster « main » pour PostgreSQL $version_pg (pg_createcluster --locale=C.UTF-8 --encoding=UTF8 $version_pg main)"
    else
      local port statut
      port=$(port_cluster "$version_pg")
      statut=$(statut_cluster "$version_pg")
      if [ "$port" = "$PORT_PG" ]; then ok "cluster $version_pg/main sur le port $PORT_PG"; else manque "le cluster $version_pg/main écoute sur le port $port ; les tests attendent $PORT_PG"; fi
      if [ "$statut" = online ] && serveur_pret; then
        ok "serveur PostgreSQL démarré"
        verifier_contenu_postgresql
      else
        manque "serveur PostgreSQL arrêté (après chaque redémarrage du conteneur : service postgresql start) ; contenu de la base non vérifié"
      fi
    fi
  fi

  titre "Mot de passe de la base de test locale (${VARIABLE_MDP})"
  local mdp
  mdp=$(mdp_du_profil)
  if mdp_ecrit_dans_profil && [ -n "$mdp" ]; then
    ok "défini dans $PROFIL (valeur jamais affichée)"
    if [ "${#mdp}" -lt 12 ]; then
      attention "mot de passe court (moins de 12 caractères) : un mot court risque de figurer dans les notes, que pilotage-sync.sh doit pouvoir contrôler ; dans un conteneur neuf, le script en tire un de 32 caractères"
    fi
  else
    manque "absent de $PROFIL (export ${VARIABLE_MDP}=…)"
  fi
  if profil_lu_par_bash_l; then
    ok "$PROFIL est lu par bash -l (les commandes de test se lancent sous bash -lc)"
  else
    manque "un ~/.bash_profile ou ~/.bash_login empêche bash -l de lire $PROFIL : ${VARIABLE_MDP} y serait invisible"
  fi
  if [ -n "$mdp" ] && serveur_pret; then
    if connexion_piliqi_reussie "$mdp"; then
      ok "connexion de $ROLE_PG à $BASE_PG par 127.0.0.1:$PORT_PG avec ce mot de passe"
    else
      manque "connexion de $ROLE_PG à $BASE_PG refusée avec le mot de passe de $PROFIL (rôle, base, mot de passe ou pg_hba.conf)"
    fi
  fi

  titre "faketime"
  # Pas d'essai de l'horloge ici : libfaketime crée des objets dans /dev/shm. L'installation
  # fait cet essai.
  if command -v faketime >/dev/null 2>&1; then
    ok "faketime $(faketime --version 2>&1 | sed -n 's/.*Version[[:space:]]*//p' | head -n 1) ($(command -v faketime) ; ancien conteneur : 0.9.10)"
  else
    manque "faketime absent (apt-get install faketime)"
  fi
  if [ -n "$(bibliotheque_faketime)" ]; then
    ok "bibliothèque libfaketime : $(bibliotheque_faketime)"
  else
    manque "bibliothèque libfaketime absente (apt-get install faketime)"
  fi

  titre "Fuseaux horaires"
  if dpkg -s tzdata >/dev/null 2>&1; then
    ok "tzdata $(dpkg-query -W -f='${Version}' tzdata 2>/dev/null) (PHP d'Ubuntu lit les fuseaux du système)"
  else
    manque "tzdata absent (apt-get install tzdata)"
  fi
  if command -v php >/dev/null 2>&1; then
    local fuseaux_absents
    fuseaux_absents=$(php -r 'foreach (array_slice($argv, 1) as $f) { try { new DateTimeZone($f); } catch (Throwable $e) { echo $f, " "; } }' "${FUSEAUX_REQUIS[@]}" 2>/dev/null)
    if [ -z "$fuseaux_absents" ]; then ok "PHP connaît ${FUSEAUX_REQUIS[*]}"; else manque "fuseaux inconnus de PHP : $fuseaux_absents"; fi
  fi

  titre "Dépôt ($DEPOT)"
  if [ ! -d "$DEPOT/.git" ] && [ ! -f "$DEPOT/.git" ]; then
    manque "dépôt absent : l'environnement doit cloner besnikpiliqi/piliqiediteur dans $DEPOT"
  else
    local branche
    branche=$(git -C "$DEPOT" branch --show-current 2>/dev/null)
    if [ "$branche" = "$BRANCHE_TRAVAIL" ]; then
      ok "branche de travail : $branche"
    else
      attention "branche actuelle : ${branche:-(détachée)} ; la branche de travail est $BRANCHE_TRAVAIL (fiche de reprise, § 4.1)"
    fi
    local fichier_exclusion ligne
    fichier_exclusion="$(git -C "$DEPOT" rev-parse --git-path info/exclude 2>/dev/null)"
    case "$fichier_exclusion" in /*) ;; *) fichier_exclusion="$DEPOT/$fichier_exclusion" ;; esac
    for ligne in "${LIGNES_EXCLUSION[@]}"; do
      if [ -f "$fichier_exclusion" ] && grep -qxF -- "$ligne" "$fichier_exclusion"; then
        ok "exclusion locale « $ligne » dans .git/info/exclude"
      else
        manque "exclusion locale « $ligne » absente de .git/info/exclude (un test jetable oublié rendrait l'arbre sale)"
      fi
    done
    if [ ! -f "$DEPOT/composer.json" ]; then
      manque "$(raison_sans_projet composer.json) ; vendor, vendor/bin et .env non vérifiés"
    else
      verifier_projet
    fi
    local cles_non_suivies
    cles_non_suivies=$(git -C "$DEPOT" ls-files --others --exclude-standard -- resources/caisse-securisee/cles 2>/dev/null | tr '\n' ' ')
    if [ -n "$cles_non_suivies" ]; then
      attention "clé de développement non suivie dans resources/caisse-securisee/cles : $cles_non_suivies (laissée par caisse:generer-cle ; à ne jamais committer : elle rend l'arbre sale)"
    fi
  fi

  titre "Outils du pilotage (hors projet)"
  if command -v python3 >/dev/null 2>&1; then
    ok "$(python3 --version 2>&1) (modifier les scripts de la chaîne, lire les journaux des courses)"
  else
    attention "python3 absent (la session s'en sert pour modifier les scripts de la chaîne et lire les journaux)"
  fi
  if command -v node >/dev/null 2>&1; then
    ok "Node $(node -v 2>/dev/null) (seulement pour « node --check » des scripts de la chaîne ; le projet n'a aucun outil Node)"
  else
    attention "Node absent (sert seulement à « node --check » des scripts de la chaîne)"
  fi

  titre "Outils facultatifs (information)"
  if command -v redis-server >/dev/null 2>&1; then info "$(redis-server --version 2>/dev/null | awk '{print $1, $2, $3}') installé, arrêté par défaut (pas encore utilisé ; prévu pour la campagne de tests avec Redis)"; fi
  if [ -n "${PLAYWRIGHT_BROWSERS_PATH:-}" ] && [ -d "${PLAYWRIGHT_BROWSERS_PATH}" ]; then info "navigateurs Playwright dans $PLAYWRIGHT_BROWSERS_PATH : $(ls "$PLAYWRIGHT_BROWSERS_PATH" 2>/dev/null | tr '\n' ' ')"; fi
  if command -v pdftotext >/dev/null 2>&1; then info "poppler-utils présent (pdftotext, pdftoppm : lire ou dessiner un PDF à la main)"; fi

  titre "Bilan"
  if [ "$MANQUES" -eq 0 ]; then
    printf '  Tout est en place (%d point(s) d'"'"'attention).\n' "$ATTENTIONS"
    return 0
  fi
  printf '  Il manque %d élément(s) (%d point(s) d'"'"'attention). Lancer : bash installer-session.sh\n' "$MANQUES" "$ATTENTIONS"
  return 1
}

# Vendor, outils de test et .env : seulement quand la branche actuelle a le projet.
verifier_projet() {
  if [ -f "$DEPOT/vendor/autoload.php" ] && [ -f "$DEPOT/vendor/composer/installed.json" ]; then
    local ecart nb_absents nb_differents
    ecart=$(ecart_vendor_lock)
    nb_absents=$(printf '%s\n' "$ecart" | sed -n '1p')
    nb_differents=$(printf '%s\n' "$ecart" | sed -n '2p')
    if [ "$nb_absents" = 0 ] && [ "$nb_differents" = 0 ]; then
      ok "vendor conforme à composer.lock (dépendances de développement comprises)"
    elif [ -z "$nb_absents" ]; then
      manque "impossible de comparer vendor et composer.lock"
    else
      manque "vendor différent de composer.lock : $nb_absents paquet(s) absent(s) [$(printf '%s\n' "$ecart" | sed -n '3p')], $nb_differents version(s) différente(s) [$(printf '%s\n' "$ecart" | sed -n '4p')] : lancer composer install (jamais composer update)"
    fi
  else
    manque "vendor absent : lancer composer install dans $DEPOT (jamais composer update)"
  fi
  local outil
  for outil in phpunit paratest pint; do
    if [ -x "$DEPOT/vendor/bin/$outil" ]; then ok "vendor/bin/$outil présent"; else manque "vendor/bin/$outil absent (composer install)"; fi
  done
  if [ -f "$DEPOT/.env" ]; then
    ok ".env présent"
    if grep -Eq '^APP_KEY=base64:.+' "$DEPOT/.env"; then ok "APP_KEY renseignée"; else manque "APP_KEY vide dans .env (php artisan key:generate)"; fi
    if [ -f "$DEPOT/.env.example" ]; then
      local absentes_env
      absentes_env=$(comm -13 <(grep -oE '^[A-Z0-9_]+' "$DEPOT/.env" | sort -u) <(grep -oE '^[A-Z0-9_]+' "$DEPOT/.env.example" | sort -u) | tr '\n' ' ')
      if [ -n "$absentes_env" ]; then
        info ".env n'a pas ces variables de .env.example (sans effet sur les tests, qui prennent leurs valeurs dans phpunit.xml) : $absentes_env"
      fi
    fi
  else
    manque ".env absent (cp .env.example .env, puis php artisan key:generate)"
  fi
}

verifier_contenu_postgresql() {
  local template1
  template1=$(pg_lire "select pg_encoding_to_char(encoding) || '|' || datcollate || '|' || datctype from pg_database where datname = 'template1'")
  if [ "$template1" = "UTF8|C.UTF-8|C.UTF-8" ]; then
    ok "template1 en UTF8 et C.UTF-8 (les bases des tests parallèles se créent depuis template1)"
  elif [ -z "$template1" ]; then
    manque "template1 illisible par le compte postgres"
  else
    manque "template1 en ${template1//|/ \/ } au lieu de UTF8 / C.UTF-8 : le cluster doit être recréé en C.UTF-8 (seulement s'il ne contient aucune donnée)"
  fi
  local role
  role=$(pg_lire "select rolcanlogin::text || '|' || rolcreatedb::text || '|' || rolsuper::text from pg_roles where rolname = '$ROLE_PG'")
  if [ -z "$role" ]; then
    manque "rôle $ROLE_PG absent"
  else
    case "$role" in
      true\|true\|false) ok "rôle $ROLE_PG : LOGIN CREATEDB, pas superutilisateur (comme la CI)" ;;
      true\|true\|true) attention "rôle $ROLE_PG superutilisateur (la CI ne l'est pas : un test qui ne passe que grâce à ce droit passerait ici et casserait en CI)" ;;
      *) manque "rôle $ROLE_PG sans LOGIN ou sans CREATEDB (connexion|createdb|superutilisateur = ${role//|/ \/ })" ;;
    esac
  fi
  local base
  base=$(pg_lire "select pg_get_userbyid(datdba) || '|' || pg_encoding_to_char(encoding) || '|' || datcollate || '|' || datctype from pg_database where datname = '$BASE_PG'")
  if [ -z "$base" ]; then
    manque "base $BASE_PG absente"
  elif [ "$base" = "$ROLE_PG|UTF8|C.UTF-8|C.UTF-8" ]; then
    ok "base $BASE_PG : propriétaire $ROLE_PG, UTF8, C.UTF-8"
  else
    manque "base $BASE_PG : propriétaire / encodage / collation / ctype = ${base//|/ \/ } au lieu de $ROLE_PG / UTF8 / C.UTF-8 / C.UTF-8"
  fi
  local fuseau
  fuseau=$(pg_lire "show timezone")
  [ -n "$fuseau" ] && info "fuseau par défaut du serveur : $fuseau (l'application fixe UTC à chaque connexion)"
}

# ==================================================================================
# Installation (idempotente)
# ==================================================================================

installer_paquets() {
  etape "Paquets système"
  local paquets=()
  if [ -z "$(versions_pg_installees)" ]; then
    if apt-cache show postgresql-16 >/dev/null 2>&1; then paquets+=(postgresql-16); else paquets+=(postgresql); fi
  fi
  command -v faketime >/dev/null 2>&1 || paquets+=(faketime)
  dpkg -s tzdata >/dev/null 2>&1 || paquets+=(tzdata)
  php_complet || paquets+=("${PAQUETS_PHP[@]}")
  command -v composer >/dev/null 2>&1 || paquets+=(composer)
  command -v git >/dev/null 2>&1 || paquets+=(git)
  command -v unzip >/dev/null 2>&1 || paquets+=(unzip)
  command -v curl >/dev/null 2>&1 || paquets+=(curl ca-certificates)
  if [ "${#paquets[@]}" -eq 0 ]; then
    ok "rien à installer"
    return 0
  fi
  info "à installer : ${paquets[*]}"
  en_root env DEBIAN_FRONTEND=noninteractive apt-get update -q >/dev/null \
    || echec "apt-get update a échoué : l'accès réseau de l'environnement doit autoriser archive.ubuntu.com et security.ubuntu.com"
  # LANG et LC_ALL en C.UTF-8 : le cluster que crée le paquet postgresql naît alors en C.UTF-8.
  en_root env DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8 \
    apt-get install -y -q --no-install-recommends "${paquets[@]}" >/dev/null \
    || echec "apt-get install ${paquets[*]} a échoué"
  ok "installés : ${paquets[*]}"
}

demarrer_postgresql() {
  local version="$1" essai
  if [ "$(statut_cluster "$version")" != online ]; then
    en_root service postgresql start >/dev/null 2>&1 || en_root pg_ctlcluster "$version" main start >/dev/null 2>&1
  fi
  for essai in $(seq 1 30); do
    serveur_pret && return 0
    sleep 1
  done
  return 1
}

installer_cluster() {
  etape "Cluster PostgreSQL"
  local version
  version=$(version_pg_choisie)
  [ -n "$version" ] || echec "PostgreSQL n'est pas installé"
  if [ -z "$(ligne_cluster "$version")" ]; then
    en_root pg_createcluster --locale=C.UTF-8 --encoding=UTF8 "$version" main >/dev/null \
      || echec "pg_createcluster $version main a échoué"
    ok "cluster $version/main créé en UTF8 et C.UTF-8"
  fi
  local port
  port=$(port_cluster "$version")
  [ "$port" = "$PORT_PG" ] || echec "le cluster $version/main écoute sur le port $port ; les tests attendent $PORT_PG (un autre cluster occupe-t-il ce port ? pg_lsclusters)"
  demarrer_postgresql "$version" || echec "le serveur PostgreSQL $version ne démarre pas (journal : /var/log/postgresql/postgresql-$version-main.log)"
  ok "serveur PostgreSQL $version démarré sur le port $PORT_PG"
  local template1
  template1=$(pg_lire "select pg_encoding_to_char(encoding) || '|' || datcollate || '|' || datctype from pg_database where datname = 'template1'")
  if [ "$template1" = "UTF8|C.UTF-8|C.UTF-8" ]; then
    ok "template1 en UTF8 et C.UTF-8"
    return 0
  fi
  [ -n "$template1" ] || echec "le compte postgres ne peut pas lire template1 (connexion locale « peer » de pg_hba.conf ?)"
  if ! cluster_vide; then
    echec "template1 est en ${template1//|/ \/ } et le cluster contient des données : il n'est pas supprimé. Sauvegarder ce qui doit l'être, puis, à la main : pg_dropcluster --stop $version main && pg_createcluster --locale=C.UTF-8 --encoding=UTF8 $version main && service postgresql start"
  fi
  info "template1 en ${template1//|/ \/ } dans un cluster vide : recréation en C.UTF-8"
  en_root pg_dropcluster --stop "$version" main >/dev/null || echec "pg_dropcluster $version main a échoué"
  en_root pg_createcluster --locale=C.UTF-8 --encoding=UTF8 "$version" main >/dev/null || echec "pg_createcluster $version main a échoué"
  demarrer_postgresql "$version" || echec "le serveur PostgreSQL $version ne redémarre pas"
  ok "cluster $version/main recréé en UTF8 et C.UTF-8"
}

installer_mot_de_passe() {
  etape "Mot de passe de la base de test locale"
  if [ -n "$(mdp_vu_par_bash_l)" ]; then
    ok "${VARIABLE_MDP} déjà défini pour bash -lc (valeur gardée, jamais affichée)"
    return 0
  fi
  if mdp_ecrit_dans_profil; then
    echec "${VARIABLE_MDP} est écrit dans $PROFIL mais bash -lc ne le lit pas : un ~/.bash_profile ou ~/.bash_login doit lire ~/.profile"
  fi
  local nouveau
  nouveau=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
  [ "${#nouveau}" -eq 32 ] || echec "tirage du mot de passe impossible (/dev/urandom)"
  {
    printf '\n# Base PostgreSQL locale de test de PILIQI (valeur de test tirée au hasard par installer-session.sh, jamais dans le dépôt)\n'
    printf 'export %s=%s\n' "$VARIABLE_MDP" "$nouveau"
  } >> "$PROFIL" || echec "écriture dans $PROFIL impossible"
  [ -n "$(mdp_vu_par_bash_l)" ] || echec "${VARIABLE_MDP} écrit dans $PROFIL mais invisible pour bash -lc"
  ok "${VARIABLE_MDP} tiré au hasard et écrit dans $PROFIL (jamais affiché)"
}

installer_role_et_base() {
  etape "Rôle $ROLE_PG et base $BASE_PG"
  local mdp mdp_sql
  mdp=$(mdp_vu_par_bash_l)
  [ -n "$mdp" ] || echec "mot de passe introuvable"
  mdp_sql=${mdp//\'/\'\'}
  local role
  role=$(pg_lire "select rolcanlogin::text || '|' || rolcreatedb::text from pg_roles where rolname = '$ROLE_PG'")
  # Le mot de passe passe par l'entrée standard de psql (jamais sur une ligne de commande)
  # et le serveur ne journalise pas l'instruction, même en cas d'erreur.
  if [ -z "$role" ]; then
    en_postgres psql -X -q -v ON_ERROR_STOP=1 -p "$PORT_PG" -d postgres >/dev/null 2>&1 <<SQL || echec "création du rôle $ROLE_PG impossible"
SET log_statement = 'none';
SET log_min_error_statement = 'panic';
CREATE ROLE $ROLE_PG LOGIN CREATEDB PASSWORD '$mdp_sql';
SQL
    ok "rôle $ROLE_PG créé (LOGIN CREATEDB)"
  elif [ "$role" != "true|true" ]; then
    pg_lire "ALTER ROLE $ROLE_PG LOGIN CREATEDB" >/dev/null || echec "ALTER ROLE $ROLE_PG LOGIN CREATEDB impossible"
    ok "rôle $ROLE_PG : LOGIN CREATEDB rétablis"
  else
    ok "rôle $ROLE_PG déjà présent"
  fi
  local base
  base=$(pg_lire "select pg_get_userbyid(datdba) || '|' || pg_encoding_to_char(encoding) || '|' || datcollate || '|' || datctype from pg_database where datname = '$BASE_PG'")
  if [ -z "$base" ]; then
    en_postgres createdb -p "$PORT_PG" --owner="$ROLE_PG" --template=template0 --encoding=UTF8 \
      --lc-collate=C.UTF-8 --lc-ctype=C.UTF-8 "$BASE_PG" </dev/null || echec "createdb $BASE_PG impossible"
    ok "base $BASE_PG créée (propriétaire $ROLE_PG, UTF8, C.UTF-8)"
  else
    case "$base" in
      *"|UTF8|C.UTF-8|C.UTF-8") ;;
      *) echec "la base $BASE_PG existe en ${base//|/ \/ } : elle n'est jamais supprimée par ce script ; la recréer à la main en UTF8 et C.UTF-8 si elle ne contient rien d'utile (dropdb $BASE_PG, puis relancer ce script)" ;;
    esac
    if [ "${base%%|*}" != "$ROLE_PG" ]; then
      pg_lire "ALTER DATABASE $BASE_PG OWNER TO $ROLE_PG" >/dev/null || echec "ALTER DATABASE $BASE_PG OWNER TO $ROLE_PG impossible"
      ok "base $BASE_PG rendue à $ROLE_PG"
    else
      ok "base $BASE_PG déjà présente (propriétaire $ROLE_PG, UTF8, C.UTF-8)"
    fi
  fi
  if ! connexion_piliqi_reussie "$mdp"; then
    en_postgres psql -X -q -v ON_ERROR_STOP=1 -p "$PORT_PG" -d postgres >/dev/null 2>&1 <<SQL || echec "ALTER ROLE $ROLE_PG PASSWORD impossible"
SET log_statement = 'none';
SET log_min_error_statement = 'panic';
ALTER ROLE $ROLE_PG PASSWORD '$mdp_sql';
SQL
    info "mot de passe du rôle $ROLE_PG aligné sur $PROFIL"
  fi
  connexion_piliqi_reussie "$mdp" || echec "connexion de $ROLE_PG à $BASE_PG par 127.0.0.1:$PORT_PG refusée (voir /etc/postgresql/*/main/pg_hba.conf : host 127.0.0.1/32 scram-sha-256)"
  ok "connexion de $ROLE_PG à $BASE_PG vérifiée"
}

controler_faketime() {
  etape "Essai de faketime"
  local annee
  annee=$(TZ=UTC NO_FAKE_STAT=1 faketime -f '@2027-01-02 10:00:00' date -u +%Y 2>/dev/null)
  [ "$annee" = 2027 ] || echec "faketime ne truque pas l'horloge (faketime -f '@2027-01-02 10:00:00' date -u donne « $annee »)"
  ok "faketime truque l'horloge (2027-01-02 10:00:00 UTC)"
}

installer_exclusions() {
  etape "Exclusion locale des tests jetables"
  if [ ! -d "$DEPOT/.git" ] && [ ! -f "$DEPOT/.git" ]; then
    attention "dépôt absent : exclusion non écrite"
    return 0
  fi
  local fichier ligne
  fichier="$(git -C "$DEPOT" rev-parse --git-path info/exclude 2>/dev/null)"
  case "$fichier" in /*) ;; *) fichier="$DEPOT/$fichier" ;; esac
  mkdir -p "$(dirname "$fichier")"
  for ligne in "${LIGNES_EXCLUSION[@]}"; do
    if [ -f "$fichier" ] && grep -qxF -- "$ligne" "$fichier"; then
      ok "« $ligne » déjà exclu"
    else
      printf '%s\n' "$ligne" >> "$fichier"
      ok "« $ligne » ajouté à .git/info/exclude"
    fi
  done
}

installer_composer() {
  etape "Dépendances Composer"
  if [ "$AVEC_COMPOSER" = 0 ]; then
    info "étape sautée (--sans-composer) : lancer plus tard « bash installer-session.sh » dans la session"
    return 0
  fi
  if [ ! -f "$DEPOT/composer.json" ]; then
    attention "$(raison_sans_projet composer.json) : composer install non lancé"
    return 0
  fi
  # GitHub refuse les archives (dist) passées par le proxy de l'environnement : Composer
  # se rabat sur des clones git, plus lents ; d'où le long délai de chaque processus.
  if (cd "$DEPOT" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=1800 \
        composer install --no-interaction --no-progress); then
    ok "composer install terminé"
    return 0
  fi
  attention "composer install a échoué ; nouvel essai avec --prefer-source (clones git)"
  if (cd "$DEPOT" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=1800 \
        composer install --no-interaction --no-progress --prefer-source); then
    ok "composer install --prefer-source terminé"
  else
    attention "composer install a encore échoué : voir son message ci-dessus (accès réseau à repo.packagist.org et github.com)"
  fi
}

installer_env() {
  etape "Fichier .env et clé de l'application"
  if [ ! -f "$DEPOT/.env.example" ]; then
    attention "$(raison_sans_projet .env.example) : .env non créé"
    return 0
  fi
  if [ ! -f "$DEPOT/.env" ]; then
    cp "$DEPOT/.env.example" "$DEPOT/.env" || echec "copie de .env.example impossible"
    ok ".env copié depuis .env.example"
  else
    ok ".env déjà présent (gardé tel quel)"
  fi
  if grep -Eq '^APP_KEY=base64:.+' "$DEPOT/.env"; then
    ok "APP_KEY déjà renseignée"
  elif [ -f "$DEPOT/vendor/autoload.php" ]; then
    (cd "$DEPOT" && php artisan key:generate --no-interaction >/dev/null) || echec "php artisan key:generate a échoué"
    ok "APP_KEY générée"
  else
    attention "APP_KEY vide : php artisan key:generate se lance après composer install"
  fi
}

installer() {
  printf 'Installation de l'"'"'environnement de PILIQI (dépôt : %s)\n' "$DEPOT"
  droits_root_disponibles || echec "ni root ni sudo sans mot de passe"
  installer_paquets
  installer_cluster
  installer_mot_de_passe
  installer_role_et_base
  controler_faketime
  installer_exclusions
  installer_composer
  installer_env
  printf '\nVérification finale :\n'
  verifier
}

if [ "$MODE" = verifier ]; then
  printf 'Vérification de l'"'"'environnement de PILIQI, sans rien modifier (dépôt : %s)\n' "$DEPOT"
  verifier
  exit $?
fi
installer
exit $?
