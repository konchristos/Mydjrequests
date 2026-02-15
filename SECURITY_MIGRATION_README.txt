Security migration (cPanel)

1) Upload and replace the patched files in this zip.
2) Create folder outside web root:
   mkdir -p /home/mydjrequests/.secrets
   chmod 700 /home/mydjrequests/.secrets
3) Copy template file from public_html to secret location:
   cp /home/mydjrequests/public_html/mydjrequests.php.secrets-template /home/mydjrequests/.secrets/mydjrequests.php
4) Edit /home/mydjrequests/.secrets/mydjrequests.php and replace all REPLACE_* values.
5) Lock permissions:
   chmod 600 /home/mydjrequests/.secrets/mydjrequests.php

Important:
- Rotate any leaked keys first (Stripe/Spotify/DB password).
- Keep APP_DEBUG=false in production.
