# ArcGeek Survey — Cloud Run API

This repository contains the Google Cloud Run service that exposes the QGIS plugin API endpoint, plus the Hostinger MySQL proxy that keeps your existing database on shared hosting.

```
QGIS Plugin
    │  HTTPS + X-Plugin-Token
    ▼
Cloud Run  (api.php)
    │  HTTPS + PROXY_SECRET
    ▼
Hostinger  (internal/db-proxy.php)  →  MySQL localhost
    │
    └── user_connections table → encrypted Supabase / PostgreSQL credentials
```

---

## Repository layout

```
.github/workflows/deploy.yml   — GitHub Actions: build → push → deploy
cloud-run/
  Dockerfile                   — PHP 8.2 + Apache, port 8080
  public/api.php               — QGIS plugin endpoint
  config/
    database.php               — Proxy client + credential decryption
    security.php               — Plugin-token validation
    plans.php                  — Plan limit constants
hostinger/
  internal/
    db-proxy.php               — MySQL proxy (upload to Hostinger manually)
    secrets.example.php        — Template → copy to secrets.php and fill in
    .htaccess                  — Blocks direct browser access
```

---

## One-time setup

### 1. Google Cloud project

```bash
# Replace YOUR_PROJECT_ID throughout
gcloud projects create YOUR_PROJECT_ID
gcloud config set project YOUR_PROJECT_ID

# Enable required APIs
gcloud services enable \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  secretmanager.googleapis.com \
  cloudbuild.googleapis.com

# Create Artifact Registry repository
gcloud artifacts repositories create arcgeek \
  --repository-format=docker \
  --location=us-central1
```

### 2. Google Secret Manager — create secrets

```bash
# Plugin token (must match the value in your QGIS plugin / Hostinger portal)
echo -n "REPLACE_WITH_PLUGIN_TOKEN"   | gcloud secrets create arcgeek-plugin-token   --data-file=-

# Hostinger proxy URL  (full URL to db-proxy.php, e.g. https://yourdomain.com/internal/db-proxy.php)
echo -n "https://yourdomain.com/internal/db-proxy.php" | gcloud secrets create arcgeek-proxy-url --data-file=-

# Proxy shared secret (must match PROXY_SECRET in hostinger/internal/secrets.php)
echo -n "REPLACE_WITH_PROXY_SECRET"  | gcloud secrets create arcgeek-proxy-secret  --data-file=-

# Encryption key (must match ENCRYPTION_KEY used when credentials were encrypted in Hostinger portal)
echo -n "REPLACE_WITH_ENCRYPTION_KEY" | gcloud secrets create arcgeek-encryption-key --data-file=-

# Admin email
echo -n "franzpc@gmail.com"           | gcloud secrets create arcgeek-admin-email    --data-file=-
```

### 3. Service account for GitHub Actions

```bash
gcloud iam service-accounts create github-actions \
  --display-name="GitHub Actions deployer"

SA="github-actions@YOUR_PROJECT_ID.iam.gserviceaccount.com"

# Grant minimum required roles
gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
  --member="serviceAccount:$SA" --role="roles/run.admin"
gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
  --member="serviceAccount:$SA" --role="roles/artifactregistry.writer"
gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
  --member="serviceAccount:$SA" --role="roles/secretmanager.secretAccessor"
gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
  --member="serviceAccount:$SA" --role="roles/iam.serviceAccountUser"

# Export JSON key
gcloud iam service-accounts keys create sa-key.json --iam-account="$SA"
```

### 4. GitHub repository secrets

In **Settings → Secrets and variables → Actions**, add:

| Secret name     | Value                                       |
|-----------------|---------------------------------------------|
| `GCP_PROJECT_ID`| Your Google Cloud project ID                |
| `GCP_REGION`    | e.g. `us-central1`                          |
| `GCP_SA_KEY`    | Contents of `sa-key.json` (delete the file after) |

### 5. Hostinger — upload proxy files

Upload these two files to your Hostinger file manager under your domain root:

```
internal/db-proxy.php
internal/.htaccess
```

Then copy `hostinger/internal/secrets.example.php` → `secrets.php`, fill in your MySQL credentials and the `PROXY_SECRET`, and upload it to the same `internal/` folder. **Do not commit `secrets.php` to Git.**

### 6. First deploy

Push to `main` — GitHub Actions will:
1. Build the Docker image from `cloud-run/`
2. Push it to Artifact Registry
3. Deploy it to Cloud Run with all secrets injected

```bash
git push origin main
```

The workflow prints the Cloud Run service URL at the end. Update your QGIS plugin's `API_URL` constant to point to `https://<service-url>/public/api.php`.

---

## Updating secrets (e.g. rotating PLUGIN_TOKEN)

```bash
echo -n "NEW_TOKEN_VALUE" | gcloud secrets versions add arcgeek-plugin-token --data-file=-

# Redeploy so Cloud Run picks up the new version
gcloud run services update arcgeek-survey-api \
  --region=us-central1 \
  --set-secrets="PLUGIN_TOKEN=arcgeek-plugin-token:latest,..."
```

---

## Local testing

```bash
cd cloud-run
docker build -t arcgeek-api .
docker run -p 8080:8080 \
  -e PLUGIN_TOKEN=test_token \
  -e PROXY_URL=https://yourdomain.com/internal/db-proxy.php \
  -e PROXY_SECRET=your_proxy_secret \
  -e ENCRYPTION_KEY=your_encryption_key \
  -e ADMIN_EMAIL=franzpc@gmail.com \
  arcgeek-api
# Test: curl http://localhost:8080/public/api.php?form_code=TEST
```
