<?php
/**
 * Role test on the LIVE site (Task 19, checklist item 5). Run only with the owner's OK:
 *
 *   cd ~/domains/citycharcha.com/public_html && wp eval-file ~/citycharcha/scripts/live-role-test.php
 *
 * Runs inside WordPress as each role in turn (Visitor, Member, Writer, Trusted writer, Editor,
 * second Editor) through the same REST API and rules the website and app use. Safe for the live site:
 *   - no e-mail is sent during the test (all wp_mail calls are short-circuited);
 *   - test accounts are portal_livetest_* (e-mail @example.com), test stories are titled
 *     "[TEST] please ignore …"; published test stories exist only for seconds;
 *   - at the end, everything the test created is deleted: users, their stories, tips, series,
 *     likes, reports and the audit-log lines written during this run. Then the page cache is cleared.
 *
 * @package Portal
 */

global $wpdb;

add_filter( 'pre_wp_mail', '__return_true' );               // no e-mails during the test.
remove_action( 'publish_post', '_publish_post_hook', 5 );   // no "pings" to outside services.

$tag   = '[TEST] please ignore ' . strtolower( wp_generate_password( 4, false ) );
$pass  = 0;
$fail  = 0;
$audit = $wpdb->prefix . 'portal_audit_log';
$reps  = $wpdb->prefix . 'portal_reports';
$audit_start   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$audit}" ); // phpcs:ignore WordPress.DB
$reports_start = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$reps}" );  // phpcs:ignore WordPress.DB

$check = static function ( $name, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) {
		++$pass;
		WP_CLI::log( 'PASS  ' . $name );
	} else {
		++$fail;
		WP_CLI::log( 'FAIL  ' . $name . ( $detail ? '  -> ' . $detail : '' ) );
	}
};

// One REST call as a given user (0 = visitor).
$rest = static function ( $method, $route, array $params = array(), $user = 0 ) {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array(
		'code' => $response->get_status(),
		'data' => $response->get_data(),
	);
};

$cat = static function ( $slug ) {
	$term = get_category_by_slug( $slug );
	return $term ? (int) $term->term_id : 0;
};
$community = $cat( 'community-blogs' );
$news      = $cat( 'news' );
$crime     = $cat( 'crime' );

$users = array();
try {
	WP_CLI::log( '== Setup: temporary test accounts' );
	foreach ( array( 'subscriber', 'contributor', 'author', 'editor', 'editor2' ) as $key ) {
		$login = 'portal_livetest_' . $key;
		$old   = get_user_by( 'login', $login );
		if ( $old ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $old->ID );
		}
		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.com',
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => 'editor2' === $key ? 'editor' : $key,
				'display_name' => 'Live test ' . $key,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		update_user_meta( $id, '_portal_livetest', 1 );
		$users[ $key ] = (int) $id;
	}
	$m  = $users['subscriber'];
	$w  = $users['contributor'];
	$a  = $users['author'];
	$e  = $users['editor'];
	$e2 = $users['editor2'];

	WP_CLI::log( '== Trusted writer (Author)' );
	$pub = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag author story", 'content' => 'Test text.', 'status' => 'publish', 'categories' => array( $community ) ), $a );
	$pid = (int) ( $pub['data']['id'] ?? 0 );
	$check( '1. A trusted writer publishes a community story directly', 201 === $pub['code'] && 'publish' === get_post_status( $pid ), 'code=' . $pub['code'] );
	$an = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag author news", 'content' => 'x', 'status' => 'draft', 'categories' => array( $news ) ), $a );
	$check( '2. A trusted writer cannot post in News (editor-only section)', $an['code'] >= 400, 'code=' . $an['code'] );

	WP_CLI::log( '== Visitor' );
	$vl = $rest( 'GET', '/wp/v2/posts', array(), 0 );
	$vc = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag visitor" ), 0 );
	$vk = $rest( 'POST', '/portal/v1/likes/' . $pid, array(), 0 );
	$check( '3. Visitors can read stories but cannot write or like', 200 === $vl['code'] && in_array( $vc['code'], array( 401, 403 ), true ) && 401 === $vk['code'], "read={$vl['code']} write={$vc['code']} like={$vk['code']}" );

	WP_CLI::log( '== Member (Subscriber)' );
	$mc = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag member" ), $m );
	$mk = $rest( 'POST', '/portal/v1/likes/' . $pid, array(), $m );
	$mr = $rest( 'POST', '/portal/v1/reports', array( 'object_type' => 'post', 'object_id' => $pid, 'reason' => 'spam', 'details' => 'live role test' ), $m );
	$check( '4. Members cannot write stories, but can like and report', in_array( $mc['code'], array( 401, 403 ), true ) && 200 === $mk['code'] && ! empty( $mk['data']['liked'] ) && in_array( $mr['code'], array( 200, 201 ), true ), "write={$mc['code']} like={$mk['code']} report={$mr['code']}" );

	WP_CLI::log( '== Writer (Contributor)' );
	$wp1 = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag writer story one", 'content' => 'Writer text.', 'status' => 'pending', 'categories' => array( $community ) ), $w );
	$wp2 = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag writer story two", 'content' => 'Writer text.', 'status' => 'pending', 'categories' => array( $community ) ), $w );
	$w1  = (int) ( $wp1['data']['id'] ?? 0 );
	$w2  = (int) ( $wp2['data']['id'] ?? 0 );
	$wpub = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag writer publish", 'status' => 'publish', 'categories' => array( $community ) ), $w );
	$check( '5. A writer\'s story goes to Pending review; a writer cannot publish', 'pending' === get_post_status( $w1 ) && 'pending' === get_post_status( $w2 ) && in_array( $wpub['code'], array( 401, 403 ), true ), 'one=' . get_post_status( $w1 ) . ' publish=' . $wpub['code'] );
	$wn = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag writer crime", 'status' => 'pending', 'categories' => array( $crime ) ), $w );
	$check( '6. A writer cannot post in Crime (editor-only section)', $wn['code'] >= 400, 'code=' . $wn['code'] );

	WP_CLI::log( '== Editor: review' );
	wp_set_current_user( $e ); // the editor is the one logged in when pressing Approve / Request changes.
	$short = portal_review_decide( $w1, 'changes', 'no', $e );
	$chg   = portal_review_decide( $w1, 'changes', 'Please add the date and the place.', $e );
	$appr  = portal_review_decide( $w2, 'approve', '', $e );
	$check( '7. Editors request changes (a reason is required) and approve: statuses change correctly', is_wp_error( $short ) && ! is_wp_error( $chg ) && 'portal_changes' === get_post_status( $w1 ) && ! is_wp_error( $appr ) && 'publish' === get_post_status( $w2 ), 'w1=' . get_post_status( $w1 ) . ' w2=' . get_post_status( $w2 ) );

	WP_CLI::log( '== Editor: News and the sensitive-story rule' );
	$en = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag editor news", 'content' => 'News text.', 'status' => 'draft', 'categories' => array( $news ) ), $e );
	$check( '8. Editors can write in News', 201 === $en['code'], 'code=' . $en['code'] );
	$sd  = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag sensitive", 'content' => 'Sensitive text.', 'status' => 'draft', 'categories' => array( $crime ), 'meta' => array( 'portal_sensitive' => true ) ), $e );
	$sid = (int) ( $sd['data']['id'] ?? 0 );
	$sp  = $rest( 'POST', '/wp/v2/posts/' . $sid, array( 'status' => 'publish' ), $e );
	$check( '9. A sensitive story cannot be published without a second editor', $sp['code'] >= 400 && 'publish' !== get_post_status( $sid ), 'code=' . $sp['code'] . ' status=' . get_post_status( $sid ) );
	$self = $rest( 'POST', '/portal/v1/review/' . $sid . '/second-approval', array(), $e );
	$ok2  = $rest( 'POST', '/portal/v1/review/' . $sid . '/second-approval', array(), $e2 );
	$sp2  = $rest( 'POST', '/wp/v2/posts/' . $sid, array( 'status' => 'publish' ), $e );
	$check( '10. The same editor cannot approve their own sensitive story; a different editor can, then it publishes', $self['code'] >= 400 && in_array( $ok2['code'], array( 200, 201 ), true ) && 'publish' === get_post_status( $sid ), "self={$self['code']} second={$ok2['code']} status=" . get_post_status( $sid ) );

	WP_CLI::log( '== Reports, takedown, audit log' );
	$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reps} WHERE id > %d AND object_id = %d", $reports_start, $pid ) ); // phpcs:ignore WordPress.DB
	$td     = portal_take_down( 'post', $pid, 'Live role test takedown', $e );
	$logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE id > %d AND object_id = %d", $audit_start, $pid ) ); // phpcs:ignore WordPress.DB
	$check( '11. The member\'s report is stored; an editor\'s takedown hides the story and is written in the audit log', $stored >= 1 && ! is_wp_error( $td ) && 'portal_takedown' === get_post_status( $pid ) && $logged >= 1, "reports=$stored status=" . get_post_status( $pid ) . " log=$logged" );

	WP_CLI::log( '== Reader news tip' );
	wp_set_current_user( $m );
	$tip = portal_submit_tip( $m, array( 'title' => "$tag tip headline here", 'details' => 'A long enough description of what the test reader saw today.', 'where' => 'Raipur', 'how' => 'saw', 'credit' => 'anonymous', 'consent' => 1 ), array() );
	$tipok = ! is_wp_error( $tip ) && 'private' === get_post_status( $tip );
	$story = $tipok ? portal_tip_write_up( get_post( $tip ), $e ) : 0;
	if ( $story && ! is_wp_error( $story ) ) {
		wp_set_current_user( $e );
		wp_update_post( array( 'ID' => $story, 'post_status' => 'publish' ) );
	}
	$state = $tipok ? get_post_meta( $tip, '_portal_tip_state', true ) : '';
	$check( '12. A member sends a tip (private); an editor writes it up and publishes; the tip shows "published"', $tipok && $story && 'published' === $state, 'tip=' . ( is_wp_error( $tip ) ? $tip->get_error_message() : $tip ) . " state=$state" );

	WP_CLI::log( '== Series' );
	$own = $rest( 'POST', '/wp/v2/posts', array( 'title' => "$tag series part", 'content' => 'Part text.', 'status' => 'publish', 'categories' => array( $community ) ), $a );
	$oid = (int) ( $own['data']['id'] ?? 0 );
	$sr  = portal_create_series( $a, "$tag series" );
	$as  = is_wp_error( $sr ) ? $sr : portal_assign_series( $oid, $sr, $a );
	$bad = is_wp_error( $sr ) ? null : portal_assign_series( $w2, $sr, $w );
	$pos = portal_series_position( $oid );
	$check( '13. A writer makes a series of their own stories; others cannot add to it', ! is_wp_error( $sr ) && true === $as && is_wp_error( $bad ) && $pos && 1 === $pos['number'], is_wp_error( $sr ) ? $sr->get_error_message() : 'ok' );

	WP_CLI::log( '== Public pages' );
	$cm = $rest( 'GET', '/portal/v1/community', array(), 0 );
	$check( '14. The Community page data loads for visitors', 200 === $cm['code'], 'code=' . $cm['code'] );
} finally {
	WP_CLI::log( '== Clean-up' );
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ids = array_values( $users );
	if ( $ids ) {
		// Series made by test accounts.
		foreach ( get_terms( array( 'taxonomy' => 'portal_series', 'hide_empty' => false, 'meta_query' => array( array( 'key' => 'portal_series_owner', 'value' => $ids, 'compare' => 'IN' ) ) ) ) as $term ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_term( $term->term_id, 'portal_series' );
		}
		// Every post, tip and file by test accounts, plus stories written up from their tips.
		$posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'author__in' => $ids, 'numberposts' => -1, 'fields' => 'ids' ) );
		$tips  = get_posts( array( 'post_type' => 'portal_tip', 'post_status' => 'any', 'author__in' => $ids, 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $tips as $t ) {
			$s = (int) get_post_meta( $t, '_portal_tip_story', true );
			if ( $s ) {
				$posts[] = $s;
			}
		}
		$tagged = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 's' => $tag, 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( array_unique( array_merge( $posts, $tips, $tagged ) ) as $p ) {
			wp_delete_post( $p, true );
		}
		foreach ( $ids as $u ) {
			wp_delete_user( $u );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$reps} WHERE id > %d", $reports_start ) );   // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE id > %d", $audit_start ) );    // phpcs:ignore WordPress.DB
	delete_transient( 'portal_trending_writers' );
	do_action( 'litespeed_purge_all' );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", 'portal\_livetest\_%' ) ); // phpcs:ignore WordPress.DB
	$posts_left = count( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 's' => '[TEST] please ignore', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	WP_CLI::log( "Removed test accounts and everything they created (test accounts left: $left, test stories left: $posts_left)." );
	WP_CLI::log( '' );
	WP_CLI::log( "RESULT: $pass passed, $fail failed" );
}
