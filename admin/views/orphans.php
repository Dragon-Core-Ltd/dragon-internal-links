<?php
/**
 * Orphans View
 *
 * @package DragonInternalLinks
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View is included from Admin class methods; variables are function-scoped, not global.

// Template variables are provided by Admin::render_orphans_page().
?>
<div class="wrap dil-orphans">
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

	<p class="dil-description">
		<?php esc_html_e( 'Orphan posts have no internal links pointing to them. Adding internal links helps search engines discover this content and passes link equity.', 'dragon-internal-links' ); ?>
	</p>

	<!-- Orphan Posts Table -->
	<div class="dil-card">
		<h2>
			<?php esc_html_e( 'Posts with Zero Inbound Links', 'dragon-internal-links' ); ?>
			<span class="dil-count">(<?php echo count( $orphans ); ?>)</span>
		</h2>

		<?php if ( empty( $orphans ) ) : ?>
			<p class="dil-success">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Great! All your posts have at least one internal link pointing to them.', 'dragon-internal-links' ); ?>
			</p>
		<?php else : ?>
			<table class="dil-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Type', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Published', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Outbound Links', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'dragon-internal-links' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orphans as $dragoninternallinks_post ) : ?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_post['post_id'] ) ); ?>">
										<?php echo esc_html( $dragoninternallinks_post['post_title'] ); ?>
									</a>
								</strong>
							</td>
							<td><?php echo esc_html( $dragoninternallinks_post['post_type'] ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $dragoninternallinks_post['post_date'] ) ) ); ?></td>
							<td class="dil-center"><?php echo esc_html( $dragoninternallinks_post['outbound_count'] ); ?></td>
							<td>
								<?php
								$dragoninternallinks_score          = (float) $dragoninternallinks_post['orphan_score'];
								$dragoninternallinks_priority_class = $dragoninternallinks_score > 5 ? 'high' : ( $dragoninternallinks_score > 2 ? 'medium' : 'low' );
								?>
								<span class="dil-priority dil-priority-<?php echo esc_attr( $dragoninternallinks_priority_class ); ?>">
									<?php echo esc_html( ucfirst( $dragoninternallinks_priority_class ) ); ?>
								</span>
							</td>
							<td>
								<a href="<?php echo esc_url( get_permalink( $dragoninternallinks_post['post_id'] ) ); ?>" target="_blank" class="button button-small">
									<?php esc_html_e( 'View', 'dragon-internal-links' ); ?>
								</a>
								<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_post['post_id'] ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'dragon-internal-links' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Low Outbound Links -->
	<div class="dil-card" style="margin-top: 20px;">
		<h2>
			<?php esc_html_e( 'Posts with Few Outbound Links', 'dragon-internal-links' ); ?>
			<span class="dil-count">(<?php echo count( $low_outbound ); ?>)</span>
		</h2>

		<p class="dil-description">
			<?php esc_html_e( 'These posts have 2 or fewer outbound internal links. Consider adding more to improve site structure.', 'dragon-internal-links' ); ?>
		</p>

		<?php if ( empty( $low_outbound ) ) : ?>
			<p class="dil-success">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'All posts have good outbound link coverage.', 'dragon-internal-links' ); ?>
			</p>
		<?php else : ?>
			<table class="dil-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Outbound Links', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Inbound Links', 'dragon-internal-links' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'dragon-internal-links' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $low_outbound, 0, 25 ) as $dragoninternallinks_post ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_post['post_id'] ) ); ?>">
									<?php echo esc_html( $dragoninternallinks_post['post_title'] ); ?>
								</a>
							</td>
							<td class="dil-center">
								<span class="dil-badge dil-badge-warning"><?php echo esc_html( $dragoninternallinks_post['outbound_count'] ); ?></span>
							</td>
							<td class="dil-center"><?php echo esc_html( $dragoninternallinks_post['inbound_count'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $dragoninternallinks_post['post_id'] ) ); ?>" class="button button-small button-primary">
									<?php esc_html_e( 'Add Links', 'dragon-internal-links' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
