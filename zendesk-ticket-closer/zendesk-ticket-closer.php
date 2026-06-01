<?php
/*
Plugin Name: Zendesk Ticket Closer
Description: Closes Zendesk tickets directly using the Zendesk API with bearer token authentication
Version: 3.0
*/

// Ensure this file is being run within WordPress
if (!defined('ABSPATH')) {
    exit;
}

// Custom logging function
function ztc_custom_log($message) {
    $log_file = plugin_dir_path(__FILE__) . 'zendesk_ticket_closer.log';
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
    return file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Function to close a Zendesk ticket
function ztc_close_zendesk_ticket($ticket_id, $user_id) {
    ztc_custom_log("Starting ticket closure process for ticket ID: " . $ticket_id . " and user ID: " . $user_id);

    if (!defined('ZENDESK_BEARER_TOKEN')) {
        ztc_custom_log("Zendesk bearer token is not defined");
        return false;
    }

    $subdomain = defined('ZENDESK_SUBDOMAIN') ? ZENDESK_SUBDOMAIN : 'your-subdomain';
    $url = "https://{$subdomain}.zendesk.com/api/v2/tickets/{$ticket_id}.json";

    $data = array(
        'ticket' => array(
            'status' => 'closed',
            'comment' => array(
                'body' => 'Ticket closed via customer request.',
                'public' => false
            )
        )
    );

    $args = array(
        'method'  => 'PUT',
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . ZENDESK_BEARER_TOKEN
        ),
        'body'    => json_encode($data),
        'timeout' => 30
    );

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        ztc_custom_log("WordPress error: " . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    ztc_custom_log("Response code: " . $response_code);

    if ($response_code === 200) {
        ztc_custom_log("Successfully closed ticket: " . $ticket_id);
        return true;
    } else {
        ztc_custom_log("Failed to close ticket: " . $ticket_id . ". Response code: " . $response_code);
        return false;
    }
}

// Handle the close ticket request
function ztc_handle_close_ticket() {
    if (!isset($_GET['pid']) || !isset($_GET['fid'])) {
        return;
    }

    $ticket_id = intval($_GET['pid']);
    $user_id   = intval($_GET['fid']);

    ztc_custom_log("Received request to close ticket: " . $ticket_id . " for user: " . $user_id);
    ztc_close_zendesk_ticket($ticket_id, $user_id);
}

add_action('template_redirect', function() {
    if (is_page('close-ticket') && isset($_GET['pid']) && isset($_GET['fid'])) {
        ztc_handle_close_ticket();
    }
});

add_filter('theme_page_templates', function($templates) {
    $templates['page-templates/close-ticket.php'] = 'Close Ticket';
    return $templates;
});

add_filter('template_include', function($template) {
    if (is_page_template('page-templates/close-ticket.php')) {
        $new_template = plugin_dir_path(__FILE__) . 'page-templates/close-ticket.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    return $template;
});
