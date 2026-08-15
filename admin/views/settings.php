<?php
/**
 * Settings View
 *
 * @package DragonInternalLinks
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View is included from Admin class methods; variables are function-scoped, not global.

// Template variables are provided by Admin::render_settings_page().
?>
<div class="wrap dil-settings">
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

	<?php settings_errors( 'dragoninternallinks_settings' ); ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'dragoninternallinks_save_settings', 'dragoninternallinks_settings_nonce' ); ?>

		<div class="dil-settings-section">
			<h2><?php esc_html_e( 'Scanning Options', 'dragon-internal-links' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types to Scan', 'dragon-internal-links' ); ?></th>
					<td>
						<?php
						$dragoninternallinks_post_types = get_post_types( array( 'public' => true ), 'objects' );
						$dragoninternallinks_selected   = (array) $settings['post_types'];

						foreach ( $dragoninternallinks_post_types as $dragoninternallinks_post_type ) :
							if ( 'attachment' === $dragoninternallinks_post_type->name ) {
								continue;
							}
							?>
							<label>
								<input type="checkbox"
										name="dragoninternallinks_post_types[]"
										value="<?php echo esc_attr( $dragoninternallinks_post_type->name ); ?>"
										<?php checked( in_array( $dragoninternallinks_post_type->name, $dragoninternallinks_selected, true ) ); ?>>
								<?php echo esc_html( $dragoninternallinks_post_type->label ); ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dragoninternallinks_auto_scan"><?php esc_html_e( 'Auto-scan on Save', 'dragon-internal-links' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox"
									id="dragoninternallinks_auto_scan"
									name="dragoninternallinks_auto_scan"
									value="1"
									<?php checked( $settings['auto_scan'] ); ?>>
							<?php esc_html_e( 'Automatically scan posts when they are saved', 'dragon-internal-links' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dragoninternallinks_scan_frequency"><?php esc_html_e( 'Full Scan Frequency', 'dragon-internal-links' ); ?></label>
					</th>
					<td>
						<select id="dragoninternallinks_scan_frequency" name="dragoninternallinks_scan_frequency">
							<option value="daily" <?php selected( $settings['scan_frequency'], 'daily' ); ?>>
								<?php esc_html_e( 'Daily', 'dragon-internal-links' ); ?>
							</option>
							<option value="weekly" <?php selected( $settings['scan_frequency'], 'weekly' ); ?>>
								<?php esc_html_e( 'Weekly', 'dragon-internal-links' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'How often to run a full site scan in the background.', 'dragon-internal-links' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="dil-settings-section">
			<h2><?php esc_html_e( 'Suggestion Options', 'dragon-internal-links' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="dragoninternallinks_min_word_count"><?php esc_html_e( 'Minimum Keyword Words', 'dragon-internal-links' ); ?></label>
					</th>
					<td>
						<input type="number"
								id="dragoninternallinks_min_word_count"
								name="dragoninternallinks_min_word_count"
								value="<?php echo esc_attr( $settings['min_word_count'] ); ?>"
								min="1"
								max="10"
								class="small-text">
						<p class="description">
							<?php esc_html_e( 'Minimum number of words in a keyword phrase for suggestions. Higher = more specific matches.', 'dragon-internal-links' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Exclude Categories', 'dragon-internal-links' ); ?></th>
					<td>
						<?php
						$dragoninternallinks_categories = get_categories( array( 'hide_empty' => false ) );
						$dragoninternallinks_excluded   = (array) $settings['exclude_categories'];

						foreach ( $dragoninternallinks_categories as $dragoninternallinks_category ) :
							?>
							<label>
								<input type="checkbox"
										name="dragoninternallinks_exclude_categories[]"
										value="<?php echo esc_attr( $dragoninternallinks_category->term_id ); ?>"
										<?php checked( in_array( $dragoninternallinks_category->term_id, $dragoninternallinks_excluded, true ) ); ?>>
								<?php echo esc_html( $dragoninternallinks_category->name ); ?>
							</label><br>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'Posts in these categories will be excluded from scanning.', 'dragon-internal-links' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'dragon-internal-links' ) ); ?>
	</form>
</div>
