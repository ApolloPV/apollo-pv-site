# Website Inquiry Backend

The contact form posts to `/submit-inquiry.php`.

Behavior:

1. Validates required fields: name, email, and project details.
2. Uses a honeypot field named `bot-field` for basic spam filtering.
3. Emails the inquiry to `sales@apollopvdesign.com`.
4. Creates/updates a HubSpot contact and creates a deal in the `Website Inquiry` pipeline when `config/hubspot.php` is present.
5. Sends an automated Twilio SMS when `config/twilio.php` is present and the visitor provided a phone number.
6. Logs outbound and inbound SMS messages to `submissions/sms-messages.csv`.
7. Receives inbound SMS replies at `/twilio-webhook.php`, alerts `sales@apollopvdesign.com`, and attempts to add a HubSpot contact note/update.
8. Saves a backup CSV to `submissions/inquiries.csv` on the Hostinger server.
9. Redirects the visitor to `/thank-you.html`.

The `submissions/` folder includes `.htaccess` with `Require all denied` so the CSV should not be publicly accessible via browser.

To retrieve CSV submissions manually in Hostinger:

- hPanel → Websites → Manage `apollopvdesign.com`
- Files → File Manager
- Open the site folder, usually `/domains/apollopvdesign.com/public_html/`
- Open `submissions/inquiries.csv`

If email, HubSpot, or Twilio delivery does not work, the CSV backup should still capture the inquiry.

HubSpot and Twilio configs are generated during GitHub Actions deploy from repo secrets. They are not committed to git.

Required GitHub secrets:

- `HUBSPOT_PRIVATE_APP_TOKEN`
- `TWILIO_ACCOUNT_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_FROM_NUMBER`

Current HubSpot target:

- Pipeline: `Website Inquiry` (`2346115797`)
- Stage: `Appointment Scheduled` (`3823191778`)

## Twilio inbound SMS setup

After deployment, configure the Apollo PV Twilio phone number:

1. Twilio Console → Phone Numbers → Manage → Active numbers.
2. Open the Apollo PV sending number.
3. Under **Messaging configuration**, set **A message comes in** to:
   - Method: `HTTP POST`
   - URL: `https://apollopvdesign.com/twilio-webhook.php`
4. Save.

Inbound reply behavior:

- Verifies Twilio's request signature using `TWILIO_AUTH_TOKEN`.
- Logs the inbound SMS to `submissions/sms-messages.csv`.
- Searches HubSpot by phone number, creates/updates a contact when possible, and attempts to add an SMS note.
- Emails `sales@apollopvdesign.com` with the reply content and HubSpot status.
- Sends the visitor: “Thanks — Apollo PV received your reply. A team member will follow up shortly. Reply STOP to opt out.”
- If the visitor texts STOP/UNSUBSCRIBE/CANCEL/END/QUIT, it logs and alerts but does not send another message.

Troubleshooting files on Hostinger:

- `submissions/sms-messages.csv`
- `submissions/twilio-errors.log`
- `submissions/twilio-webhook-errors.log`
- `submissions/hubspot-errors.log`
