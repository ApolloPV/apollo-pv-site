# Website Inquiry Backend

The contact form posts to `/submit-inquiry.php`.

Behavior:

1. Validates required fields: name, email, and project details.
2. Uses a honeypot field named `bot-field` for basic spam filtering.
3. Emails the inquiry to `sales@apollopvdesign.com`.
4. Creates/updates a HubSpot contact and creates a deal in the `Website Inquiry` pipeline when `config/hubspot.php` is present.
5. Saves a backup CSV to `submissions/inquiries.csv` on the Hostinger server.
6. Redirects the visitor to `/thank-you.html`.

The `submissions/` folder includes `.htaccess` with `Require all denied` so the CSV should not be publicly accessible via browser.

To retrieve CSV submissions manually in Hostinger:

- hPanel → Websites → Manage `apollopvdesign.com`
- Files → File Manager
- Open the site folder, usually `/domains/apollopvdesign.com/public_html/`
- Open `submissions/inquiries.csv`

If email or HubSpot delivery does not work, the CSV backup should still capture the inquiry.

HubSpot config is generated during GitHub Actions deploy from the repo secret `HUBSPOT_PRIVATE_APP_TOKEN`. It is not committed to git.

Current HubSpot target:

- Pipeline: `Website Inquiry` (`2346115797`)
- Stage: `Appointment Scheduled` (`3823191778`)
