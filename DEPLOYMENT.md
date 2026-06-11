# Apollo PV Hostinger Auto-Deploy

This site is set up to deploy from GitHub to Hostinger whenever code is pushed to the `main` branch.

## 1. Create a GitHub repository

Create a new GitHub repo, for example:

- `apollo-pv-site`

Do **not** initialize it with a README, because this folder already has one.

## 2. Push this folder to GitHub

From this folder:

```bash
git init
git branch -M main
git add .
git commit -m "Initial Apollo PV website"
git remote add origin git@github.com:YOUR-GITHUB-USERNAME/apollo-pv-site.git
git push -u origin main
```

If using HTTPS instead of SSH:

```bash
git remote add origin https://github.com/YOUR-GITHUB-USERNAME/apollo-pv-site.git
```

## 3. Add GitHub Actions secrets

In GitHub:

`Repo → Settings → Secrets and variables → Actions → New repository secret`

Add these secrets from Hostinger:

| Secret name | Value |
| --- | --- |
| `HOSTINGER_FTP_SERVER` | Hostinger FTP hostname, often `ftp.apollopvdesign.com` or the FTP server shown in hPanel |
| `HOSTINGER_FTP_USERNAME` | Hostinger FTP username |
| `HOSTINGER_FTP_PASSWORD` | Hostinger FTP password |
| `HOSTINGER_SERVER_DIR` | Target folder, usually `/public_html/` or `/domains/apollopvdesign.com/public_html/` |

## 4. Test deploy

After the secrets are added, push any change to `main`, or run the workflow manually:

`Repo → Actions → Deploy to Hostinger → Run workflow`

## Important note about the contact form

The current form uses Netlify-style form attributes. Hostinger will serve the static pages, but it will not process Netlify forms. The site may need a separate form handler, email service, CRM form, or embedded HubSpot form for submissions to work on Hostinger.
