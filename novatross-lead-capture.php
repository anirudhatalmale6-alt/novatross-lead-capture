<?php
/**
 * Plugin Name: Novatross Lead Capture (HubSpot)
 * Description: Pushes Contact Form 7 submissions into the central HubSpot lead hub
 *              with every field mapped, and gates public forms against spam
 *              (honeypot + content heuristic). NO PHI / patient data is ever
 *              sent to HubSpot. Reusable for Novatross, FhirPlug and future forms.
 * Version: 1.4.0
 * Author: AT
 */

if (!defined('ABSPATH')) { exit; }

// Central HubSpot lead hub (Novatross portal)
if (!defined('NV_HS_PORTAL')) { define('NV_HS_PORTAL', '45753602'); }
if (!defined('NV_HS_FORM_GUID')) { define('NV_HS_FORM_GUID', '6ebedcc9-131e-4250-a784-84261d1006d1'); }
// HubSpot internal field name for the product/service dropdown (label "Product and Services").
if (!defined('NV_HS_PRODUCT_FIELD')) { define('NV_HS_PRODUCT_FIELD', 'product_and_services'); }

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
    foreach (array('email', 'firstname', 'lastname', 'company', 'phone') as $k) {
        if (!empty($lead[$k])) {
            $fields[] = array('name' => $k, 'value' => (string) $lead[$k]);
        }
    }
    // Product/service dropdown -> HubSpot's select field (internal name may differ
    // from its label, so it's kept in one constant).
    if (!empty($lead['productservice'])) {
        $fields[] = array('name' => NV_HS_PRODUCT_FIELD, 'value' => (string) $lead['productservice']);
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
 * Record a blocked submission on the CF7 submission log + PHP error log so we
 * can audit for any false positives.
 */
function nv_log_spam($submission, $agent, $reason) {
    if ($submission && method_exists($submission, 'add_spam_log')) {
        $submission->add_spam_log(array('agent' => $agent, 'reason' => $reason));
    }
    error_log('[novatross-spam] blocked :: ' . $agent . ' :: ' . $reason);
}

/**
 * Lightweight, content-based spam scoring for public forms. Deliberately
 * conservative: only patterns that essentially never appear in a genuine
 * US-healthcare B2B enquiry contribute, and a real lead has to trip a very
 * strong signal (or several weak ones) before it is blocked. Returns
 * array($score, $reasons).
 */
function nv_spam_score(array $f) {
    $score = 0;
    $reasons = array();
    $first   = trim((string) ($f['first'] ?? ''));
    $last    = trim((string) ($f['last'] ?? ''));
    $company = trim((string) ($f['company'] ?? ''));
    $message = trim((string) ($f['message'] ?? ''));
    $name_blob = trim($first . ' ' . $last);
    $all = strtolower(trim($name_blob . ' ' . $company . ' ' . $message));

    // 1) Predominantly non-Latin script in the message/name. This form only takes
    //    English-language enquiries; a Cyrillic / Georgian / CJK / Arabic body is
    //    the signature of the current spam wave. (Accented Latin names such as
    //    "Jose" stay overwhelmingly Latin and are NOT caught.)
    $probe   = trim($message . ' ' . $name_blob);
    $letters = preg_match_all('/\p{L}/u', $probe);
    $latin   = preg_match_all('/\p{Latin}/u', $probe);
    if ($letters >= 4) {
        $nonlatin = $letters - $latin;
        if ($nonlatin / $letters > 0.4) {
            $score += 4;
            $reasons[] = 'non_latin_body(' . $nonlatin . '/' . $letters . ')';
        }
    }

    // 2) Identical first and last name (e.g. "Roberttuh Roberttuh") - weak alone.
    if ($first !== '' && strcasecmp($first, $last) === 0) {
        $score += 2;
        $reasons[] = 'identical_name';
    }

    // 3) URLs where a human never puts them, or link-stuffed messages.
    if (preg_match('~https?://|www\.~i', $name_blob . ' ' . $company)) {
        $score += 3;
        $reasons[] = 'url_in_name';
    }
    $url_hits = preg_match_all('~https?://|www\.|\[url~i', $message);
    if ($url_hits >= 2) {
        $score += 3;
        $reasons[] = 'multi_url(' . $url_hits . ')';
    } elseif ($url_hits === 1) {
        $score += 1;
        $reasons[] = 'url';
    }

    // 4) Classic spam vocabulary.
    $kw = array('seo', 'backlink', 'ranking', 'crypto', 'bitcoin', 'casino',
                'viagra', 'cialis', 'porn', 'escort', 'payday loan',
                'guest post', 'increase traffic', 'first page of google',
                'rank higher', 'buy now', 'telegram', 'whatsapp us');
    foreach ($kw as $w) {
        if (strpos($all, $w) !== false) {
            $score += 2;
            $reasons[] = 'kw:' . $w;
        }
    }

    return array($score, $reasons);
}

/**
 * Spam gate for public CF7 forms.
 *  - Honeypot: the CSS-hidden `nv_website` field is only ever filled by bots.
 *  - Content heuristic: blocks the automated form-spam that clears the honeypot
 *    (non-Latin bodies, link stuffing, spam vocabulary). Threshold 4 means a
 *    single strong signal or multiple weak ones; real people are not scored on
 *    anything a genuine enquiry contains.
 * Blocked submissions send no mail and are not pushed to HubSpot.
 */
add_filter('wpcf7_spam', function ($spam, $submission = null) {
    if ($spam) { return $spam; }

    if (!empty($_POST['nv_website'])) {
        nv_log_spam($submission, 'nv_honeypot', 'Honeypot field was filled (bot).');
        return true;
    }

    list($score, $reasons) = nv_spam_score(array(
        'first'   => $_POST['text-firstname'] ?? '',
        'last'    => $_POST['text-lastname'] ?? '',
        'company' => $_POST['text-company'] ?? '',
        'message' => $_POST['textarea-message'] ?? '',
    ));
    if ($score >= 4) {
        nv_log_spam($submission, 'nv_content_filter',
            'Spam score ' . $score . ' [' . implode(', ', $reasons) . ']');
        return true;
    }

    return $spam;
}, 10, 2);

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
