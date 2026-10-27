#!/usr/bin/env bash
#
# Install and migrate quizaccess_conditionalquestions.
#
# This script performs the complete migration from the predecessor plugin
# local_conditional_questions (which required a core patch) to this plugin:
#
#   1. detects the Moodle webroot (standard layout or public/ layout);
#   2. backs up the database and the patched code (optional);
#   3. copies the plugin to <webroot>/mod/quiz/accessrule/conditionalquestions/;
#   4. reverts the old core patch (git checkout required, patch file ships in
#      docs/core_patch.patch);
#   5. resets the mod_quiz version in the database to the clean core version;
#   6. runs the upgrade, which installs this plugin and migrates existing
#      rules and attempt data;
#   7. uninstalls the old local plugin and removes its directory (optional);
#   8. purges caches and prints a verification summary.
#
# It is safe to re-run: every step is skipped when its precondition is not
# met. A site without the old plugin and without the core patch simply gets
# the plugin installed.
#
# Usage:
#   ./install.sh [--webroot DIR] [--yes] [--skip-backup]
#                [--no-uninstall-old] [--dry-run]
#
# Options:
#   --webroot DIR       Moodle dirroot (the directory containing config.php).
#                       Auto-detected when the script is run from inside the
#                       plugin directory of a Moodle installation.
#   --yes               Do not ask for confirmation.
#   --skip-backup       Do not create database/code backups.
#   --no-uninstall-old  Keep local_conditional_questions installed.
#   --dry-run           Show what would be done without changing anything.
#
# See docs/AGENT_COREPATCH_REMOVAL.md for a step-by-step guide with manual
# fallbacks.

set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PATCH_FILE="$SELF_DIR/docs/core_patch.patch"
CORE_VERSION="2026042000"

ASSUME_YES=0
SKIP_BACKUP=0
UNINSTALL_OLD=1
DRYRUN=0
WEBROOT_ARG=""

log() { echo "[cq] $*"; }
die() { echo "[cq] ERROR: $*" >&2; exit 1; }

usage() { sed -n '2,37p' "$0" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes)              ASSUME_YES=1 ;;
        --skip-backup)      SKIP_BACKUP=1 ;;
        --no-uninstall-old) UNINSTALL_OLD=0 ;;
        --dry-run)          DRYRUN=1 ;;
        --webroot)          [[ $# -ge 2 ]] || die "--webroot needs a directory argument"
                            WEBROOT_ARG="$2"; shift ;;
        -h|--help)          usage; exit 0 ;;
        *)                  die "Unknown option: $1 (see --help)" ;;
    esac
    shift
done

run() {
    if ((DRYRUN)); then
        echo "[cq] dry-run: $*"
    else
        "$@"
    fi
}

run_in() {
    local dir="$1"; shift
    if ((DRYRUN)); then
        echo "[cq] dry-run: (cd $dir) $*"
    else
        (cd "$dir" && "$@")
    fi
}

# ---------------------------------------------------------------- webroot ---
if [[ -n $WEBROOT_ARG ]]; then
    WEBROOT="$(cd "$WEBROOT_ARG" && pwd)" || die "Webroot not found: $WEBROOT_ARG"
else
    # Script may live in <dirroot>/mod/quiz/accessrule/conditionalquestions
    # (standard layout) or <root>/public/mod/quiz/accessrule/conditionalquestions
    # (public/ layout). Walk four levels up to find the webroot.
    CAND="$(cd "$SELF_DIR/../../../.." && pwd)"
    if [[ -f "$CAND/config.php" ]]; then
        WEBROOT="$CAND"
    else
        die "Moodle webroot not detected. Run from inside the plugin directory or pass --webroot DIR."
    fi
fi
[[ -f "$WEBROOT/config.php" ]] || die "No config.php in $WEBROOT"

GITROOT="$(git -C "$WEBROOT" rev-parse --show-toplevel 2>/dev/null || true)"
TARGET="$WEBROOT/mod/quiz/accessrule/conditionalquestions"

# CLI scripts may live in <webroot>/admin/cli (standard layout) or, in a split
# layout where the webroot is a public/ subdirectory of the git root, in
# <gitroot>/admin/cli.
if [[ -f "$WEBROOT/admin/cli/upgrade.php" ]]; then
    CLIROOT="$WEBROOT"
elif [[ -n $GITROOT && -f "$GITROOT/admin/cli/upgrade.php" ]]; then
    CLIROOT="$GITROOT"
else
    die "admin/cli/upgrade.php not found under $WEBROOT or $GITROOT."
fi

command -v php >/dev/null 2>&1 || die "php CLI not found in PATH"

# ---------------------------------------------------------------- helper ----
TMPDIR_MQCQ="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_MQCQ"' EXIT

cat > "$TMPDIR_MQCQ/helper.php" <<'PHPEOF'
<?php
// Internal helper for install.sh. Usage:
//   php helper.php <webroot> get-version <frankenstylecomponent>
//   php helper.php <webroot> set-version <frankenstylecomponent> <version>
//   php helper.php <webroot> table-exists <tablename>
define('CLI_SCRIPT', true);
require($argv[1] . '/config.php');
switch ($argv[2]) {
    case 'get-version':
        $rec = $DB->get_record('config_plugins', ['plugin' => $argv[3], 'name' => 'version']);
        echo $rec ? $rec->value : '';
        break;
    case 'set-version':
        $DB->set_field('config_plugins', 'value', $argv[4], ['plugin' => $argv[3], 'name' => 'version']);
        echo 'ok';
        break;
    case 'table-exists':
        echo $DB->get_manager()->table_exists($argv[3]) ? 'yes' : 'no';
        break;
    default:
        echo 'unknown command';
        exit(1);
}
PHPEOF

db_get_version() { php "$TMPDIR_MQCQ/helper.php" "$WEBROOT" get-version "$1" 2>/dev/null | tail -n1; }
db_table_exists() { php "$TMPDIR_MQCQ/helper.php" "$WEBROOT" table-exists "$1" 2>/dev/null | tail -n1; }

# Detects whether the old core patch is applied to mod/quiz.
patch_applied() {
    local hook
    for hook in \
        edit_menu_actions \
        edit_question_actions \
        before_attempt_questions_created \
        before_attempt_saved \
        before_attempt_grade_rescaled \
        before_final_grade_calculated \
        after_all_final_grades_recomputed; do
        [[ -f "$WEBROOT/mod/quiz/classes/hook/$hook.php" ]] || return 1
    done
    grep -Fq 'mod_quiz\hook\edit_menu_actions' \
        "$WEBROOT/mod/quiz/classes/output/edit_renderer.php" 2>/dev/null || return 1
    grep -Fq 'before_attempt_questions_created' \
        "$WEBROOT/mod/quiz/locallib.php" 2>/dev/null || return 1
    return 0
}

# ---------------------------------------------------------------- state -----
log "Webroot: $WEBROOT"
[[ -n $GITROOT ]] && log "Git root: $GITROOT" || log "Not a git checkout."

RELEASE=$(grep -m1 '\$release' "$WEBROOT/version.php" | sed "s/^[^']*'//; s/'.*//")
log "Moodle release: $RELEASE"
[[ $RELEASE == *5.2* ]] || log "WARNING: This plugin supports Moodle 5.2 only (found: $RELEASE)."

V_MODQUIZ=$(db_get_version mod_quiz)
V_NEW=$(db_get_version quizaccess_conditionalquestions)
V_OLD=$(db_get_version local_conditional_questions)
APPLIED=0
if patch_applied; then APPLIED=1; fi

log "mod_quiz version (DB): ${V_MODQUIZ:-not installed}"
log "quizaccess_conditionalquestions (DB): ${V_NEW:-not installed}"
log "local_conditional_questions (DB): ${V_OLD:-not installed}"
log "Old core patch applied: $([[ $APPLIED == 1 ]] && echo yes || echo no)"

if ((APPLIED)) && [[ ! -f $PATCH_FILE ]]; then
    die "Core patch is applied but $PATCH_FILE is missing - cannot revert."
fi
if ((APPLIED)) && [[ -z $GITROOT ]]; then
    die "Core patch is applied but $WEBROOT is not a git checkout. See docs/AGENT_COREPATCH_REMOVAL.md for the manual fallback."
fi

# ---------------------------------------------------------------- confirm ---
if ((!ASSUME_YES && !DRYRUN)); then
    read -r -p "[cq] Proceed with the migration? [y/N] " ANSWER
    [[ $ANSWER == y* ]] || { log "Aborted."; exit 0; }
fi

# ---------------------------------------------------------------- backup ----
if ((SKIP_BACKUP)); then
    log "Skipping backup (--skip-backup)."
elif ((DRYRUN)); then
    log "dry-run: backup of database and mod/quiz code"
else
    BDIR="${MQCQ_BACKUP_DIR:-$(dirname "$WEBROOT")}/conditionalquestions-migration-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "$BDIR"
    log "Backup directory: $BDIR"

    # In a split layout (webroot = public/) the webroot config.php is only a
    # loader; the real credentials live in the git root's config.php.
    CFGFILE="$WEBROOT/config.php"
    if ! grep -q '\$CFG->dbtype' "$CFGFILE" && [[ -n $GITROOT && -f "$GITROOT/config.php" ]]; then
        CFGFILE="$GITROOT/config.php"
    fi
    cfg() { sed -n "s/^\$CFG->$1 *= *['\"]\([^'\"]*\)['\"].*/\1/p" "$CFGFILE" | head -1; }
    DBTYPE=$(cfg dbtype); DBNAME=$(cfg dbname); DBUSER=$(cfg dbuser)
    DBPASS=$(cfg dbpass); DBHOST=$(cfg dbhost)

    case "$DBTYPE" in
        mariadb|mysqli)
            if command -v mariadb-dump >/dev/null 2>&1; then DUMPBIN=mariadb-dump; else DUMPBIN=mysqldump; fi
            log "Dumping database ($DUMPBIN)..."
            MYSQL_PWD="$DBPASS" "$DUMPBIN" --host="${DBHOST:-localhost}" --user="$DBUSER" \
                --single-transaction "$DBNAME" > "$BDIR/database.sql" \
                || die "Database dump failed - aborting (nothing has been changed yet)."
            ;;
        pgsql)
            command -v pg_dump >/dev/null 2>&1 || die "pg_dump not found"
            log "Dumping database (pg_dump)..."
            PGPASSWORD="$DBPASS" pg_dump --host="${DBHOST:-localhost}" --username="$DBUSER" \
                --format=custom "$DBNAME" -f "$BDIR/database.dump" \
                || die "Database dump failed - aborting (nothing has been changed yet)."
            ;;
        *)
            die "Unsupported dbtype '$DBTYPE' - dump the database manually and re-run with --skip-backup."
            ;;
    esac

    log "Archiving current mod/quiz code..."
    tar czf "$BDIR/mod-quiz-code.tgz" -C "$WEBROOT" mod/quiz
    [[ -d "$WEBROOT/local/conditional_questions" ]] && \
        tar czf "$BDIR/local-conditional-questions.tgz" -C "$WEBROOT" local/conditional_questions
    log "NOTE: this is a targeted backup. A full backup of moodledata is still recommended."
fi

# ------------------------------------------------------------ copy files ----
SELF_REAL="$(cd "$SELF_DIR" && pwd -P)"
TARGET_REAL="$(cd "$TARGET" 2>/dev/null && pwd -P || true)"
if [[ "$SELF_REAL" != "$TARGET_REAL" ]]; then
    [[ -f "$SELF_DIR/version.php" ]] || die "Script must be run from the plugin repository root."
    command -v rsync >/dev/null 2>&1 || die "rsync not found in PATH."
    log "Copying plugin to $TARGET ..."
    run mkdir -p "$(dirname "$TARGET")"
    run rsync -a --delete --exclude .git "$SELF_DIR/" "$TARGET/"
else
    log "Plugin files already in place."
fi

# ------------------------------------------------------------ revert patch --
if ((APPLIED)); then
    log "Reverting core patch..."
    run git -C "$GITROOT" apply -R --check "$PATCH_FILE" \
        || die "git apply -R --check failed - core files deviate from the patch. See docs/AGENT_COREPATCH_REMOVAL.md."
    run git -C "$GITROOT" apply -R "$PATCH_FILE"
    patch_applied && die "Patch markers still present after revert - investigate manually."
    log "Core patch reverted cleanly."
else
    log "Core patch not applied - nothing to revert."
fi

# ------------------------------------------------------------ reset version -
if ((APPLIED)) || { [[ -n $V_MODQUIZ ]] && (( V_MODQUIZ > CORE_VERSION )); }; then
    log "Resetting mod_quiz version in DB: ${V_MODQUIZ:-unknown} -> $CORE_VERSION"
    run php "$TMPDIR_MQCQ/helper.php" "$WEBROOT" set-version mod_quiz "$CORE_VERSION"
else
    log "mod_quiz version needs no reset."
fi

# ---------------------------------------------------------------- upgrade ---
log "Running Moodle upgrade (installs this plugin and migrates old data)..."
run_in "$CLIROOT" php admin/cli/upgrade.php --non-interactive --allow-unstable

# --------------------------------------------------------- uninstall old ----
if ((UNINSTALL_OLD)); then
    if [[ -n $V_OLD || -d "$WEBROOT/local/conditional_questions" ]]; then
        log "Uninstalling local_conditional_questions..."
        run_in "$CLIROOT" php admin/cli/uninstall_plugins.php --plugins=local_conditional_questions --run
        if [[ -d "$WEBROOT/local/conditional_questions" ]]; then
            run rm -rf "$WEBROOT/local/conditional_questions"
        fi
    else
        log "Old plugin not installed - nothing to uninstall."
    fi
else
    log "Skipping uninstall of old plugin (--no-uninstall-old)."
fi

# ---------------------------------------------------------------- caches ----
[[ -f "$CLIROOT/admin/cli/purge_caches.php" ]] && run_in "$CLIROOT" php admin/cli/purge_caches.php

# ------------------------------------------------------------- summary ------
log "---- Verification ----"
log "mod_quiz version (DB):                $(db_get_version mod_quiz)"
log "quizaccess_conditionalquestions (DB): $(db_get_version quizaccess_conditionalquestions)"
log "local_conditional_questions (DB):     $(db_get_version local_conditional_questions)"
log "Table quizaccess_condq_rule:   $(db_table_exists quizaccess_condq_rule)"
log "Table quizaccess_condq_attempt: $(db_table_exists quizaccess_condq_attempt)"
log "Table quizaccess_condq_hidden: $(db_table_exists quizaccess_condq_hidden)"
[[ -d "$WEBROOT/local/conditional_questions" ]] \
    && log "Old plugin directory still present: $WEBROOT/local/conditional_questions" \
    || log "Old plugin directory: removed / not present"
log "Done. Please verify a quiz attempt (grading and conditional questions) in the UI."
