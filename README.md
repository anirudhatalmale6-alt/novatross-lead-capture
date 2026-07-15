# Novatross Lead Capture (HubSpot)

Central lead-capture integration. Pushes website form submissions into one HubSpot
"lead hub" form with every field mapped, so leads from the Novatross website,
FhirPlug, and any future form all land in one place.

## What it does
- Hooks Contact Form 7 submissions (Novatross main contact form, id `10228`).
- On a genuine, validated, non-spam submission, POSTs to HubSpot's Forms
  Submissions API with all fields mapped: email, first/last name, company,
  phone, product interest, and message.
- Tags each lead with a **Source** (e.g. `Novatross Website`) so leads from
  different products/sites are distinguishable in HubSpot.
- Runs as a **must-use plugin** so it survives theme changes and WordPress'
  content sanitisation.

## Reusing it for another form (FhirPlug, future forms)
Any form just builds an array and calls the one shared function:

```php
nv_hubspot_push_lead(array(
    'email'          => '...',   // required
    'firstname'      => '...',
    'lastname'       => '...',
    'company'        => '...',
    'phone'          => '...',
    'productservice' => '...',   // must match a HubSpot dropdown option, or leave blank
    'raw_interest'   => '...',   // preserved in the Message note regardless
    'message'        => '...',
    'source'         => 'FhirPlug',
    'page_uri'       => 'https://...',
    'page_name'      => 'FhirPlug - Contact',
));
```

## Config
`NV_HS_PORTAL` and `NV_HS_FORM_GUID` at the top of the plugin point at the
HubSpot lead-hub form.

## Hard rule: NO PHI
Only business-contact and product-interest metadata is ever sent to HubSpot.
No patient / clinical data. This is enforced in code (the CF7 form collects no
PHI) and must stay that way for any form that reuses this function - HubSpot's
terms forbid PHI and there is no BAA in place.

## Install
Copy `novatross-lead-capture.php` into `wp-content/mu-plugins/` on the target
WordPress site. No activation needed (must-use plugins load automatically).
