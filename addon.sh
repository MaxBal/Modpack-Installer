#!/bin/sh
set -eu
export COMPOSER_ALLOW_SUPERUSER=1
export YARN_ENABLE_PROGRESS_BARS=0

SOURCE_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

if [ -f "$SOURCE_DIR/artisan" ]; then
  PANEL_DIR="$SOURCE_DIR"
else
  PANEL_DIR="$(dirname "$SOURCE_DIR")"
fi

if [ ! -f "$PANEL_DIR/artisan" ]; then
  echo "Could not find artisan. Upload this folder into /opt/pterodactyl-panel and run:"
  echo "  sh /opt/pterodactyl-panel/modpacks-addon-upload/addon.sh"
  exit 1
fi

FILES="
app/Providers/SettingsServiceProvider.php
config/pterodactyl.php
routes/admin.php
routes/api-client.php
app/Http/Controllers/Admin/Settings/ModpacksController.php
app/Http/Controllers/Api/Client/Servers/ModpackController.php
app/Http/Requests/Admin/Settings/ModpacksSettingsFormRequest.php
app/Repositories/Wings/DaemonFileRepository.php
app/Services/Modpacks/ModpackProviderService.php
app/Transformers/Api/Client/ServerTransformer.php
resources/views/partials/admin/settings/nav.blade.php
resources/views/admin/settings/modpacks.blade.php
resources/scripts/routers/routes.ts
resources/scripts/routers/ServerRouter.tsx
resources/scripts/api/server/getServer.ts
resources/scripts/api/server/modpacks.ts
resources/scripts/components/server/InstallListener.tsx
resources/scripts/components/server/modpacks/ModpacksContainer.tsx
"

BACKUP_DIR=""

step() {
  printf "\n==> %s\n" "$1"
}

yarn_install() {
  yarn install --frozen-lockfile
}

is_installed() {
  [ -f "$PANEL_DIR/app/Http/Controllers/Api/Client/Servers/ModpackController.php" ] &&
    [ -f "$PANEL_DIR/resources/scripts/components/server/modpacks/ModpacksContainer.tsx" ] &&
    grep -q "admin.settings.modpacks" "$PANEL_DIR/routes/admin.php" 2>/dev/null &&
    grep -q "ModpacksContainer" "$PANEL_DIR/resources/scripts/routers/routes.ts" 2>/dev/null
}

fix_permissions() {
  step "Fixing file permissions"
  cd "$PANEL_DIR"
  if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data storage bootstrap/cache public/assets || true
  fi
  chmod -R ug+rwX storage bootstrap/cache || true
}

restart_services() {
  step "Restarting panel services"
  if command -v systemctl >/dev/null 2>&1; then
    systemctl restart php8.4-fpm 2>/dev/null || true
    systemctl restart php8.3-fpm 2>/dev/null || true
    systemctl restart php8.2-fpm 2>/dev/null || true
    systemctl restart apache2 2>/dev/null || true
    systemctl restart nginx 2>/dev/null || true
  fi
}

ensure_node() {
  step "Checking Node.js, npm, and yarn"
  if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
      step "Installing Node.js 22 and npm"
      apt-get update
      apt-get install -y ca-certificates curl gnupg
      curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
      apt-get install -y nodejs
    else
      echo "Node.js 22 and npm are required to build frontend assets."
      exit 1
    fi
  fi

  YARN_MAJOR="0"
  if command -v yarn >/dev/null 2>&1; then
    YARN_MAJOR="$(yarn --version | cut -d. -f1)"
  fi

  if [ "$YARN_MAJOR" != "1" ]; then
    step "Installing yarn classic 1.22.22"
    if command -v corepack >/dev/null 2>&1; then
      corepack disable || true
    fi
    npm install -g yarn@1.22.22
  fi
}

rebuild_panel() {
  cd "$PANEL_DIR"

  if ! command -v composer >/dev/null 2>&1; then
    echo "Composer is required but was not found."
    exit 1
  fi

  step "Refreshing Composer autoload"
  composer dump-autoload -o --no-interaction

  step "Backing up current frontend assets"
  ASSETS_BACKUP="$(mktemp -d)"
  if [ -d public/assets ]; then
    cp -a public/assets/. "$ASSETS_BACKUP/"
  fi

  step "Installing frontend dependencies"
  yarn_install

  step "Building frontend assets"
  if ! yarn run build:production; then
    echo "Frontend build failed. Restoring previous public/assets."
    rm -rf public/assets
    mkdir -p public/assets
    cp -a "$ASSETS_BACKUP/." public/assets/ 2>/dev/null || true
    rm -rf "$ASSETS_BACKUP"
    fix_permissions
    exit 1
  fi

  step "Checking built frontend assets"
  if ! php -r '$manifest = json_decode(file_get_contents("public/assets/manifest.json"), true); foreach (["main.js", "server.js", "dashboard.js"] as $key) { $path = ltrim($manifest[$key]["src"] ?? "", "/"); if (!$path || !is_file("public/" . preg_replace("#^assets/#", "assets/", $path))) { fwrite(STDERR, "Missing built asset for {$key}\n"); exit(1); } }'; then
    echo "Frontend build did not produce all required assets. Restoring previous public/assets."
    rm -rf public/assets
    mkdir -p public/assets
    cp -a "$ASSETS_BACKUP/." public/assets/ 2>/dev/null || true
    rm -rf "$ASSETS_BACKUP"
    fix_permissions
    exit 1
  fi

  rm -rf "$ASSETS_BACKUP"

  step "Clearing Laravel caches"
  php artisan optimize:clear
  php artisan route:clear
  php artisan view:clear
  php artisan config:clear
  php artisan queue:restart

  fix_permissions
  restart_services
}

apply_addon_files() {
  BACKUP_DIR="$PANEL_DIR/storage/modpacks-addon-backups/$(date +%Y%m%d%H%M%S)"
  mkdir -p "$BACKUP_DIR"

  step "Copying addon files"
  for file in $FILES; do
    src="$SOURCE_DIR/$file"
    dest="$PANEL_DIR/$file"

    if [ ! -f "$src" ]; then
      echo "Missing package file: $file"
      exit 1
    fi

    if [ "$src" != "$dest" ] && [ -f "$dest" ]; then
      mkdir -p "$BACKUP_DIR/$(dirname "$file")"
      cp -p "$dest" "$BACKUP_DIR/$file"
    fi

    mkdir -p "$(dirname "$dest")"
    cp -f "$src" "$dest"
  done

  cd "$PANEL_DIR"
  if grep -q "'version' => 'canary'" config/app.php; then
    step "Changing panel version label from canary to 1.12.2"
    sed -i "s/'version' => 'canary'/'version' => '1.12.2'/" config/app.php
  fi

  fix_permissions
  ensure_node
  rebuild_panel
}

install_addon() {
  if is_installed; then
    echo "The Modpacks addon already appears to be installed."
    return
  fi

  apply_addon_files
  echo "Installed Pterodactyl Modpacks addon."
  echo "Backup saved to: $BACKUP_DIR"
  echo "Open Admin > Settings > Modpacks and set the CurseForge API key."
}

update_addon() {
  apply_addon_files
  echo "Updated/Reinstalled Pterodactyl Modpacks addon."
  echo "Backup saved to: $BACKUP_DIR"
  echo "Existing addon settings were kept."
}

uninstall_addon() {
  if ! is_installed; then
    echo "The Modpacks addon does not appear to be installed."
    return
  fi

  BACKUP_ROOT="$PANEL_DIR/storage/modpacks-addon-backups"
  LATEST_BACKUP=""
  if [ -d "$BACKUP_ROOT" ]; then
    LATEST_BACKUP="$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1)"
  fi

  cd "$PANEL_DIR"

  if [ -n "$LATEST_BACKUP" ] && [ -d "$LATEST_BACKUP" ]; then
    step "Restoring files from latest addon backup"
    cp -a "$LATEST_BACKUP/." "$PANEL_DIR/"
    echo "Restored files from backup: $LATEST_BACKUP"
  else
    rm -f app/Http/Controllers/Admin/Settings/ModpacksController.php
    rm -f app/Http/Controllers/Api/Client/Servers/ModpackController.php
    rm -f app/Http/Requests/Admin/Settings/ModpacksSettingsFormRequest.php
    rm -rf app/Services/Modpacks
    rm -f resources/scripts/api/server/modpacks.ts
    rm -rf resources/scripts/components/server/modpacks
    rm -f resources/views/admin/settings/modpacks.blade.php
    echo "No backup found. Removed addon-only files, but core route/settings files were not restored."
  fi

  step "Removing addon settings"
  php artisan tinker --execute='
      \Pterodactyl\Models\Setting::query()
          ->whereIn("key", [
              "settings::pterodactyl:modpacks:curseforge:enabled",
              "settings::pterodactyl:modpacks:curseforge:api_key",
              "settings::pterodactyl:modpacks:modrinth:enabled",
              "settings::pterodactyl:modpacks:default_page_size",
          ])
          ->delete();
  '

  ensure_node
  rebuild_panel

  echo "Uninstalled Pterodactyl Modpacks addon."
}

echo "Pterodactyl Modpacks addon"
echo "Panel directory: $PANEL_DIR"
echo

if is_installed; then
  echo "Status: installed"
  echo "Choose an action:"
  echo "  1) Update/Reinstall addon files"
  echo "  2) Uninstall addon"
  echo "  3) Do nothing"
  printf "Enter choice [1-3]: "
  read answer
  case "$answer" in
    1) update_addon ;;
    2) uninstall_addon ;;
    *) echo "Nothing changed." ;;
  esac
else
  echo "Status: not installed"
  printf "Do you want to install it now? [Y/n] "
  read answer
  case "$answer" in
    n|N|no|NO) echo "Nothing changed." ;;
    *) install_addon ;;
  esac
fi
