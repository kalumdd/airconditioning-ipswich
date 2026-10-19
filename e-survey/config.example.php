<?php
// =====================================================================
//  Video e-survey settings  (edit these, then upload)
// =====================================================================

// Password for /e-survey/admin.php — CHANGE THIS before going live.
const ESURVEY_ADMIN_PASSWORD = '__ADMIN_PASSWORD__';

// Where uploaded videos are stored. By default this is a folder NEXT TO
// public_html (not inside it), so videos can never be opened by guessing
// a URL. If that folder can't be created, a locked folder inside the site
// is used instead (see esurvey_data_dir() below).
const ESURVEY_DATA_DIR = __DIR__ . '/../../esurvey-data';

// Backup notification by email, used only if the Formspree message fails.
const ESURVEY_NOTIFY_EMAIL = 'info@anglianaircon.co.uk';

// Business name shown on the private viewing page.
const ESURVEY_SITE_NAME = 'Air Conditioning Ipswich';

// Limits
const ESURVEY_MAX_FILES      = 15;                       // per survey
const ESURVEY_MAX_FILE_BYTES = 1024 * 1024 * 1024;       // 1 GB per file
const ESURVEY_MAX_TOTAL      = 3 * 1024 * 1024 * 1024;   // 3 GB per survey
const ESURVEY_CHUNK_BYTES    = 4 * 1024 * 1024;          // 4 MB pieces fit every PHP default
const ESURVEY_RETENTION_DAYS = 180;                      // auto-delete after this
const ESURVEY_STARTS_PER_HOUR = 12;                      // per visitor IP

const ESURVEY_ALLOWED_EXT = [
  'mp4','mov','m4v','3gp','webm','avi','mkv','hevc',
  'jpg','jpeg','png','heic','heif','webp','gif','pdf'
];
