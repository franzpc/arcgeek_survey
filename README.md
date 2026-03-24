# ArcGeek Survey — QGIS Plugin

Collect geolocated field survey data directly from QGIS and manage everything from your browser.

**Web portal:** https://acolita.com/survey/

---

## What it does

- Design survey forms with custom fields in the web portal
- Collect responses in the field using the QGIS plugin (GPS coordinates included)
- View and export responses as QGIS layers (points with attributes)
- Store responses in your own Supabase or PostgreSQL database, or use the free hosted storage

## Requirements

- QGIS 3.x
- An account at https://acolita.com/survey/

## Plugin installation

1. Download the latest release from the [Releases](../../releases) page
2. In QGIS: **Plugins → Manage and Install Plugins → Install from ZIP**
3. Select the downloaded `.zip` file and click **Install Plugin**
4. The plugin appears in the **Plugins** menu as **ArcGeek Survey**

## First use

1. Open the plugin: **Plugins → ArcGeek Survey**
2. Enter your email and password (same credentials as the web portal)
3. Select a form and click **Load** — responses appear as a point layer in QGIS

## Support

Open an issue in this repository or use the contact form at https://acolita.com/survey/
