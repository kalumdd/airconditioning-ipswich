# airconditioning-ipswich.co.uk

Static website for **airconditioning-ipswich.co.uk** (part of Anglian Aircon). Plain HTML/CSS/JS plus a small PHP video e-survey uploader. No build step.

## Deploy to Hostinger
Upload the contents of this repo to the domain's `public_html`, or connect the repo in hPanel → Advanced → Git.

## E-survey admin password
`e-survey/config.php` is not in git because it holds the admin password.
On the server, copy `e-survey/config.example.php` to `e-survey/config.php` and set `ESURVEY_ADMIN_PASSWORD`.
If you deploy from the zip, the real `config.php` is already included.

## Pages
Home, 6 service pages, price guide, 6 area pages, video e-survey, size and running-cost calculators, FAQs, about, contact, privacy, 404.
Sitemap: `/sitemap.xml`. Videos are stored outside `public_html` in `esurvey-data/` and deleted after 180 days.
