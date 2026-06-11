# Website Inquiry Backend

The contact form posts to `/submit-inquiry.php`.

Behavior:

1. Validates required fields: name, email, and project details.
2. Uses a honeypot field named `bot-field` for basic spam filtering.
3. Emails the inquiry to `sales@apollopvdesign.com`.
4. Saves a backup CSV to `submissions/inquiries.csv` on the Hostinger server.
5. Redirects the visitor to `/thank-you.html`.

The `submissions/` folder includes `.htaccess` with `Require all denied` so the CSV should not be publicly accessible via browser.

To retrieve CSV submissions manually in Hostinger:

- hPanel → Websites → Manage `apollopvdesign.com`
- Files → File Manager
- Open the site folder, usually `/domains/apollopvdesign.com/public_html/`
- Open `submissions/inquiries.csv`

If email delivery does not work, the CSV backup should still capture the inquiry.

Future upgrade: replace this with a HubSpot embedded form or a server-side HubSpot API submission once a HubSpot form ID/private server-side token flow is configured.
