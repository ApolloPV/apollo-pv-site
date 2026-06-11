# Website Inquiry Backend

The contact form posts to `/submit-inquiry.php`.

Behavior:

1. Validates required fields: name, email, and project details.
2. Uses a honeypot field named `bot-field` for basic spam filtering.
3. Emails the inquiry to `sales@apollopvdesign.com`.
4. Creates/updates a HubSpot contact and creates a deal in the `Website Inquiry` pipeline when `config/hubspot.php` is present.
5. Sends an automated Twilio SMS when `config/twilio.php` is present and the visitor provided a phone number.
6. Saves a backup CSV to `submissions/inquiries.csv` on the Hostinger server.
7. Redirects the visitor to `/thank-you.html`.

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
