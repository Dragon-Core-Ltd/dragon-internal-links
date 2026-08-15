/**
 * Dragon Internal Links - Admin JavaScript
 */
(function($) {
    'use strict';

    // Scan All Posts
    $('#dil-scan-all').on('click', function() {
        const $btn = $(this);
        const $status = $('#dil-scan-status');
        const $progress = $('#dil-progress');
        const $progressFill = $('#dil-progress-fill');
        const $progressText = $('#dil-progress-text');

        $btn.prop('disabled', true);
        $status.text(dilAdmin.i18n.scanning);
        $progress.show();

        function scanBatch(offset) {
            $.ajax({
                url: dilAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dragoninternallinks_scan_all',
                    nonce: dilAdmin.nonce,
                    offset: offset
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const percent = Math.round((data.offset / data.total) * 100);

                        $progressFill.css('width', percent + '%');
                        $progressText.text(data.message);

                        if (data.complete) {
                            $btn.prop('disabled', false);
                            $status.text(dilAdmin.i18n.scanComplete);

                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            // Continue with next batch
                            scanBatch(data.offset);
                        }
                    } else {
                        $btn.prop('disabled', false);
                        $status.text(dilAdmin.i18n.error);
                        $progress.hide();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $status.text(dilAdmin.i18n.error);
                    $progress.hide();
                }
            });
        }

        scanBatch(0);
    });

    // Generate Suggestions
    $('#dil-generate-suggestions').on('click', function() {
        const $btn = $(this);
        const $status = $('#dil-scan-status');

        $btn.prop('disabled', true);
        $status.text(dilAdmin.i18n.generating);

        $.ajax({
            url: dilAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoninternallinks_generate_suggestions',
                nonce: dilAdmin.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $status.text(response.data.message);

                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $status.text(dilAdmin.i18n.error);
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.text(dilAdmin.i18n.error);
            }
        });
    });

    // Apply Suggestion
    $('.dil-apply-suggestion').on('click', function() {
        const $btn = $(this);
        const $card = $btn.closest('.dil-suggestion-card');
        const suggestionId = $card.data('id');

        if (!confirm(dilAdmin.i18n.confirm)) {
            return;
        }

        $btn.prop('disabled', true).text('Applying...');

        $.ajax({
            url: dilAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoninternallinks_apply_suggestion',
                nonce: dilAdmin.nonce,
                suggestion_id: suggestionId
            },
            success: function(response) {
                if (response.success) {
                    $card.addClass('dil-applied');

                    setTimeout(function() {
                        $card.slideUp(300, function() {
                            $(this).remove();
                        });
                    }, 500);
                } else {
                    alert(response.data.message || dilAdmin.i18n.error);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Link');
                }
            },
            error: function() {
                alert(dilAdmin.i18n.error);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Link');
            }
        });
    });

    // Dismiss Suggestion
    $('.dil-dismiss-suggestion').on('click', function() {
        const $btn = $(this);
        const $card = $btn.closest('.dil-suggestion-card');
        const suggestionId = $card.data('id');

        $btn.prop('disabled', true);

        $.ajax({
            url: dilAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoninternallinks_dismiss_suggestion',
                nonce: dilAdmin.nonce,
                suggestion_id: suggestionId
            },
            success: function(response) {
                if (response.success) {
                    $card.addClass('dil-dismissed');

                    setTimeout(function() {
                        $card.slideUp(300, function() {
                            $(this).remove();
                        });
                    }, 300);
                } else {
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                $btn.prop('disabled', false);
            }
        });
    });

})(jQuery);
