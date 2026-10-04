#!/usr/bin/env bash
# Real-HTTP scenarios for the settings form, the AJAX handlers and the screens.
#
# Usage: tests/env/wporg-scenarios.sh <wp-env dir>
#
# Drives the throwaway env the way a browser does: logs in through
# wp-login.php with a cookie jar, scrapes the real nonces from the plugin's
# screens, and posts the same fields the settings form and admin.js post.
# Only this plugin's own options and tables are changed, and only posts whose
# title starts with "DIL scn" are created; all of it is put back at the end.

set -u -o pipefail

ENV_DIR="${1:?usage: $0 <wp-env dir>}"
ENV_DIR="$(cd "$ENV_DIR" && pwd)"
WORKSPACE=/Users/rich/Sites/wp-plugins
export NODE_OPTIONS="--require $WORKSPACE/force-ipv4.js"

PORT="$(sed -n 's/.*"port":[[:space:]]*\([0-9]*\).*/\1/p' "$ENV_DIR/.wp-env.json" | head -1)"
BASE="http://localhost:${PORT}"
ENV_NAME="$(basename "$ENV_DIR")"
CLI="$(docker ps --format '{{.Names}}' | grep -- "^wp-env-${ENV_NAME}-[0-9a-f]*-cli-1\$" | head -1)"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
RUN="$(date +%s)"

wp() {
	if [ -n "$CLI" ]; then
		docker exec -u www-data "$CLI" wp --path=/var/www/html "$@" 2>/dev/null < /dev/null
	else
		( cd "$ENV_DIR" && "$WORKSPACE/node_modules/.bin/wp-env" run cli wp "$@" 2>/dev/null < /dev/null )
	fi
}

# PHP from stdin, run inside WordPress.
wpeval() {
	wp eval "$(cat)" | tr -d '\r'
}

PASS=0
FAIL=0
pass() { PASS=$(( PASS + 1 )); printf 'PASS  %s\n' "$1"; }
fail() { FAIL=$(( FAIL + 1 )); printf 'FAIL  %s  (%s)\n' "$1" "$2"; }
eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected [$2], got [$(printf '%.300s' "$3")]"; fi; }
ne() { if [ "$2" != "$3" ]; then pass "$1"; else fail "$1" "still [$(printf '%.300s' "$3")]"; fi; }
has() { case "$3" in *"$2"*) pass "$1" ;; *) fail "$1" "missing [$2] in [$(printf '%.300s' "$3")]" ;; esac; }
hasnt() { case "$3" in *"$2"*) fail "$1" "unexpected [$2]" ;; *) pass "$1" ;; esac; }

PREFIX="$(wp db prefix | tr -d '\r\n')"
LINKS="${PREFIX}dil_links"
SUGS="${PREFIX}dil_suggestions"
sql() { wp db query "$1" --skip-column-names | tr -d '\r'; }

LOG_START="$(wp eval 'echo (int) @filesize( WP_CONTENT_DIR . "/debug.log" );' | tr -d '\r\n')"
LOG_START="${LOG_START:-0}"

echo "env=$ENV_NAME base=$BASE run=$RUN log_start=$LOG_START"

eq "the plugin is active" "active" "$(wp plugin get dragon-internal-links --field=status | tr -d '\r\n')"
echo "plugin version: $(wp plugin get dragon-internal-links --field=version | tr -d '\r\n')"

# --- this plugin's settings as they are now, restored at the end --------------

SAVED="$(wpeval <<'PHP'
global $wpdb;
$rows = array();
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'dragoninternallinks_' ) . '%' ) ) as $row ) {
	$rows[ $row->option_name ] = $row->option_value;
}
echo base64_encode( serialize( $rows ) );
PHP
)"
[ -n "$SAVED" ] && pass "current settings recorded for the restore" || fail "current settings recorded" "empty"

restore() {
	wpeval <<PHP
global \$wpdb;
\$rows = unserialize( base64_decode( '$SAVED' ) );
\$now  = \$wpdb->get_col( \$wpdb->prepare( "SELECT option_name FROM {\$wpdb->options} WHERE option_name LIKE %s", \$wpdb->esc_like( 'dragoninternallinks_' ) . '%' ) );
foreach ( \$now as \$name ) {
	if ( ! array_key_exists( \$name, \$rows ) ) {
		delete_option( \$name );
	}
}
foreach ( \$rows as \$name => \$value ) {
	update_option( \$name, maybe_unserialize( \$value ) );
}
\DragonInternalLinks\Scheduler::reschedule( \DragonInternalLinks\Scheduler::frequency() );
echo 'restored';
PHP
}

# Fingerprint of every setting the form writes, plus the scan schedule.
settings_snap() {
	wpeval <<'PHP'
$out = array();
foreach ( array( 'post_types', 'auto_scan', 'min_word_count', 'exclude_categories', 'scan_frequency', 'ai_enabled', 'ai_provider', 'ai_model', 'ai_api_key', 'delete_data_on_uninstall' ) as $key ) {
	$out[ $key ] = get_option( 'dragoninternallinks_' . $key, '(unset)' );
}
echo md5( serialize( $out ) ) . ' ' . wp_get_schedule( 'dragoninternallinks_daily_scan' );
PHP
}

opt() {
	wp eval "echo wp_json_encode( get_option( 'dragoninternallinks_$1', '(unset)' ) );" | tr -d '\r\n'
}

# --- users and cookies ---------------------------------------------------------

SUB=dilscn_sub
wp user get "$SUB" --field=ID >/dev/null || wp user create "$SUB" "$SUB@example.test" --role=subscriber --user_pass=dilscn-pass >/dev/null
wp user update "$SUB" --user_pass=dilscn-pass >/dev/null

login() {
	local jar="$1" user="$2" pass="$3"
	curl -s -o /dev/null -c "$jar" "$BASE/wp-login.php"
	curl -s -o /dev/null -b "$jar" -c "$jar" \
		--data-urlencode "log=$user" --data-urlencode "pwd=$pass" \
		--data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1" \
		--data-urlencode "redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}

ADMIN_JAR="$TMP/admin.jar"
SUB_JAR="$TMP/sub.jar"
login "$ADMIN_JAR" admin password
login "$SUB_JAR" "$SUB" dilscn-pass

SCREEN="$BASE/wp-admin/tools.php?page=dragon-internal-links"
SETTINGS_URL="$SCREEN&tab=settings"

DASH="$(curl -s -b "$ADMIN_JAR" "$SCREEN")"
NONCE="$(printf '%s' "$DASH" | sed -n 's/.*var dilAdmin = {.*"nonce":"\([0-9a-f]*\)".*/\1/p' | head -1)"
SETTINGS_HTML="$(curl -s -b "$ADMIN_JAR" "$SETTINGS_URL")"
SNONCE="$(printf '%s' "$SETTINGS_HTML" | sed -n 's/.*name="dragoninternallinks_settings_nonce" value="\([0-9a-f]*\)".*/\1/p' | head -1)"
if [ -z "$NONCE" ] || [ -z "$SNONCE" ]; then
	echo "could not scrape the nonces (ajax=[$NONCE] settings=[$SNONCE]); aborting"
	exit 1
fi
pass "AJAX nonce scraped from the dashboard screen"
pass "settings nonce scraped from the settings form"

# A subscriber never sees the plugin's screens, so mint the nonces they would
# hold from their own session (a nonce is bound to the session token).
SUB_COOKIE="$(awk '$6 ~ /^wordpress_logged_in_/ { print $7 }' "$SUB_JAR" | head -1)"
sub_nonce() {
	wpeval <<PHP | tr -d '\n'
\$_COOKIE[ LOGGED_IN_COOKIE ] = urldecode( '$SUB_COOKIE' );
wp_set_current_user( get_user_by( 'login', '$SUB' )->ID );
echo wp_create_nonce( '$1' );
PHP
}
SUB_NONCE="$(sub_nonce dragoninternallinks_admin_nonce)"
SUB_SNONCE="$(sub_nonce dragoninternallinks_save_settings)"
[ -n "$SUB_NONCE" ] && [ -n "$SUB_SNONCE" ] && pass "subscriber nonces minted from their session" || fail "subscriber nonces minted" "empty"

# save <jar> <field=value>... -> prints "<status>|<body>" of the settings screen
save() {
	local jar="$1"
	shift
	local args=() f
	for f in "$@"; do args+=( --data-urlencode "$f" ); done
	printf '%s|' "$(curl -s -b "$jar" -o "$TMP/save.html" -w '%{http_code}' "${args[@]}" "$SETTINGS_URL")"
	cat "$TMP/save.html"
}

# ajax <jar> <field=value>... -> prints "<status>|<body>"
ajax() {
	local jar="$1"
	shift
	local args=() f
	for f in "$@"; do args+=( --data-urlencode "$f" ); done
	curl -s -b "$jar" -w '|%{http_code}' "${args[@]}" "$BASE/wp-admin/admin-ajax.php" | awk -F'|' '{ status=$NF; sub(/\|[0-9]+$/, ""); print status "|" $0 }'
}

# refused <label> <action> <snapshot command> <field=value>...
# Missing nonce and a bad nonce end with core's "-1" and 403; a subscriber
# with their own valid nonce gets the plugin's JSON error. The snapshot is the
# same before and after all three.
refused() {
	local label="$1" action="$2" snap="$3"
	shift 3
	local before after r
	before="$(eval "$snap")"
	r="$(ajax "$ADMIN_JAR" "action=$action" "$@")"
	eq "$label: missing nonce refused" '403|-1' "$r"
	r="$(ajax "$ADMIN_JAR" "action=$action" "nonce=0badc0ffee" "$@")"
	eq "$label: bad nonce refused" '403|-1' "$r"
	r="$(ajax "$SUB_JAR" "action=$action" "nonce=$SUB_NONCE" "$@")"
	eq "$label: subscriber refused" '200|{"success":false,"data":{"message":"Permission denied."}}' "$r"
	after="$(eval "$snap")"
	eq "$label: nothing changed by the refused requests" "$before" "$after"
}

# --- settings form -------------------------------------------------------------

CAT="$(wp term create category "DIL scn cat $RUN" --porcelain | tr -d '\r\n')"
case "$CAT" in ''|*[!0-9]*) fail "test category created" "got [$CAT]" ;; *) pass "test category created" ;; esac

# A key with every character a text sanitizer would alter.
KEY='sk-ant-api03-Ab%4F_c+d/e=f<g>h"i'"'"'j\k  l&m ключ'
KEY_B64="$(printf '%s' "$KEY" | base64 | tr -d '\n')"

FORM=(
	"dragoninternallinks_post_types[]=post"
	"dragoninternallinks_post_types[]=page"
	"dragoninternallinks_auto_scan=1"
	"dragoninternallinks_scan_frequency=weekly"
	"dragoninternallinks_min_word_count=4"
	"dragoninternallinks_exclude_categories[]=$CAT"
	"dragoninternallinks_ai_enabled=1"
	"dragoninternallinks_ai_provider=anthropic"
	"dragoninternallinks_ai_model=claude-haiku-4-5-20251001"
	"dragoninternallinks_ai_api_key=$KEY"
	"dragoninternallinks_delete_data=1"
	"submit=Save Settings"
)

has "settings screen renders the form" 'name="dragoninternallinks_settings_nonce"' "$SETTINGS_HTML"

BEFORE="$(settings_snap)"
R="$(save "$ADMIN_JAR" "${FORM[@]}")"
eq "settings: missing nonce re-renders the screen" "200" "${R%%|*}"
hasnt "settings: missing nonce is not reported as saved" "Settings saved." "$R"
R="$(save "$ADMIN_JAR" "dragoninternallinks_settings_nonce=0badc0ffee" "${FORM[@]}")"
eq "settings: bad nonce re-renders the screen" "200" "${R%%|*}"
hasnt "settings: bad nonce is not reported as saved" "Settings saved." "$R"
R="$(save "$SUB_JAR" "dragoninternallinks_settings_nonce=$SUB_SNONCE" "${FORM[@]}")"
eq "settings: subscriber is refused the screen" "403" "${R%%|*}"
hasnt "settings: subscriber is not shown the form" 'name="dragoninternallinks_settings_nonce"' "$R"
eq "settings: nothing changed by the refused requests" "$BEFORE" "$(settings_snap)"

R="$(save "$ADMIN_JAR" "dragoninternallinks_settings_nonce=$SNONCE" "${FORM[@]}")"
eq "settings: valid save returns the screen" "200" "${R%%|*}"
has "settings: valid save is reported" "Settings saved." "$R"
eq "settings: post types saved" '["post","page"]' "$(opt post_types)"
eq "settings: auto-scan saved" '"1"' "$(opt auto_scan)"
eq "settings: frequency saved" '"weekly"' "$(opt scan_frequency)"
eq "settings: scan rescheduled weekly" 'weekly' "$(wp eval 'echo wp_get_schedule( "dragoninternallinks_daily_scan" );' | tr -d '\r\n')"
eq "settings: minimum words saved" '"4"' "$(opt min_word_count)"
eq "settings: excluded category saved" "[$CAT]" "$(opt exclude_categories)"
eq "settings: AI ranking saved" '"1"' "$(opt ai_enabled)"
eq "settings: provider saved" '"anthropic"' "$(opt ai_provider)"
eq "settings: model saved" '"claude-haiku-4-5-20251001"' "$(opt ai_model)"
eq "settings: delete-on-uninstall saved" '"1"' "$(opt delete_data_on_uninstall)"
eq "settings: API key decrypts to exactly what was typed" "$KEY_B64" "$(wp eval 'echo base64_encode( \DragonInternalLinks\AI_Ranker::api_key() );' | tr -d '\r\n')"
hasnt "settings: stored API key is not the plain key" 'sk-ant-api03' "$(opt ai_api_key)"
hasnt "settings: the screen never prints the key back" 'sk-ant-api03' "$R"
has "settings: the screen shows the masked placeholder" 'value="••••••••"' "$R"
has "settings: saved category is ticked on the screen" "value=\"$CAT\"" "$R"

# The saved key reaches the provider request unchanged. The request itself is
# answered locally, so nothing leaves the site.
AI="$(wpeval <<'PHP'
$seen = array( '', array() );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$seen ) {
		$seen = array( $url, $args['headers'] );
		return array(
			'headers'  => array(),
			'body'     => '{"content":[{"type":"text","text":"{\"1\":90}"}]}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$scores = \DragonInternalLinks\AI_Ranker::rank(
	array(
		'title' => 'DIL scn source',
		'text'  => 'DIL scn text',
	),
	array(
		7 => array(
			'title'   => 'DIL scn target',
			'excerpt' => 'x',
			'keyword' => 'target',
		),
	)
);
echo $seen[0] . '|' . base64_encode( (string) ( $seen[1]['x-api-key'] ?? '' ) ) . '|' . wp_json_encode( $scores );
PHP
)"
eq "AI ranking sends the saved key, byte for byte, to the chosen provider" "https://api.anthropic.com/v1/messages|$KEY_B64|{\"7\":90}" "$AI"

STORED="$(opt ai_api_key)"
R="$(save "$ADMIN_JAR" "dragoninternallinks_settings_nonce=$SNONCE" "${FORM[@]/#dragoninternallinks_ai_api_key=*/dragoninternallinks_ai_api_key=••••••••}")"
has "settings: save with the masked placeholder is reported" "Settings saved." "$R"
eq "settings: masked placeholder keeps the stored key" "$STORED" "$(opt ai_api_key)"
eq "settings: the key is remembered with its provider" '"anthropic"' "$(opt ai_key_provider)"

# Changing provider without entering a new key removes the key, and nothing is
# sent with it to the new provider (the later provider field wins).
R="$(save "$ADMIN_JAR" "dragoninternallinks_settings_nonce=$SNONCE" "${FORM[@]/#dragoninternallinks_ai_api_key=*/dragoninternallinks_ai_api_key=••••••••}" "dragoninternallinks_ai_provider=google")"
has "provider switch: the screen says the key was removed" "removed because it belongs to the previous AI provider" "$R"
eq "provider switch: provider saved" '"google"' "$(opt ai_provider)"
eq "provider switch: the old key is gone" '"(unset)"' "$(opt ai_api_key)"
eq "provider switch: its provider is forgotten" '"(unset)"' "$(opt ai_key_provider)"
hasnt "provider switch: the key field is empty, not masked" 'value="••••••••"' "$R"
AI="$(wpeval <<'PHP'
$seen = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$seen ) {
		$seen[] = $url;
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$scores = \DragonInternalLinks\AI_Ranker::rank(
	array(
		'title' => 'DIL scn source',
		'text'  => 'DIL scn text',
	),
	array(
		7 => array(
			'title'   => 'DIL scn target',
			'excerpt' => 'x',
			'keyword' => 'target',
		),
	)
);
echo count( $seen ) . '|' . wp_json_encode( $scores );
PHP
)"
eq "provider switch: no request is sent with the old key" "0|null" "$AI"

# Unticked boxes and empty lists; an empty key field removes the key.
R="$(save "$ADMIN_JAR" "dragoninternallinks_settings_nonce=$SNONCE" \
	"dragoninternallinks_post_types[]=post" "dragoninternallinks_post_types[]=page" \
	"dragoninternallinks_auto_scan=1" "dragoninternallinks_scan_frequency=daily" \
	"dragoninternallinks_min_word_count=3" "dragoninternallinks_ai_provider=openai" \
	"dragoninternallinks_ai_model=" "dragoninternallinks_ai_api_key=" "submit=Save Settings")"
has "settings: second save is reported" "Settings saved." "$R"
eq "settings: empty category list saved" '[]' "$(opt exclude_categories)"
eq "settings: AI ranking off when unticked" '""' "$(opt ai_enabled)"
eq "settings: delete-on-uninstall off when unticked" '""' "$(opt delete_data_on_uninstall)"
eq "settings: empty key field removes the key" '"(unset)"' "$(opt ai_api_key)"
eq "settings: provider changed" '"openai"' "$(opt ai_provider)"
eq "settings: frequency back to daily" '"daily"' "$(opt scan_frequency)"
eq "settings: scan rescheduled daily" 'daily' "$(wp eval 'echo wp_get_schedule( "dragoninternallinks_daily_scan" );' | tr -d '\r\n')"

# --- fixtures -------------------------------------------------------------------

mkpost() {
	local title="$1" content="$2"
	wpeval <<PHP | tr -d '\n'
echo wp_insert_post( array( 'post_title' => '$title', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => wp_slash( base64_decode( '$(printf '%s' "$content" | base64 | tr -d '\n')' ) ) ) );
PHP
}

KW1="DIL scn coffee beans guide $RUN"
KW2="DIL scn tea brewing handbook $RUN"

T1="$(mkpost "$KW1" "<!-- wp:paragraph --><p>Everything about choosing and storing coffee beans.</p><!-- /wp:paragraph -->")"
T2="$(mkpost "$KW2" "<!-- wp:paragraph --><p>Everything about water temperature and steeping tea.</p><!-- /wp:paragraph -->")"
T2_URL="$(wp eval "echo get_permalink( $T2 );" | tr -d '\r\n')"
T1_URL="$(wp eval "echo get_permalink( $T1 );" | tr -d '\r\n')"
S1="$(mkpost "DIL scn source one $RUN" "<!-- wp:paragraph --><p>Read our $KW1 today, then see <a href=\"$T2_URL\">this page</a>.</p><!-- /wp:paragraph -->")"
S2="$(mkpost "DIL scn source two $RUN" "<!-- wp:paragraph --><p>Before you start, open the $KW2 and keep it nearby.</p><!-- /wp:paragraph -->")"

# A password-protected post that would otherwise be both a target (source
# three names it) and a source (it names the tea handbook).
KWP="DIL scn private roadmap notes $RUN"
MARK="DILSCN-CONFIDENTIAL-$RUN"
PP="$(mkpost "$KWP" "<!-- wp:paragraph --><p>$MARK the plan. See the $KW2 for the cover story.</p><!-- /wp:paragraph -->")"
wp post update "$PP" --post_password=dilscn-secret >/dev/null
S3="$(mkpost "DIL scn source three $RUN" "<!-- wp:paragraph --><p>Compare the $KWP with the $KW2 before you start.</p><!-- /wp:paragraph -->")"

# A code sample and an embed URL that hold the keyword before any prose does.
KWC="dilscncode$RUN"
KWE="dilscnembed$RUN"
C4="<!-- wp:code --><pre class=\"wp-block-code\"><code>run $KWC --now</code></pre><!-- /wp:code --><!-- wp:paragraph --><p>Prose about $KWC here.</p><!-- /wp:paragraph -->"
C5="<!-- wp:embed {\"url\":\"https://example.test/$KWE-guide/\",\"type\":\"rich\"} -->
<figure class=\"wp-block-embed is-type-rich\"><div class=\"wp-block-embed__wrapper\">
https://example.test/$KWE-guide/
</div></figure>
<!-- /wp:embed -->"
S4="$(mkpost "DIL scn code sample $RUN" "$C4")"
S5="$(mkpost "DIL scn embed $RUN" "$C5")"

# A Verse block is prose, so its words are linked as before.
KWV="dilscnverse$RUN"
C6="<!-- wp:verse -->
<pre class=\"wp-block-verse\">The morning $KWV
wakes the town</pre>
<!-- /wp:verse -->"
S6="$(mkpost "DIL scn verse $RUN" "$C6")"

ALL="$T1,$T2,$S1,$S2,$PP,$S3,$S4,$S5,$S6"
case "${ALL//,/}" in *[!0-9]*|'') fail "test posts created" "ids [$ALL]" ;; *) pass "test posts created" ;; esac
eq "the protected post has its password" "dilscn-secret" "$(wp post get "$PP" --field=post_password | tr -d '\r\n')"

# plant <source id> <target id> <keyword> -> id of a new pending suggestion
plant() {
	wpeval <<PHP | tr -d '\n'
global \$wpdb;
\$wpdb->insert(
	'$SUGS',
	array(
		'source_post_id'  => $1,
		'target_post_id'  => $2,
		'keyword'         => '$3',
		'context'         => 'DIL scn planted',
		'relevance_score' => 50,
		'status'          => 'pending',
	)
);
echo (int) \$wpdb->insert_id;
PHP
}
sug_status() { sql "SELECT status FROM $SUGS WHERE id = $1"; }
content_md5() { wp eval "echo md5( get_post( $1 )->post_content );" | tr -d '\r\n'; }
links_from() { sql "SELECT COUNT(*) FROM $LINKS WHERE source_post_id = $1"; }

# --- scan one post ---------------------------------------------------------------

sql "DELETE FROM $LINKS WHERE source_post_id = $S1" >/dev/null
eq "scan post: the index holds no link from the source before the scan" "0" "$(links_from "$S1")"
refused "scan post" dragoninternallinks_scan_post "links_from $S1" "post_id=$S1"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_scan_post" "nonce=$NONCE" "post_id=$S1")"
eq "scan post: valid request reports the link it found" '200|{"success":true,"data":{"links_found":1,"message":"Found 1 internal link."}}' "$R"
eq "scan post: the link is indexed" "1" "$(sql "SELECT COUNT(*) FROM $LINKS WHERE source_post_id = $S1 AND target_post_id = $T2")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_scan_post" "nonce=$NONCE")"
eq "scan post: no post id is an error" '200|{"success":false,"data":{"message":"Invalid post ID."}}' "$R"

# --- scan everything -------------------------------------------------------------

sql "DELETE FROM $LINKS WHERE source_post_id = $S1" >/dev/null
wp option update dragoninternallinks_last_scan 1 >/dev/null
refused "scan all" dragoninternallinks_scan_all "echo \$(links_from $S1) \$(opt last_scan)" "offset=0" "failed=0"

OFFSET=0
COMPLETE=""
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
	R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_scan_all" "nonce=$NONCE" "offset=$OFFSET" "failed=0")"
	case "$R" in '200|{"success":true'*) ;; *) break ;; esac
	case "$R" in *'"complete":true'*) COMPLETE=1; break ;; esac
	OFFSET="$(printf '%s' "$R" | sed -n 's/.*"offset":\([0-9]*\).*/\1/p')"
done
eq "scan all: batches run to completion" "1" "$COMPLETE"
has "scan all: completion message" 'Scan complete! Processed' "$R"
has "scan all: nothing failed" '"failed":0' "$R"
eq "scan all: the source's link is indexed again" "1" "$(links_from "$S1")"
ne "scan all: last scan time recorded" "1" "$(opt last_scan)"

# --- dismiss a suggestion ---------------------------------------------------------

SUG_D="$(plant "$S2" "$T1" "DIL scn dismiss $RUN")"
refused "dismiss" dragoninternallinks_dismiss_suggestion "sug_status $SUG_D" "suggestion_id=$SUG_D"
eq "dismiss: suggestion still pending after the refused requests" "pending" "$(sug_status "$SUG_D")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_dismiss_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_D")"
eq "dismiss: valid request succeeds" '200|{"success":true,"data":{"message":"Suggestion dismissed."}}' "$R"
eq "dismiss: suggestion is dismissed" "dismissed" "$(sug_status "$SUG_D")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_dismiss_suggestion" "nonce=$NONCE")"
eq "dismiss: no suggestion id is an error" '200|{"success":false,"data":{"message":"Invalid suggestion ID."}}' "$R"

# --- apply a suggestion -----------------------------------------------------------

SUG_A="$(plant "$S1" "$T1" "$KW1")"
SUGGESTIONS_HTML="$(curl -s -b "$ADMIN_JAR" "$SCREEN&tab=suggestions")"
has "suggestions screen lists the pending suggestion" "data-id=\"$SUG_A\"" "$SUGGESTIONS_HTML"
hasnt "suggestions screen leaves out the dismissed suggestion" "data-id=\"$SUG_D\"" "$SUGGESTIONS_HTML"

refused "apply" dragoninternallinks_apply_suggestion "echo \$(sug_status $SUG_A) \$(content_md5 $S1)" "suggestion_id=$SUG_A"
hasnt "apply: post has no link to the target after the refused requests" "href=\"$T1_URL\"" "$(wp post get "$S1" --field=post_content)"

# The Zero Inbound Links section of the Orphan Posts tab.
orphan_section() {
	local html tmp
	html="$(curl -s -b "$ADMIN_JAR" "$SCREEN&tab=orphans")"
	tmp="${html#*Zero Inbound Links}"
	printf '%s' "${tmp%%Few Outbound Links*}"
}
inbound_of() { sql "SELECT inbound_count FROM ${PREFIX}dil_stats WHERE post_id = $1"; }
eq "orphan: the target has no inbound link before the apply" "0" "$(inbound_of "$T1")"
has "orphan: the target is listed on the Orphan Posts tab" "$KW1" "$(orphan_section)"

R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_A")"
has "apply: valid request succeeds" '200|{"success":true,"data":{"message":"Link added successfully!"' "$R"
CONTENT="$(wp post get "$S1" --field=post_content)"
has "apply: the keyword in the post is now a link to the target" "<p>Read our <a href=\"$T1_URL\">$KW1</a> today, then see <a href=\"$T2_URL\">this page</a>.</p>" "$CONTENT"
has "apply: block markup is kept" '<!-- wp:paragraph -->' "$CONTENT"
eq "apply: suggestion is marked applied" "applied" "$(sug_status "$SUG_A")"
eq "apply: the new link is indexed" "1" "$(sql "SELECT COUNT(*) FROM $LINKS WHERE source_post_id = $S1 AND target_post_id = $T1")"
eq "orphan: the target's inbound count is 1 straight after the apply" "1" "$(inbound_of "$T1")"
hasnt "orphan: the target left the Orphan Posts tab without a rescan" "$KW1" "$(orphan_section)"

MD5="$(content_md5 "$S1")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_A")"
has "apply: a second apply is refused as already applied" 'already been applied or dismissed' "$R"
eq "apply: post unchanged by the second apply" "$MD5" "$(content_md5 "$S1")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=999999999")"
eq "apply: unknown suggestion is an error" '200|{"success":false,"data":{"message":"Suggestion not found."}}' "$R"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_D")"
has "apply: a dismissed suggestion is refused" 'already been applied or dismissed' "$R"

# Code samples and embed URLs are left alone.
SUG_C="$(plant "$S4" "$T1" "$KWC")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_C")"
has "code: apply succeeds on the prose" '"success":true' "$R"
CONTENT="$(wp post get "$S4" --field=post_content)"
has "code: the code block is unchanged" "<pre class=\"wp-block-code\"><code>run $KWC --now</code></pre>" "$CONTENT"
has "code: the prose occurrence is linked" "<p>Prose about <a href=\"$T1_URL\">$KWC</a> here.</p>" "$CONTENT"

SUG_E="$(plant "$S5" "$T1" "$KWE")"
MD5="$(content_md5 "$S5")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_E")"
eq "embed: a word only inside the embed URL is not linked" '200|{"success":false,"data":{"message":"Could not find keyword in content."}}' "$R"
eq "embed: the post is unchanged" "$MD5" "$(content_md5 "$S5")"
eq "embed: the suggestion stays pending" "pending" "$(sug_status "$SUG_E")"

SUG_V="$(plant "$S6" "$T1" "$KWV")"
R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_V")"
has "verse: apply succeeds inside a Verse block" '"success":true' "$R"
has "verse: the word in the Verse block is linked" "<pre class=\"wp-block-verse\">The morning <a href=\"$T1_URL\">$KWV</a>" "$(wp post get "$S6" --field=post_content)"
eq "verse: suggestion marked applied" "applied" "$(sug_status "$SUG_V")"

# --- generate suggestions ---------------------------------------------------------

SUG_G="$(plant "$S2" "$T1" "DIL scn stale $RUN")"
refused "generate" dragoninternallinks_generate_suggestions "sug_status $SUG_G" "offset=0" "failed=0" "stale=0"
eq "generate: pending suggestions kept by the refused requests" "pending" "$(sug_status "$SUG_G")"

OFFSET=0
DONE=""
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30; do
	R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_generate_suggestions" "nonce=$NONCE" "offset=$OFFSET" "failed=0" "stale=0")"
	case "$R" in '200|{"success":true'*) ;; *) break ;; esac
	case "$R" in *'"done":true'*) DONE=1; break ;; esac
	OFFSET="$(printf '%s' "$R" | sed -n 's/.*"offset":\([0-9]*\).*/\1/p')"
done
eq "generate: batches run to completion" "1" "$DONE"
has "generate: completion message" 'Done - analyzed' "$R"
has "generate: nothing failed and nothing stale" '"failed":0,"stale":false,"warning":false' "$R"
eq "generate: the earlier pending list was replaced" "" "$(sug_status "$SUG_G")"
eq "generate: applied and dismissed suggestions are kept" "applied dismissed" "$(echo $(sug_status "$SUG_A") $(sug_status "$SUG_D"))"
eq "protected: no suggestion links from or to the protected post" "0" "$(sql "SELECT COUNT(*) FROM $SUGS WHERE source_post_id = $PP OR target_post_id = $PP")"
eq "protected: source three still gets its other suggestion" "1" "$(sql "SELECT COUNT(*) > 0 FROM $SUGS WHERE source_post_id = $S3 AND target_post_id = $T2")"

# With AI ranking on, the protected post never reaches a request. The requests
# are answered locally, so nothing leaves the site.
PROT="$(wpeval <<PHP
update_option( 'dragoninternallinks_ai_enabled', true );
update_option( 'dragoninternallinks_ai_provider', 'openai' );
update_option( 'dragoninternallinks_ai_model', '' );
update_option( 'dragoninternallinks_ai_api_key', \DragonInternalLinks\AI_Ranker::encrypt_key( 'sk-dilscn' ) );
update_option( 'dragoninternallinks_ai_key_provider', 'openai' );
\$bodies = array();
add_filter(
	'pre_http_request',
	static function ( \$pre, \$args, \$url ) use ( &\$bodies ) {
		\$bodies[] = (string) \$args['body'];
		return array(
			'headers'  => array(),
			'body'     => '{"choices":[{"message":{"content":"{\\\\"1\\\\":90,\\\\"2\\\\":80,\\\\"3\\\\":70}"}}]}',
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
\$an      = new \DragonInternalLinks\Analyzer( new \DragonInternalLinks\Scanner() );
\$from_s3 = array_map( 'intval', array_column( \$an->generate_suggestions_for_post( $S3 ), 'target_post_id' ) );
\$from_pp = \$an->generate_suggestions_for_post( $PP );
\$all     = implode( "\n", \$bodies );
delete_option( 'dragoninternallinks_ai_enabled' );
delete_option( 'dragoninternallinks_ai_api_key' );
delete_option( 'dragoninternallinks_ai_key_provider' );
echo count( \$bodies ) . '|' . ( in_array( $PP, \$from_s3, true ) ? 'pp-suggested' : 'pp-left-out' ) . '|' . count( \$from_pp ) . '|'
	. ( str_contains( \$all, '$MARK' ) ? 'marker-sent' : 'marker-kept' ) . '|' . ( str_contains( \$all, 'title: $KWP' ) ? 'candidate-sent' : 'not-a-candidate' ) . '|'
	. ( str_contains( \$all, '$KW2' ) ? 'control-sent' : 'control-missing' );
PHP
)"
eq "protected: one request for source three, none for the protected post, nothing of it sent" "1|pp-left-out|0|marker-kept|not-a-candidate|control-sent" "$PROT"

SUG_R="$(sql "SELECT id FROM $SUGS WHERE source_post_id = $S2 AND target_post_id = $T2 AND status = 'pending' ORDER BY relevance_score DESC LIMIT 1")"
case "$SUG_R" in
	''|*[!0-9]*) fail "generate: a suggestion from source two to the page it names was found" "none in $SUGS" ;;
	*)
		pass "generate: a suggestion from source two to the page it names was found"
		R="$(ajax "$ADMIN_JAR" "action=dragoninternallinks_apply_suggestion" "nonce=$NONCE" "suggestion_id=$SUG_R")"
		has "generated suggestion: apply succeeds" '"success":true' "$R"
		has "generated suggestion: the post now links to the page" "<a href=\"$T2_URL\">" "$(wp post get "$S2" --field=post_content)"
		eq "generated suggestion: marked applied" "applied" "$(sug_status "$SUG_R")"
		;;
esac

# --- screens ----------------------------------------------------------------------

for TAB in "" "&tab=orphans" "&tab=suggestions" "&tab=settings" "&tab=nosuchtab" "&tab=%3Cscript%3E" "&tab[]=settings"; do
	R="$(curl -s -g -b "$ADMIN_JAR" -w '|%{http_code}' "$SCREEN$TAB")"
	eq "screen [${TAB:-dashboard}] loads" "200" "${R##*|}"
	has "screen [${TAB:-dashboard}] shows the plugin heading" 'Dragon Internal Links' "$R"
	hasnt "screen [${TAB:-dashboard}] has no PHP error text" 'Fatal error' "$R"
done
R="$(curl -s -b "$SUB_JAR" -o /dev/null -w '%{http_code}' "$SCREEN")"
eq "a subscriber cannot open the screen" "403" "$R"

# --- clean up ---------------------------------------------------------------------

sql "DELETE FROM $SUGS WHERE source_post_id IN ($ALL) OR target_post_id IN ($ALL)" >/dev/null
for ID in "$S1" "$S2" "$S3" "$S4" "$S5" "$S6" "$PP" "$T1" "$T2"; do
	wp post delete "$ID" --force >/dev/null
done
eq "test posts removed" "0" "$(sql "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_title LIKE 'DIL scn%'")"
eq "index rows of the test posts removed" "0" "$(sql "SELECT COUNT(*) FROM $LINKS WHERE source_post_id IN ($ALL) OR target_post_id IN ($ALL)")"
wp term delete category "$CAT" >/dev/null
wp user delete "$SUB" --yes >/dev/null
eq "settings put back as they were" "restored" "$(restore)"

# --- log --------------------------------------------------------------------------

NEW_LOG="$(wp eval "\$f = WP_CONTENT_DIR . '/debug.log'; if ( is_file( \$f ) ) { \$h = fopen( \$f, 'r' ); fseek( \$h, $LOG_START ); echo stream_get_contents( \$h ); }" | grep -E '/plugins/dragon-internal-links(-pro)?/' || true)"
eq "no PHP notices or warnings from this plugin in debug.log" "" "$NEW_LOG"

TOTAL=$(( PASS + FAIL ))
echo "$PASS/$TOTAL passed"
[ "$FAIL" -eq 0 ]
