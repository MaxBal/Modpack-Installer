# Pterodactyl Modpacks Addon

Add a **Modpacks** tab to Pterodactyl Panel servers for installing and managing Minecraft mods and modpacks from **Modrinth** and **CurseForge**.

The addon is designed for Forge, NeoForge, and Fabric servers. It installs files through Wings, tracks installed files in a manifest, and can remove or update only the files it installed.

## Features

- Server-level **Modpacks** tab in the Pterodactyl client area.
- Provider support:
  - Modrinth mods and modpacks.
  - CurseForge mods and modpacks.
- Loader-aware filtering for Forge, NeoForge, and Fabric servers.
- Optional Minecraft version filter, for example `1.20.1`.
- Search updates while typing.
- Provider, type, and page size changes update results immediately.
- CurseForge API key settings page in the admin panel.
- CurseForge API key is stored in settings and shown masked in the UI.
- CurseForge server pack support:
  - If a CurseForge modpack version has attached server files, the addon downloads the server pack zip automatically.
  - The server pack is extracted on the node by Wings.
  - The temporary `.pterodactyl-serverpack-*.zip` file is removed after extraction.
- Automatic dependency resolution where provider APIs expose dependency metadata.
- Sequential file downloads to avoid Wings' simultaneous remote download limit.
- Progress panel with:
  - current stage,
  - percent,
  - current file,
  - completed file count,
  - detailed step descriptions.
- Install cancellation.
- Reopenable install progress modal.
- Update/reinstall support for already installed projects.
- File manifest tracking in `.pterodactyl-modpacks/manifest.json`.
- Safe removal based on the manifest.
- Helper scripts written to `.pterodactyl-modpacks/install.sh` and `.pterodactyl-modpacks/uninstall.sh`.
- Installer script with install, update/reinstall, and uninstall modes.
- Automatic backup of overwritten panel files before applying addon files.
- Automatic Node.js, npm, and Yarn Classic checks for panel asset builds.
- Restores previous frontend assets if the production build fails.

## Requirements

- Pterodactyl Panel installed and working.
- Shell access to the panel server.
- Composer available on the panel server.
- PHP version supported by your Pterodactyl installation.
- A working Wings node connected to the panel.
- Remote file download must be enabled in Wings.
- For CurseForge support: a CurseForge API key.

The installer can install Node.js 22, npm, and Yarn Classic if they are missing on Debian/Ubuntu systems with `apt-get`.

## Download

Download or upload the full `modpacks-addon-upload` folder to your panel server.

Recommended location:

```bash
/opt/pterodactyl-panel/modpacks-addon-upload
```

If your panel is installed somewhere else, place the folder directly inside your panel directory.

## Installation

SSH into your panel server and run:

```bash
cd /opt/pterodactyl-panel
sh modpacks-addon-upload/addon.sh
```

If the addon is not installed yet, the script will offer to install it.

During installation the script will:

1. Detect the panel directory.
2. Back up overwritten files to `storage/modpacks-addon-backups/<timestamp>`.
3. Copy addon files into the panel.
4. Fix permissions for `storage`, `bootstrap/cache`, and `public/assets`.
5. Check Node.js, npm, and Yarn Classic.
6. Refresh Composer autoload.
7. Install frontend dependencies.
8. Build production frontend assets.
9. Clear Laravel caches.
10. Restart common PHP-FPM and web server services if available.

After installation, open:

```text
Admin Panel > Settings > Modpacks
```

Configure:

- CurseForge enabled or disabled.
- CurseForge API key.
- Modrinth enabled or disabled.
- Default page size.

## Updating Or Reinstalling The Addon

Upload the new `modpacks-addon-upload` folder, replacing the old upload folder, then run:

```bash
cd /opt/pterodactyl-panel
sh modpacks-addon-upload/addon.sh
```

If the addon is already installed, choose:

```text
1) Update/Reinstall addon files
```

Existing addon settings are kept.

## Uninstalling The Addon

Run:

```bash
cd /opt/pterodactyl-panel
sh modpacks-addon-upload/addon.sh
```

Choose:

```text
2) Uninstall addon
```

The script attempts to restore panel files from the latest backup in:

```text
storage/modpacks-addon-backups
```

It also removes addon settings from the panel database and rebuilds frontend assets.

## Using The Modpacks Tab

Open any supported Minecraft server in the client area.

If the server egg/image/startup looks like Forge, NeoForge, or Fabric, a **Modpacks** tab appears in the server navigation.

From this tab you can:

1. Select a provider: Modrinth or CurseForge.
2. Select type: Mods or Modpacks.
3. Select page size.
4. Optionally enter a Minecraft version, for example `1.20.1`.
5. Search for a project.
6. Click **Install**.
7. Select a version.
8. Confirm installation.

If the project is already installed, the button changes to **Update**.

## CurseForge Server Packs

Some CurseForge modpacks provide server files in the version's additional files.

When available, the addon prioritizes the CurseForge server pack because it is usually prepared by the modpack author for server use.

The install flow is:

1. Detect `serverPackFileId`.
2. Fetch the server pack file metadata.
3. Download the server pack zip through Wings.
4. Extract the zip on the node.
5. Delete the temporary zip.
6. Scan extracted files.
7. Store the extracted file list in `.pterodactyl-modpacks/manifest.json`.

During extraction, Wings processes the archive as one operation. The panel cannot see per-file extraction progress from Wings, but the addon shows the current stage and explains what is happening.

## Manual CurseForge Downloads

Some CurseForge projects disable third-party file distribution. In that case the API may return no direct `downloadUrl`.

The addon does not try to bypass this.

Instead, it will:

1. Stop before installing files.
2. Show the blocked files.
3. Show CurseForge links.
4. Show the exact upload path, usually `/mods/<file>.jar`.
5. Ask you to upload those files manually.

After uploading the required files, click **Install** or **Update** again. The addon validates that the files exist and continues the installation.

## Install Progress And Cancellation

The progress UI shows:

- stage title,
- detailed message,
- percent,
- completed file count,
- current file path,
- extra details about the current operation.

You can close and reopen the progress modal while installation continues.

Use **Stop install** to request cancellation. If Wings is currently downloading or extracting a file, cancellation happens after the current operation returns.

Partial files installed by the addon are cleaned up where possible.

## File Tracking

Installed files are tracked in:

```text
.pterodactyl-modpacks/manifest.json
```

This manifest is used for:

- showing installed projects,
- updating/reinstalling projects,
- removing only addon-installed files,
- generating helper scripts.

Do not delete this manifest unless you intentionally want to stop tracking installed files.

## Helper Scripts On Game Servers

The addon writes helper scripts into the game server:

```text
.pterodactyl-modpacks/install.sh
.pterodactyl-modpacks/uninstall.sh
```

`install.sh` lists currently tracked installations.

`uninstall.sh` removes tracked files and clears the manifest. It only removes paths listed in the manifest.

## Pterodactyl Version Label

If `config/app.php` still reports:

```php
'version' => 'canary'
```

the installer changes it to:

```php
'version' => '1.12.2'
```

If the panel already has a real release version, the script leaves it alone so future official Pterodactyl updates can manage the version normally.

## Updating Pterodactyl Later

When Pterodactyl releases an update:

1. Update Pterodactyl using the official update guide.
2. Re-run the addon installer.
3. Choose **Update/Reinstall addon files**.

This reapplies addon files and rebuilds panel assets.

## Troubleshooting

### The Modpacks tab is missing

Check that the server is using a Forge, NeoForge, or Fabric egg/image/startup. The addon hides the tab when it cannot detect a supported loader.

### CurseForge results are empty

Check:

- CurseForge is enabled in `Admin Panel > Settings > Modpacks`.
- A valid CurseForge API key is saved.
- The selected Minecraft version has matching files.

### Installation appears stuck on extracting

Large server packs can take several minutes to extract. Wings returns only when extraction is complete, so per-file extraction progress is not available from the panel.

### Wings returns remote download errors

Check:

- Wings remote downloads are enabled.
- The node can reach Modrinth, CurseForge, and CDN hosts.
- The file is not blocked by CurseForge third-party distribution settings.

### Frontend build fails during addon install

The installer restores the previous `public/assets` folder if the build fails. Fix the build error, then run:

```bash
sh modpacks-addon-upload/addon.sh
```

again.

## Notes

This addon modifies panel files directly. Always keep backups and test updates on a staging panel when possible.
