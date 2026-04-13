<?php
/**
 * Plugin Name: Product Title Mismatch Scanner
 * Description: Scans ACF "product_title" vs WordPress page title for the "product" custom post type. Lists mismatches and allows batch updating.
 * Version: 2.2.0
 * Author: Lab Res
 *
 * Changelog:
 *   2.2.0 - Paginated mismatch list to 20 per page to prevent site crashes
 *           during batch updates. Processes only the current page of results.
 *
 *   2.1.0 - Fixed double-dot bug in Cu. Ft. normalisation. Regex was matching
 *           already-correct "Cu. Ft." and appending an extra period. Added
 *           negative lookahead to skip text that already has the trailing dot.
 *           Re-run scan to fix any titles updated with "Cu. Ft.." from 2.0.0.
 *
 *   2.0.0 - Rewrote title building to use "product_id product_title" from ACF
 *           fields. Dropped master_model_number and separator-guessing logic.
 *           Added normalisation of cubic foot variations (Cubic Foot, cubic foot,
 *           cu.ft, Cu. Ft) to standard "Cu. Ft." format.
 *
 *   1.0.0 - Initial release. Separator-based title splitting (dash, en-dash,
 *           em-dash, colon).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'ptm_add_admin_page');

function ptm_add_admin_page() {
    add_management_page(
        'Product Title Mismatch',
        'Product Title Mismatch',
        'manage_options',
        'product-title-mismatch',
        'ptm_render_page'
    );
}

/**
 * Normalise cubic-foot variations to "Cu. Ft."
 *
 * Handles: "Cubic Foot", "cubic foot", "cu.ft", "Cu. Ft" (no trailing dot), etc.
 */
function ptm_normalise_cuft($text) {
    // "Cubic Foot" / "cubic foot" → "Cu. Ft."
    $text = preg_replace('/\bcubic\s+foot\b/i', 'Cu. Ft.', $text);

    // Skip anything already exactly "Cu. Ft." — only fix sloppy variants
    // like "cu.ft", "Cu.Ft", "Cu. Ft" (missing trailing dot), etc.
    $text = preg_replace('/\bcu\.?\s*ft\.?(?!\.)(?:\b)?/i', 'Cu. Ft.', $text);

    return $text;
}

/**
 * Build the correct page title from ACF fields.
 * Format: "product_id product_title"
 */
function ptm_build_correct_title($product_id, $product_title) {
    $id   = trim($product_id);
    $desc = trim(ptm_normalise_cuft($product_title));

    if ($id === '' && $desc === '') {
        return '';
    }
    if ($id === '') {
        return $desc;
    }
    if ($desc === '') {
        return $id;
    }

    return $id . ' ' . $desc;
}

/**
 * Gather mismatch data for all products.
 */
function ptm_scan_products() {
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $mismatches = [];
    $matches    = 0;
    $skipped    = 0;

    foreach ($products as $product) {
        $page_title    = trim($product->post_title);
        $product_id    = trim((string)get_field('product_id', $product->ID));
        $product_title = trim((string)get_field('product_title', $product->ID));

        // Skip if both ACF fields are empty — nothing to build a title from.
        if ($product_id === '' && $product_title === '') {
            $skipped++;
            continue;
        }

        $correct_title = ptm_build_correct_title($product_id, $product_title);

        if (mb_strtolower($page_title) === mb_strtolower($correct_title)) {
            $matches++;
        } else {
            $mismatches[] = [
                'ID'            => $product->ID,
                'page_title'    => $page_title,
                'product_id'    => $product_id,
                'product_title' => $product_title,
                'correct_title' => $correct_title,
                'edit_link'     => get_edit_post_link($product->ID, 'raw'),
            ];
        }
    }

    return [
        'mismatches' => $mismatches,
        'matches'    => $matches,
        'skipped'    => $skipped,
        'total'      => count($products),
    ];
}

/**
 * Main admin page renderer.
 */
function ptm_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Handle batch update POST.
    if (
        isset($_POST['ptm_action']) &&
        $_POST['ptm_action'] === 'batch_update' &&
        check_admin_referer('ptm_batch_update', 'ptm_nonce')
    ) {
        ptm_handle_batch_update();
        return;
    }

    $scan    = ptm_scan_products();
    $page    = isset($_GET['ptm_page']) ? max(1, intval($_GET['ptm_page'])) : 1;
    ptm_render_results_page($scan['mismatches'], $scan['matches'], $scan['total'], $scan['skipped'], [], $page);
}

/**
 * Render the results / batch-update form.
 */
function ptm_render_results_page($mismatches, $matches, $total, $skipped, $updated_ids = [], $current_page = 1) {
    $per_page    = 20;
    $total_mm    = count($mismatches);
    $total_pages = max(1, ceil($total_mm / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset      = ($current_page - 1) * $per_page;
    $page_rows   = array_slice($mismatches, $offset, $per_page);
    $base_url    = admin_url('tools.php?page=product-title-mismatch');
    ?>
    <div class="wrap">
        <h1>Product Title Mismatch Scanner</h1>

        <p>
            Compares each <strong>product</strong> page title against
            <code>product_id product_title</code> built from the ACF fields.
        </p>

        <h2>Summary</h2>
        <table class="widefat fixed" style="max-width:450px">
            <tbody>
                <tr><td><strong>Total products</strong></td><td><?php echo esc_html($total); ?></td></tr>
                <tr><td><strong>Matching</strong></td><td><?php echo esc_html($matches); ?></td></tr>
                <tr style="color:#d63638"><td><strong>Mismatched</strong></td><td><?php echo esc_html($total_mm); ?></td></tr>
                <tr><td><strong>Skipped (no ACF data)</strong></td><td><?php echo esc_html($skipped); ?></td></tr>
            </tbody>
        </table>

        <?php if (!empty($updated_ids)): ?>
            <div class="notice notice-success" style="margin-top:15px">
                <p><strong><?php echo count($updated_ids); ?></strong> product title(s) updated successfully.</p>
            </div>
        <?php endif; ?>

        <?php if (empty($mismatches)): ?>
            <div class="notice notice-success" style="margin-top:15px">
                <p>All product titles match. Nothing to update.</p>
            </div>
        <?php else: ?>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav top" style="margin-top:15px">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_mm); ?> mismatches</span>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a class="button" href="<?php echo esc_url($base_url . '&ptm_page=1'); ?>">&laquo; First</a>
                                <a class="button" href="<?php echo esc_url($base_url . '&ptm_page=' . ($current_page - 1)); ?>">&lsaquo; Prev</a>
                            <?php else: ?>
                                <span class="button disabled">&laquo; First</span>
                                <span class="button disabled">&lsaquo; Prev</span>
                            <?php endif; ?>

                            <span class="paging-input">
                                <strong><?php echo esc_html($current_page); ?></strong> of
                                <strong><?php echo esc_html($total_pages); ?></strong>
                            </span>

                            <?php if ($current_page < $total_pages): ?>
                                <a class="button" href="<?php echo esc_url($base_url . '&ptm_page=' . ($current_page + 1)); ?>">Next &rsaquo;</a>
                                <a class="button" href="<?php echo esc_url($base_url . '&ptm_page=' . $total_pages); ?>">Last &raquo;</a>
                            <?php else: ?>
                                <span class="button disabled">Next &rsaquo;</span>
                                <span class="button disabled">Last &raquo;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:10px">
                <?php wp_nonce_field('ptm_batch_update', 'ptm_nonce'); ?>
                <input type="hidden" name="ptm_action" value="batch_update">
                <input type="hidden" name="ptm_page" value="<?php echo esc_attr($current_page); ?>">

                <table class="widefat striped" style="margin-top:10px">
                    <thead>
                        <tr>
                            <th style="width:40px"><input type="checkbox" id="ptm-select-all"></th>
                            <th style="width:50px">ID</th>
                            <th>Current Page Title</th>
                            <th style="width:160px">product_id</th>
                            <th>product_title</th>
                            <th>Correct Title (preview)</th>
                            <th style="width:50px">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($page_rows as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="ptm_ids[]" value="<?php echo esc_attr($row['ID']); ?>"></td>
                                <td><?php echo esc_html($row['ID']); ?></td>
                                <td><?php echo esc_html($row['page_title']); ?></td>
                                <td><code><?php echo esc_html($row['product_id']); ?></code></td>
                                <td><?php echo esc_html($row['product_title']); ?></td>
                                <td style="color:#2271b1;font-weight:600"><?php echo esc_html($row['correct_title']); ?></td>
                                <td><a href="<?php echo esc_url($row['edit_link']); ?>" target="_blank">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="ptm-update-btn">
                        Update Selected Titles
                    </button>
                    <span class="description" style="margin-left:10px">
                        Updates only the checked rows on this page (max 20 at a time).
                    </span>
                </p>
            </form>

            <script>
            (function(){
                var selectAll = document.getElementById('ptm-select-all');
                if (!selectAll) return;
                selectAll.addEventListener('change', function(){
                    var boxes = document.querySelectorAll('input[name="ptm_ids[]"]');
                    for (var i = 0; i < boxes.length; i++) {
                        boxes[i].checked = selectAll.checked;
                    }
                });
                document.getElementById('ptm-update-btn').addEventListener('click', function(e){
                    var checked = document.querySelectorAll('input[name="ptm_ids[]"]:checked');
                    if (checked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one product to update.');
                        return;
                    }
                    if (!confirm('Update ' + checked.length + ' page title(s)? This cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            })();
            </script>

        <?php endif; ?>
    </div>
    <?php
}

/**
 * Handle the batch update.
 */
function ptm_handle_batch_update() {
    $ids = isset($_POST['ptm_ids']) ? array_map('intval', (array)$_POST['ptm_ids']) : [];

    if (empty($ids)) {
        echo '<div class="wrap"><div class="notice notice-warning"><p>No products selected.</p></div></div>';
        return;
    }

    $updated_ids = [];

    foreach ($ids as $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product') {
            continue;
        }

        $product_id    = trim((string)get_field('product_id', $post_id));
        $product_title = trim((string)get_field('product_title', $post_id));

        $correct_title = ptm_build_correct_title($product_id, $product_title);
        if ($correct_title === '') {
            continue;
        }

        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $correct_title,
            'post_name'  => sanitize_title($correct_title),
        ]);

        $updated_ids[] = $post_id;
    }

    // Re-scan after updates.
    $scan = ptm_scan_products();
    $page = isset($_POST['ptm_page']) ? max(1, intval($_POST['ptm_page'])) : 1;

    // If the current page is now beyond the last page, go to page 1.
    $total_pages = max(1, ceil(count($scan['mismatches']) / 20));
    if ($page > $total_pages) {
        $page = 1;
    }

    ptm_render_results_page(
        $scan['mismatches'],
        $scan['matches'],
        $scan['total'],
        $scan['skipped'],
        $updated_ids,
        $page
    );
}
