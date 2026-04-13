<?php
/**
 * Plugin Name: Product Title Mismatch Scanner
 * Description: Scans ACF "product_title" field vs WordPress page title for the "product" custom post type. Lists mismatches and allows batch updating.
 * Version: 1.0.0
 * Author: Lab Res
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
 * Try to split a page title into [model_number, product_name].
 *
 * Supports these common separator patterns (checked in order):
 *   "MODEL123 - Product Name"
 *   "MODEL123 – Product Name"  (en-dash)
 *   "MODEL123 — Product Name"  (em-dash)
 *   "MODEL123: Product Name"
 *
 * If no separator is found the entire title is treated as the model number
 * and the product-name portion is empty.
 */
function ptm_split_title($title) {
    // Order matters – check longer sequences first so " - " isn't consumed
    // before " – ".
    $separators = [' – ', ' — ', ' - ', ': '];

    foreach ($separators as $sep) {
        $pos = mb_strpos($title, $sep);
        if ($pos !== false) {
            $model = mb_substr($title, 0, $pos);
            $name  = mb_substr($title, $pos + mb_strlen($sep));
            return [trim($model), trim($name)];
        }
    }

    // No separator found – whole thing is treated as model number.
    return [trim($title), ''];
}

/**
 * Build the new page title: MODEL_NUMBER + separator + product_title.
 */
function ptm_build_new_title($current_title, $product_title) {
    // Detect which separator the current title uses so we can preserve it.
    $separators = [' – ', ' — ', ' - ', ': '];
    $used_sep   = ' - '; // default

    foreach ($separators as $sep) {
        if (mb_strpos($current_title, $sep) !== false) {
            $used_sep = $sep;
            break;
        }
    }

    list($model, ) = ptm_split_title($current_title);

    // If the model portion is empty (shouldn't happen, but safety),
    // just return the product_title as-is.
    if ($model === '') {
        return $product_title;
    }

    return $model . $used_sep . $product_title;
}

/**
 * Main admin page renderer.
 */
function ptm_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // ── Handle batch update POST ────────────────────────────────────────
    if (
        isset($_POST['ptm_action']) &&
        $_POST['ptm_action'] === 'batch_update' &&
        check_admin_referer('ptm_batch_update', 'ptm_nonce')
    ) {
        ptm_handle_batch_update();
        return; // ptm_handle_batch_update() re-renders with results
    }

    // ── Fetch all products ──────────────────────────────────────────────
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $mismatches = [];
    $matches    = 0;

    foreach ($products as $product) {
        $page_title    = $product->post_title;
        $product_title = get_field('product_title', $product->ID);

        // Normalise for comparison: trim whitespace.
        $page_title_trimmed    = trim($page_title);
        $product_title_trimmed = trim((string)$product_title);

        // Skip if ACF field is empty – nothing to compare.
        if ($product_title_trimmed === '') {
            continue;
        }

        // Extract the "product name" portion that sits after the model number.
        list(, $name_portion) = ptm_split_title($page_title_trimmed);

        // A match means the portion after the model number already equals the
        // ACF product_title.
        if (mb_strtolower($name_portion) === mb_strtolower($product_title_trimmed)) {
            $matches++;
        } else {
            $mismatches[] = [
                'ID'            => $product->ID,
                'page_title'    => $page_title_trimmed,
                'product_title' => $product_title_trimmed,
                'name_portion'  => $name_portion,
                'new_title'     => ptm_build_new_title($page_title_trimmed, $product_title_trimmed),
                'edit_link'     => get_edit_post_link($product->ID, 'raw'),
            ];
        }
    }

    ptm_render_results_page($mismatches, $matches, count($products));
}

/**
 * Render the results / batch-update form.
 */
function ptm_render_results_page($mismatches, $matches, $total, $updated_ids = []) {
    ?>
    <div class="wrap">
        <h1>Product Title Mismatch Scanner</h1>

        <p>
            Scans the ACF field <code>product_title</code> against the WordPress
            page title for every <strong>product</strong> post.
            When updating, the <code>product_title</code> replaces the text
            <em>after</em> the model number in the page title.
        </p>

        <h2>Summary</h2>
        <table class="widefat fixed" style="max-width:400px">
            <tbody>
                <tr><td><strong>Total products scanned</strong></td><td><?php echo esc_html($total); ?></td></tr>
                <tr><td><strong>Matching</strong></td><td><?php echo esc_html($matches); ?></td></tr>
                <tr style="color:#d63638"><td><strong>Mismatched</strong></td><td><?php echo esc_html(count($mismatches)); ?></td></tr>
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

            <form method="post" style="margin-top:20px">
                <?php wp_nonce_field('ptm_batch_update', 'ptm_nonce'); ?>
                <input type="hidden" name="ptm_action" value="batch_update">

                <table class="widefat striped fixed" style="margin-top:10px">
                    <thead>
                        <tr>
                            <th style="width:40px"><input type="checkbox" id="ptm-select-all"></th>
                            <th style="width:60px">ID</th>
                            <th>Current Page Title</th>
                            <th>ACF product_title</th>
                            <th>New Title (preview)</th>
                            <th style="width:60px">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mismatches as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="ptm_ids[]" value="<?php echo esc_attr($row['ID']); ?>"></td>
                                <td><?php echo esc_html($row['ID']); ?></td>
                                <td><?php echo esc_html($row['page_title']); ?></td>
                                <td><?php echo esc_html($row['product_title']); ?></td>
                                <td style="color:#2271b1;font-weight:600"><?php echo esc_html($row['new_title']); ?></td>
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
                        Copies <code>product_title</code> into the page title after the model number.
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

        $product_title = trim((string)get_field('product_title', $post_id));
        if ($product_title === '') {
            continue;
        }

        $new_title = ptm_build_new_title($post->post_title, $product_title);

        // Also build a matching slug.
        $new_slug = sanitize_title($new_title);

        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $new_title,
            'post_name'  => $new_slug,
        ]);

        $updated_ids[] = $post_id;
    }

    // Re-scan after updates so the table reflects the new state.
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $mismatches = [];
    $matches    = 0;

    foreach ($products as $product) {
        $page_title    = trim($product->post_title);
        $product_title = trim((string)get_field('product_title', $product->ID));

        if ($product_title === '') {
            continue;
        }

        list(, $name_portion) = ptm_split_title($page_title);

        if (mb_strtolower($name_portion) === mb_strtolower($product_title)) {
            $matches++;
        } else {
            $mismatches[] = [
                'ID'            => $product->ID,
                'page_title'    => $page_title,
                'product_title' => $product_title,
                'name_portion'  => $name_portion,
                'new_title'     => ptm_build_new_title($page_title, $product_title),
                'edit_link'     => get_edit_post_link($product->ID, 'raw'),
            ];
        }
    }

    ptm_render_results_page($mismatches, $matches, count($products), $updated_ids);
}
