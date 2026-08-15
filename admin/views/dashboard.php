<?php
/**
 * Dashboard View
 *
 * @package DragonInternalLinks
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View is included from Admin class methods; variables are function-scoped, not global.

// Template variables are provided by Admin::render_dashboard_page().
?>
<div class="wrap dil-dashboard">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Dragon Internal Links', 'dragon-internal-links' ); ?>
	</h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links' ) ); ?>" class="nav-tab <?php echo 'dashboard' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Dashboard', 'dragon-internal-links' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links&tab=orphans' ) ); ?>" class="nav-tab <?php echo 'orphans' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Orphan Posts', 'dragon-internal-links' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links&tab=suggestions' ) ); ?>" class="nav-tab <?php echo 'suggestions' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Suggestions', 'dragon-internal-links' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Settings', 'dragon-internal-links' ); ?>
		</a>
	</nav>

	<!-- Stats Cards -->
	<div class="dil-stats-grid">
		<div class="dil-stat-card">
			<div class="dil-stat-number"><?php echo esc_html( number_format( $summary['total_links'] ) ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Total Internal Links', 'dragon-internal-links' ); ?></div>
		</div>

		<div class="dil-stat-card">
			<div class="dil-stat-number"><?php echo esc_html( number_format( $summary['total_posts_scanned'] ) ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Posts Scanned', 'dragon-internal-links' ); ?></div>
		</div>

		<div class="dil-stat-card <?php echo $summary['orphan_posts'] > 0 ? 'dil-warning' : ''; ?>">
			<div class="dil-stat-number"><?php echo esc_html( number_format( $summary['orphan_posts'] ) ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Orphan Posts', 'dragon-internal-links' ); ?></div>
			<?php if ( $summary['orphan_posts'] > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links&tab=orphans' ) ); ?>" class="dil-stat-link">
					<?php esc_html_e( 'View All', 'dragon-internal-links' ); ?> →
				</a>
			<?php endif; ?>
		</div>

		<div class="dil-stat-card <?php echo $summary['broken_links'] > 0 ? 'dil-danger' : ''; ?>">
			<div class="dil-stat-number"><?php echo esc_html( number_format( $summary['broken_links'] ) ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Broken Links', 'dragon-internal-links' ); ?></div>
		</div>

		<div class="dil-stat-card dil-highlight">
			<div class="dil-stat-number"><?php echo esc_html( number_format( $summary['pending_suggestions'] ) ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Link Suggestions', 'dragon-internal-links' ); ?></div>
			<?php if ( $summary['pending_suggestions'] > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-internal-links&tab=suggestions' ) ); ?>" class="dil-stat-link">
					<?php esc_html_e( 'Review', 'dragon-internal-links' ); ?> →
				</a>
			<?php endif; ?>
		</div>

		<div class="dil-stat-card">
			<div class="dil-stat-number"><?php echo esc_html( $summary['avg_inbound'] ); ?></div>
			<div class="dil-stat-label"><?php esc_html_e( 'Avg Inbound Links', 'dragon-internal-links' ); ?></div>
		</div>
	</div>

	<!-- Actions -->
	<div class="dil-actions">
		<button type="button" id="dil-scan-all" class="button button-primary">
			<span class="dashicons dashicons-update"></span>
			<?php esc_html_e( 'Scan All Posts', 'dragon-internal-links' ); ?>
		</button>

		<button type="button" id="dil-generate-suggestions" class="button">
			<span class="dashicons dashicons-lightbulb"></span>
			<?php esc_html_e( 'Generate Suggestions', 'dragon-internal-links' ); ?>
		</button>

		<span class="dil-scan-status" id="dil-scan-status"></span>
	</div>

	<div class="dil-progress" id="dil-progress" style="display: none;">
		<div class="dil-progress-bar">
			<div class="dil-progress-fill" id="dil-progress-fill"></div>
		</div>
		<span class="dil-progress-text" id="dil-progress-text"></span>
	</div>

	<!-- Last Scan Info -->
	<?php if ( $last_scan ) : ?>
		<p class="dil-last-scan">
			<?php
			printf(
				/* translators: %s: date and time of the last scan. */
				esc_html__( 'Last scan: %s', 'dragon-internal-links' ),
				esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) )
			);
			?>
		</p>
	<?php endif; ?>

	<div class="dil-columns">
		<!-- Top Linked Posts -->
		<div class="dil-column">
			<div class="dil-card">
				<h2><?php esc_html_e( 'Most Linked Posts', 'dragon-internal-links' ); ?></h2>

				<?php if ( empty( $top_linked ) ) : ?>
					<p class="dil-empty"><?php esc_html_e( 'No data yet. Run a scan to get started.', 'dragon-internal-links' ); ?></p>
				<?php else : ?>
					<table class="dil-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post', 'dragon-internal-links' ); ?></th>
								<th><?php esc_html_e( 'Inbound', 'dragon-internal-links' ); ?></th>
								<th><?php esc_html_e( 'Outbound', 'dragon-internal-links' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $top_linked as $dragoninternallinks_post ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_post['post_id'] ) ); ?>">
											<?php echo esc_html( $dragoninternallinks_post['post_title'] ); ?>
										</a>
									</td>
									<td class="dil-center"><?php echo esc_html( $dragoninternallinks_post['inbound_count'] ); ?></td>
									<td class="dil-center"><?php echo esc_html( $dragoninternallinks_post['outbound_count'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Broken Links -->
		<div class="dil-column">
			<div class="dil-card <?php echo ! empty( $broken_links ) ? 'dil-card-danger' : ''; ?>">
				<h2><?php esc_html_e( 'Broken Internal Links', 'dragon-internal-links' ); ?></h2>

				<?php if ( empty( $broken_links ) ) : ?>
					<p class="dil-success">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'No broken links found!', 'dragon-internal-links' ); ?>
					</p>
				<?php else : ?>
					<table class="dil-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'dragon-internal-links' ); ?></th>
								<th><?php esc_html_e( 'Broken Link', 'dragon-internal-links' ); ?></th>
								<th><?php esc_html_e( 'Status', 'dragon-internal-links' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $broken_links, 0, 10 ) as $dragoninternallinks_link ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_link['source_post_id'] ) ); ?>">
											<?php echo esc_html( $dragoninternallinks_link['source_title'] ); ?>
										</a>
									</td>
									<td>
										<code><?php echo esc_html( $dragoninternallinks_link['anchor_text'] ? $dragoninternallinks_link['anchor_text'] : $dragoninternallinks_link['link_url'] ); ?></code>
									</td>
									<td>
										<span class="dil-status-badge dil-status-broken">
											<?php echo esc_html( $dragoninternallinks_link['target_status'] ?? 'deleted' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( count( $broken_links ) > 10 ) : ?>
						<p class="dil-more">
							<?php
							printf(
								/* translators: %d: number of additional broken links not shown. */
								esc_html__( '...and %d more', 'dragon-internal-links' ),
								(int) ( count( $broken_links ) - 10 )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
