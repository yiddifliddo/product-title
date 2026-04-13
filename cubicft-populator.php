<?php
/**
 * Plugin Name: Cu. Ft. Populator
 * Description: Extracts the number before "Cu. Ft." in product_title and writes it to the ACF "cubicft" field for the "product" custom post type.
 * Version: 1.0.0
 * Author: Lab Res
 *
 * Changelog:
 *   1.0.0 - Initial release. Scans product_title for the number before
 *           "Cu. Ft." / "Cubic Foot" and populates the cubicft ACF field.
 *           Paginated at 20 per page. Skips products without Cu. Ft. in title.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cuft_add_admin_page');

function cuft_add_admin_page() {
    add_management_page(
        'Cu. Ft. Populator',
        'Cu. Ft. Populator',
        'manage_options',
        'cubicft-populator',
        'cuft_render_page'
    );
}

/**
 * Extract the number before "Cu. Ft." / "Cubic Foot" from a string.
 * Returns the number as a string, or empty string if not found.
 */
function cuft_extract_number($text) {
    if (preg_match('/([\d.]+)\s*(?:Cu\.?\s*Ft\.?|Cubic\s+Foot)/i', $text, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Scan all products and find those missing a cubicft value.
 */
function cuft_scan_products() {
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $needs_update = [];
    $already_set  = 0;
    $skipped      = 0;

    foreach ($products as $product) {
        $product_title = trim((string)get_field('product_title', $product->ID));
        $current_cuft  = trim((string)get_field('cubicft', $product->ID));

        $extracted = cuft_extract_number($product_title);

        // Skip products with no Cu. Ft. in their title.
        if ($extracted === '') {
            $skipped++;
            continue;
        }

        // Already set and correct.
        if ($current_cuft === $extracted) {
            $already_set++;
            continue;
        }

        $needs_update[] = [
            'ID'            => $product->ID,
            'page_title'    => trim($product->post_title),
            'product_title' => $product_title,
            'current_cuft'  => $current_cuft,
            'extracted'     => $extracted,
            'edit_link'     => get_edit_post_link($product->ID, 'raw'),
        ];
    }

    return [
        'needs_update' => $needs_update,
        'already_set'  => $already_set,
        'skipped'      => $skipped,
        'total'        => count($products),
    ];
}

/**
 * Main page renderer.
 */
function cuft_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    if (
        isset($_POST['cuft_action']) &&
        $_POST['cuft_action'] === 'batch_update' &&
        check_admin_referer('cuft_batch_update', 'cuft_nonce')
    ) {
        cuft_handle_batch_update();
        return;
    }

    $scan = cuft_scan_products();
    $page = isset($_GET['cuft_page']) ? max(1, intval($_GET['cuft_page'])) : 1;
    cuft_render_results_page($scan['needs_update'], $scan['already_set'], $scan['total'], $scan['skipped'], [], $page);
}

/**
 * Render the results / batch-update form.
 */
function cuft_render_results_page($needs_update, $already_set, $total, $skipped, $updated_ids = [], $current_page = 1) {
    $per_page     = 20;
    $total_nu     = count($needs_update);
    $total_pages  = max(1, ceil($total_nu / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset       = ($current_page - 1) * $per_page;
    $page_rows    = array_slice($needs_update, $offset, $per_page);
    $base_url     = admin_url('tools.php?page=cubicft-populator');
    ?>
    <div class="wrap">
        <h1>Cu. Ft. Populator</h1>

        <p>
            Extracts the number before <code>Cu. Ft.</code> in each product's
            <code>product_title</code> and writes it to the ACF
            <code>cubicft</code> field. Products without "Cu. Ft." in the
            title are skipped.
        </p>

        <h2>Summary</h2>
        <table class="widefat fixed" style="max-width:450px">
            <tbody>
                <tr><td><strong>Total products</strong></td><td><?php echo esc_html($total); ?></td></tr>
                <tr><td><strong>Already set correctly</strong></td><td><?php echo esc_html($already_set); ?></td></tr>
                <tr style="color:#d63638"><td><strong>Needs populating</strong></td><td><?php echo esc_html($total_nu); ?></td></tr>
                <tr><td><strong>Skipped (no Cu. Ft. in title)</strong></td><td><?php echo esc_html($skipped); ?></td></tr>
            </tbody>
        </table>

        <?php if (!empty($updated_ids)): ?>
            <div class="notice notice-success" style="margin-top:15px">
                <p><strong><?php echo count($updated_ids); ?></strong> cubicft field(s) updated successfully.</p>
            </div>
        <?php endif; ?>

        <?php if (empty($needs_update)): ?>
            <div class="notice notice-success" style="margin-top:15px">
                <p>All cubicft fields are populated. Nothing to update.</p>
            </div>
        <?php else: ?>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav top" style="margin-top:15px">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_nu); ?> to populate</span>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a class="button" href="<?php echo esc_url($base_url . '&cuft_page=1'); ?>">&laquo; First</a>
                                <a class="button" href="<?php echo esc_url($base_url . '&cuft_page=' . ($current_page - 1)); ?>">&lsaquo; Prev</a>
                            <?php else: ?>
                                <span class="button disabled">&laquo; First</span>
                                <span class="button disabled">&lsaquo; Prev</span>
                            <?php endif; ?>

                            <span class="paging-input">
                                <strong><?php echo esc_html($current_page); ?></strong> of
                                <strong><?php echo esc_html($total_pages); ?></strong>
                            </span>

                            <?php if ($current_page < $total_pages): ?>
                                <a class="button" href="<?php echo esc_url($base_url . '&cuft_page=' . ($current_page + 1)); ?>">Next &rsaquo;</a>
                                <a class="button" href="<?php echo esc_url($base_url . '&cuft_page=' . $total_pages); ?>">Last &raquo;</a>
                            <?php else: ?>
                                <span class="button disabled">Next &rsaquo;</span>
                                <span class="button disabled">Last &raquo;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:10px">
                <?php wp_nonce_field('cuft_batch_update', 'cuft_nonce'); ?>
                <input type="hidden" name="cuft_action" value="batch_update">
                <input type="hidden" name="cuft_page" value="<?php echo esc_attr($current_page); ?>">

                <table class="widefat striped" style="margin-top:10px">
                    <thead>
                        <tr>
                            <th style="width:40px"><input type="checkbox" id="cuft-select-all"></th>
                            <th style="width:50px">ID</th>
                            <th>Page Title</th>
                            <th>product_title</th>
                            <th style="width:100px">Current cubicft</th>
                            <th style="width:100px">Extracted</th>
                            <th style="width:50px">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($page_rows as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="cuft_ids[]" value="<?php echo esc_attr($row['ID']); ?>"></td>
                                <td><?php echo esc_html($row['ID']); ?></td>
                                <td><?php echo esc_html($row['page_title']); ?></td>
                                <td><?php echo esc_html($row['product_title']); ?></td>
                                <td><?php echo $row['current_cuft'] !== '' ? esc_html($row['current_cuft']) : '<em>empty</em>'; ?></td>
                                <td style="color:#2271b1;font-weight:600"><?php echo esc_html($row['extracted']); ?></td>
                                <td><a href="<?php echo esc_url($row['edit_link']); ?>" target="_blank">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="cuft-update-btn">
                        Populate Selected
                    </button>
                    <span class="description" style="margin-left:10px">
                        Writes the extracted number to the <code>cubicft</code> ACF field (max 20 at a time).
                    </span>
                </p>
            </form>

            <script>
            (function(){
                var selectAll = document.getElementById('cuft-select-all');
                if (!selectAll) return;
                selectAll.addEventListener('change', function(){
                    var boxes = document.querySelectorAll('input[name="cuft_ids[]"]');
                    for (var i = 0; i < boxes.length; i++) {
                        boxes[i].checked = selectAll.checked;
                    }
                });
                document.getElementById('cuft-update-btn').addEventListener('click', function(e){
                    var checked = document.querySelectorAll('input[name="cuft_ids[]"]:checked');
                    if (checked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one product to update.');
                        return;
                    }
                    if (!confirm('Populate cubicft for ' + checked.length + ' product(s)?')) {
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
function cuft_handle_batch_update() {
    $ids = isset($_POST['cuft_ids']) ? array_map('intval', (array)$_POST['cuft_ids']) : [];

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

        $product_title = trim((string)get_field('product_title', $post_id));
        $extracted     = cuft_extract_number($product_title);

        if ($extracted === '') {
            continue;
        }

        update_field('cubicft', $extracted, $post_id);
        $updated_ids[] = $post_id;
    }

    // Re-scan after updates.
    $scan = cuft_scan_products();
    $page = isset($_POST['cuft_page']) ? max(1, intval($_POST['cuft_page'])) : 1;

    $total_pages = max(1, ceil(count($scan['needs_update']) / 20));
    if ($page > $total_pages) {
        $page = 1;
    }

    cuft_render_results_page(
        $scan['needs_update'],
        $scan['already_set'],
        $scan['total'],
        $scan['skipped'],
        $updated_ids,
        $page
    );
}
