<?php
/*
Plugin Name: Google Docs HTML Export Fetcher
Description: Fetches Google Docs HTML content using its native export feature and displays it via a shortcode. Use [doc num="1"] to display different docs.
Version: 1.3
Author: Chris White
*/

// Hook for plugin activation to schedule cron event
register_activation_hook(__FILE__, 'gdoc_html_export_fetcher_activate');
function gdoc_html_export_fetcher_activate() {
    if (!wp_next_scheduled('gdoc_html_export_fetcher_cron_job')) {
        wp_schedule_event(time(), 'thirty_minutes', 'gdoc_html_export_fetcher_cron_job');
    }
}

// Hook for plugin deactivation to remove cron event
register_deactivation_hook(__FILE__, 'gdoc_html_export_fetcher_deactivate');
function gdoc_html_export_fetcher_deactivate() {
    wp_clear_scheduled_hook('gdoc_html_export_fetcher_cron_job');
}

// Custom cron interval (30 minutes)
add_filter('cron_schedules', 'gdoc_custom_cron_schedule');
function gdoc_custom_cron_schedule($schedules) {
    $schedules['thirty_minutes'] = array(
        'interval' => 1800, // 30 minutes
        'display' => __('Every 30 Minutes')
    );
    return $schedules;
}

// The cron job to fetch the Google Doc content
add_action('gdoc_html_export_fetcher_cron_job', 'gdoc_fetch_and_store_html_content');
function gdoc_fetch_and_store_html_content() {
    $doc_urls = get_option('gdoc_html_export_urls', array());
    
    foreach ($doc_urls as $key => $url) {
        $new_content = gdoc_fetch_html_content($url);
        
        // Check if the new content was successfully fetched before updating the database
        if ($new_content !== false) {
            update_option("gdoc_html_export_content_{$key}", $new_content);
            update_option("gdoc_html_export_timestamp_{$key}", current_time('mysql')); // Store the timestamp of the fetch
        }
    }
}

// Function to fetch raw HTML content from the provided Google Doc export URL with detailed error handling
function gdoc_fetch_html_content($url) {
    // Validate if the URL is a proper Google Doc URL
    if (!filter_var($url, FILTER_VALIDATE_URL) || strpos($url, 'docs.google.com') === false) {
        return 'Invalid URL: The URL is either not valid or not a Google Doc URL.';
    }
    
    $response = wp_remote_get($url);
    
    // Check if the response has an error or is invalid
    if (is_wp_error($response)) {
        return 'Fetch failed: ' . $response->get_error_message();
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    
    // Only proceed if the response code is 200 (success)
    if ($response_code !== 200) {
        return 'Fetch failed: Received HTTP ' . $response_code . ' response from the server.';
    }

    $body = wp_remote_retrieve_body($response);
    
    if (empty($body)) {
        return 'No content fetched: The document may be empty or inaccessible.';
    }

    // Allow the <style> tag in addition to other allowed HTML tags
    $allowed_tags = wp_kses_allowed_html('post');
    $allowed_tags['style'] = array();  // Allow <style> tags

    return wp_kses($body, $allowed_tags); // Preserve <style> tags
}

// Function to add or update Google Docs URLs in the settings
function gdoc_add_doc_url($doc_url, $num) {
    $doc_urls = get_option('gdoc_html_export_urls', array());
    $doc_urls[$num] = $doc_url;
    update_option('gdoc_html_export_urls', $doc_urls);
}

// Shortcode to display the Google Doc HTML content
add_shortcode('doc', 'gdoc_shortcode_display');
function gdoc_shortcode_display($atts) {
    $atts = shortcode_atts(array(
        'num' => '1',
    ), $atts, 'doc');

    // Retrieve the content and the timestamp from the options table
    $content = get_option("gdoc_html_export_content_{$atts['num']}", 'No content found for this document.');
    $timestamp = get_option("gdoc_html_export_timestamp_{$atts['num']}", 'No timestamp available.');

    // Format the output with the timestamp at the top
    $output = '<div class="gdoc-html-content">';
    $output .= '<p><center><strong>Updated:</strong> ' . esc_html($timestamp) . '<center></p>'; // Display the timestamp
    $output .= $content; // Display the content
    $output .= '</div>';

    return $output;
}

// Admin page to add/update Google Doc URLs
add_action('admin_menu', 'gdoc_html_export_fetcher_menu');
function gdoc_html_export_fetcher_menu() {
    add_menu_page(
        'Google Docs HTML Export Fetcher Settings',
        'Google Docs HTML Export Fetcher',
        'manage_options',
        'gdoc-html-export-fetcher',
        'gdoc_html_export_fetcher_settings_page',
        '',
        100
    );
}

// Admin settings page
function gdoc_html_export_fetcher_settings_page() {
    if (isset($_POST['gdoc_submit'])) {
        $doc_url = sanitize_text_field($_POST['gdoc_url']);
        $doc_num = intval($_POST['gdoc_num']);
        gdoc_add_doc_url($doc_url, $doc_num);
        echo '<div class="updated"><p>Google Doc URL saved!</p></div>';
    }

    if (isset($_POST['gdoc_delete'])) {
        $doc_num = intval($_POST['gdoc_num_delete']);
        $doc_urls = get_option('gdoc_html_export_urls', array());
        unset($doc_urls[$doc_num]);
        update_option('gdoc_html_export_urls', $doc_urls);
        delete_option("gdoc_html_export_content_{$doc_num}");
        echo '<div class="updated"><p>Google Doc URL and content deleted!</p></div>';
    }

    if (isset($_POST['gdoc_fetch_now'])) {
        $doc_urls = get_option('gdoc_html_export_urls', array());
        $success_count = 0;
        $fail_count = 0;
        $error_messages = [];

        // Attempt to fetch new content for each document
        foreach ($doc_urls as $key => $url) {
            $new_content = gdoc_fetch_html_content($url);

            if ($new_content !== false && strpos($new_content, 'Fetch failed') === false) {
                update_option("gdoc_html_export_content_{$key}", $new_content);
                $success_count++;
            } else {
                $fail_count++;
                $error_messages[] = "Doc #{$key}: " . $new_content;
            }

            // Throttle fetches by sleeping for 2 seconds between requests
            //sleep(1);
        }

        // Reset the cron job to start from this moment
        wp_clear_scheduled_hook('gdoc_html_export_fetcher_cron_job');
        wp_schedule_event(time(), 'thirty_minutes', 'gdoc_html_export_fetcher_cron_job');

        // Display a message based on the results
        if ($success_count > 0) {
            echo '<div class="updated"><p>' . $success_count . ' Google Doc(s) successfully fetched and saved!</p></div>';
        }

        if ($fail_count > 0) {
            echo '<div class="error"><p>' . $fail_count . ' Google Doc(s) could not be fetched. Using the previous import for these documents.</p></div>';
            foreach ($error_messages as $error) {
                echo '<div class="error"><p>' . esc_html($error) . '</p></div>';
            }
        }

        // If all failed
        if ($success_count === 0 && $fail_count > 0) {
            echo '<div class="error"><p>All fetch attempts failed. Previous imports are being used.</p></div>';
        }
    }

    $doc_urls = get_option('gdoc_html_export_urls', array());

    ?>
    <div class="wrap">
        <h1>Google Docs HTML Export Fetcher</h1>
        <form method="post">
            <label for="gdoc_num">Doc Number:</label>
            <input type="number" name="gdoc_num" id="gdoc_num" value="1" min="1" required><br>
            <label for="gdoc_url">Google Doc Export URL (export as HTML):</label>
            <input type="text" name="gdoc_url" id="gdoc_url" value="" required><br>
            <input type="submit" name="gdoc_submit" class="button button-primary" value="Save Google Doc URL">
        </form>

        <h2>Delete a Google Doc URL</h2>
        <form method="post">
            <label for="gdoc_num_delete">Doc Number to Delete:</label>
            <input type="number" name="gdoc_num_delete" id="gdoc_num_delete" value="1" min="1" required><br>
            <input type="submit" name="gdoc_delete" class="button button-secondary" value="Delete Google Doc URL">
        </form>

        <h2>Fetch New Data</h2>
        <form method="post">
            <input type="submit" name="gdoc_fetch_now" class="button button-primary" value="Fetch Now">
        </form>
        
        <h2>Stored Google Doc URLs:</h2>
        <ul>
            <?php foreach ($doc_urls as $num => $url): ?>
                <li><strong>Doc #<?php echo $num; ?>:</strong> <?php echo esc_url($url); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}
