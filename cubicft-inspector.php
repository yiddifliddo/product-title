<?php
/**
 * Plugin Name: CubicFt Field Inspector
 * Description: Shows all products that have a value in the ACF "cubicft" field so you can see what it was used for.
 * Version: 1.0.0
 * Author: Lab Res
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cfi_add_admin_page');

function cfi_add_admin_page() {
    add_management_page(
        'CubicFt Inspector',
        'CubicFt Inspector',
        'manage_options',
        'cubicft-inspector',
        'cfi_render_page'
    );
}

function cfi_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb;

    // Get all products with a non-empty cubicft value.
    $results = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, pm.meta_value AS cubicft_value
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = 'cubicft'
        AND pm.meta_value != ''
        AND p.post_type = 'product'
        ORDER BY p.post_title ASC
    ");

    // Get total product count for context.
    $total_products = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status != 'trash'
    ");

    // Gather unique values to spot patterns.
    $value_counts = [];
    foreach ($results as $r) {
        $val = $r->cubicft_value;
        $value_counts[$val] = isset($value_counts[$val]) ? $value_counts[$val] + 1 : 1;
    }
    arsort($value_counts);

    ?>
    <div class="wrap">
        <h1>CubicFt Field Inspector</h1>

        <p>Shows every <strong>product</strong> that has a value stored in the <code>cubicft</code> ACF field.</p>

        <h2>Summary</h2>
        <table class="widefat fixed" style="max-width:450px">
            <tbody>
                <tr><td><strong>Total products</strong></td><td><?php echo esc_html($total_products); ?></td></tr>
                <tr><td><strong>Products with cubicft set</strong></td><td><?php echo esc_html(count($results)); ?></td></tr>
                <tr><td><strong>Unique values</strong></td><td><?php echo esc_html(count($value_counts)); ?></td></tr>
            </tbody>
        </table>

        <?php if (!empty($value_counts)): ?>
            <h2>Value Distribution</h2>
            <table class="widefat striped" style="max-width:350px">
                <thead>
                    <tr>
                        <th>Value</th>
                        <th style="width:80px">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($value_counts as $val => $count): ?>
                        <tr>
                            <td><code><?php echo esc_html($val); ?></code></td>
                            <td><?php echo esc_html($count); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (empty($results)): ?>
            <div class="notice notice-info" style="margin-top:15px">
                <p>No products have a value in the <code>cubicft</code> field. It's empty across the board.</p>
            </div>
        <?php else: ?>
            <h2>All Products with cubicft</h2>
            <table class="widefat striped" style="margin-top:10px">
                <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th>Page Title</th>
                        <th style="width:80px">Status</th>
                        <th style="width:120px">cubicft value</th>
                        <th style="width:50px">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r->ID); ?></td>
                            <td><?php echo esc_html($r->post_title); ?></td>
                            <td><?php echo esc_html($r->post_status); ?></td>
                            <td><code><?php echo esc_html($r->cubicft_value); ?></code></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($r->ID, 'raw')); ?>" target="_blank">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
