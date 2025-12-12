<?php
/**
 * Suggestions View
 *
 * @package DragonInternalLinks
 */

defined( 'ABSPATH' ) || exit;

// Variables: $suggestions, $current_tab
?>
<div class="wrap dil-suggestions">
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
        <?php esc_html_e( 'These are opportunities to add internal links based on keyword matches in your content. Click "Apply" to automatically add the link, or "Dismiss" to hide the suggestion.', 'dragon-internal-links' ); ?>
    </p>

    <div class="dil-actions" style="margin-bottom: 20px;">
        <button type="button" id="dil-generate-suggestions" class="button button-primary">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( 'Refresh Suggestions', 'dragon-internal-links' ); ?>
        </button>
        <span class="dil-scan-status" id="dil-scan-status"></span>
    </div>

    <?php if ( empty( $suggestions ) ) : ?>
        <div class="dil-card">
            <p class="dil-empty">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e( 'No suggestions available. Click "Refresh Suggestions" to generate new ones, or scan your posts first.', 'dragon-internal-links' ); ?>
            </p>
        </div>
    <?php else : ?>
        <div class="dil-suggestions-list">
            <?php foreach ( $suggestions as $suggestion ) : ?>
                <div class="dil-suggestion-card" data-id="<?php echo esc_attr( $suggestion['id'] ); ?>">
                    <div class="dil-suggestion-header">
                        <div class="dil-suggestion-posts">
                            <span class="dil-from">
                                <strong><?php esc_html_e( 'From:', 'dragon-internal-links' ); ?></strong>
                                <a href="<?php echo esc_url( get_edit_post_link( $suggestion['source_post_id'] ) ); ?>" target="_blank">
                                    <?php echo esc_html( $suggestion['source_title'] ); ?>
                                </a>
                            </span>
                            <span class="dil-arrow">→</span>
                            <span class="dil-to">
                                <strong><?php esc_html_e( 'Link to:', 'dragon-internal-links' ); ?></strong>
                                <a href="<?php echo esc_url( get_permalink( $suggestion['target_post_id'] ) ); ?>" target="_blank">
                                    <?php echo esc_html( $suggestion['target_title'] ); ?>
                                </a>
                            </span>
                        </div>
                        <div class="dil-suggestion-relevance">
                            <span class="dil-relevance-score" title="<?php esc_attr_e( 'Relevance Score', 'dragon-internal-links' ); ?>">
                                <?php echo esc_html( number_format( $suggestion['relevance_score'], 1 ) ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="dil-suggestion-body">
                        <div class="dil-keyword">
                            <strong><?php esc_html_e( 'Keyword:', 'dragon-internal-links' ); ?></strong>
                            <mark><?php echo esc_html( $suggestion['keyword'] ); ?></mark>
                        </div>

                        <div class="dil-context">
                            <strong><?php esc_html_e( 'Context:', 'dragon-internal-links' ); ?></strong>
                            <blockquote>
                                <?php
                                $context = $suggestion['context'];
                                $keyword = $suggestion['keyword'];
                                // Highlight keyword in context
                                $highlighted = preg_replace(
                                    '/(' . preg_quote( $keyword, '/' ) . ')/iu',
                                    '<mark>$1</mark>',
                                    esc_html( $context )
                                );
                                echo wp_kses( $highlighted, [ 'mark' => [] ] );
                                ?>
                            </blockquote>
                        </div>
                    </div>

                    <div class="dil-suggestion-actions">
                        <button type="button" class="button button-primary dil-apply-suggestion">
                            <span class="dashicons dashicons-yes"></span>
                            <?php esc_html_e( 'Apply Link', 'dragon-internal-links' ); ?>
                        </button>
                        <button type="button" class="button dil-dismiss-suggestion">
                            <span class="dashicons dashicons-no-alt"></span>
                            <?php esc_html_e( 'Dismiss', 'dragon-internal-links' ); ?>
                        </button>
                        <a href="<?php echo esc_url( get_edit_post_link( $suggestion['source_post_id'] ) ); ?>" class="button" target="_blank">
                            <?php esc_html_e( 'Edit Post', 'dragon-internal-links' ); ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
