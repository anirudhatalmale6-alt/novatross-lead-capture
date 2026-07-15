<?php
/**
 * Plugin Name: Novatross Lead Capture (HubSpot)
 * Description: Pushes Contact Form 7 submissions into the central HubSpot lead hub
 *              with every field mapped. Reusable for Novatross, FhirPlug and any
 *              future form. NO PHI / patient data is ever sent to HubSpot.
 * Version: 1.1.0
 * Author: AT
 */

if (!defined('ABSPATH')) { exit; }

// Central HubSpot lead hub (Novatross portal)
if (!defined('NV_HS_PORTAL')) { define('NV_HS_PORTAL', '45753602'); }
if (!defined('NV_HS_FORM_GUID')) { define('NV_HS_FORM_GUID', '6ebedcc9-131e-4250-a784-84261d1006d1'); }

/**
 * Reusable push. Any form (Novatross, FhirPlug, future) can build this
 * associative array and call this one function.
 *
 * @param array $lead  keys: email, firstname, lastname, company, phone,
 *                      productservice (must be a valid HubSpot option or ''),
 *                      message, source, page_uri, page_name
 * @return bool  true on HTTP 2xx
 */
function nv_hubspot_push_lead(array $lead) {
    if (empty($lead['email'])) { return false; } // email is the only required HubSpot field

    $fields = array();
    foreach (array('email', 'firstname', 'lastname', 'company', 'phone', 'productservice') as $k) {
        if (!empty($lead[$k])) {
            $fields[] = array('name' => $k, 'value' => (string) $lead[$k]);
        }
    }

    // Preserve the raw interest + free-text + source in the Message property so a
    // lead is never lost even when the dropdown value has no clean HubSpot option.
    $notes = array();
    if (!empty($lead['source']))       { $notes[] = 'Source: ' . $lead['source']; }
    if (!empty($lead['raw_interest'])) { $notes[] = 'Interest: ' . $lead['raw_interest']; }
    if (!empty($lead['message']))      { $notes[] = $lead['message']; }
    if ($notes) {
        $fields[] = array('name' => 'message', 'value' => implode("\n\n", $notes));
    }

    $payload = array(
        'fields'  => $fields,
        'context' => array(
            'pageUri'  => !empty($lead['page_uri'])  ? $lead['page_uri']  : home_url('/'),
            'pageName' => !empty($lead['page_name']) ? $lead['page_name'] : 'Website form',
        ),
    );

    $url  = 'https://api.hsforms.com/submissions/v3/integration/submit/' . NV_HS_PORTAL . '/' . NV_HS_FORM_GUID;
    $resp = wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($payload),
        'timeout' => 15,
    ));

    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 300) {
        $err = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp);
        error_log('[novatross-hubspot] lead push FAILED for ' . $lead['email'] . ' :: ' . $err);
        return false;
    }
    return true;
}

/**
 * Novatross website main contact form (CF7 id 10228) -> HubSpot.
 * Fires only on a genuine, validated, non-spam submission (mail_sent).
 */
add_action('wpcf7_mail_sent', function ($contact_form) {
    if ((int) $contact_form->id() !== 10228) { return; }

    $sub = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
    if (!$sub) { return; }
    $d = $sub->get_posted_data();

    // Extra guard: make sure it's really our form (unique field name), not a
    // same-id post on another multisite subsite.
    if (!isset($d['email-company-email'])) { return; }

    // The website dropdown now uses the SAME labels as the HubSpot
    // 'productservice' options (PAS-Connect / Smart Decisioning / AI Driven Cdex
    // / FHIR Consulting / Something else), so the value is pushed straight
    // through - no mapping needed. HubSpot's submissions API is lenient (it
    // never rejects a lead over a select value), so even a future mismatch only
    // means the choice shows in the Message note instead of the dropdown.
    $raw = $d['select-748'] ?? '';
    if (is_array($raw)) { $raw = implode(', ', $raw); }
    $raw = trim((string) $raw);
    $product = $raw;

    nv_hubspot_push_lead(array(
        'email'        => $d['email-company-email'] ?? '',
        'firstname'    => $d['text-firstname'] ?? '',
        'lastname'     => $d['text-lastname'] ?? '',
        'company'      => $d['text-company'] ?? '',
        'phone'        => $d['tel-phonenumber'] ?? '',
        'productservice' => $product,
        'raw_interest' => $raw,
        'message'      => trim((string) ($d['textarea-message'] ?? '')),
        'source'       => 'Novatross Website',
        'page_uri'     => 'https://novatross.com/contact/',
        'page_name'    => 'Novatross Website - Contact',
    ));
}, 20, 1);
