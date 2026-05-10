<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Services\Modpacks\ModpackProviderService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ModpackController extends ClientApiController
{
    private const ROOT = '/.pterodactyl-modpacks';
    private const MANIFEST = '/.pterodactyl-modpacks/manifest.json';

    public function __construct(
        private DaemonFileRepository $files,
        private ModpackProviderService $providers,
    ) {
        parent::__construct();
    }

    public function index(Request $request, Server $server): array
    {
        $this->authorizeOwner($request, $server);

        $provider = $request->query('provider', 'modrinth');
        $type = $request->query('type', 'modpack');
        $limit = min(100, max(10, (int) $request->query('limit', config('pterodactyl.modpacks.default_page_size', 50))));
        $gameVersion = $this->cleanGameVersion($request->query('game_version'));

        return [
            'data' => $this->providers->search($server, $provider, $type, $request->query('query'), $limit, $gameVersion),
            'meta' => $this->meta($server),
        ];
    }

    public function versions(Request $request, Server $server, string $provider, string $project): array
    {
        $this->authorizeOwner($request, $server);
        $gameVersion = $this->cleanGameVersion($request->query('game_version'));

        return [
            'data' => $this->providers->versions($server, $provider, $project, $request->query('type', 'modpack'), $gameVersion),
            'meta' => $this->meta($server),
        ];
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        set_time_limit(0);

        $this->authorizeOwner($request, $server);

        $data = $request->validate([
            'provider' => 'required|in:curseforge,modrinth',
            'type' => 'required|in:mod,modpack',
            'project_id' => 'required|string',
            'version_id' => 'required|string',
            'name' => 'required|string|max:191',
            'replace' => 'sometimes|boolean',
        ]);

        $this->ensureSupported($server);

        if (!$this->acquireInstallLock($server)) {
            throw new ConflictHttpException('Another mod or modpack installation is already running for this server.');
        }
        Cache::forget($this->installCancelKey($server));

        try {
        $this->prepareWorkspace($server);
        $this->setInstallStatus($server, [
            'stage' => 'resolving',
            'message' => 'Checking the installed manifest and preparing the server workspace...',
            'current' => 0,
            'total' => 0,
            'percent' => 5,
            'details' => [
                'Preparing .pterodactyl-modpacks and mods directories.',
                'Reading the existing manifest so updates can remove only files tracked by this addon.',
            ],
        ]);

        $manifest = $this->readManifest($server);
        $key = $data['provider'] . ':' . $data['type'] . ':' . $data['project_id'];
        $existing = $manifest[$key] ?? null;
        $replace = (bool) ($data['replace'] ?? false);

        if ($existing && !$replace) {
            $this->setInstallStatus($server, [
                'stage' => 'error',
                'message' => sprintf('%s is already installed. Use Update/Reinstall to replace it.', $existing['name'] ?? $data['name']),
                'percent' => 100,
            ]);

            throw new ConflictHttpException(sprintf('%s is already installed. Use Update/Reinstall to replace it.', $existing['name'] ?? $data['name']));
        }

        $oldFiles = $this->safeFileList($existing['files'] ?? []);

        try {
            $this->setInstallStatus($server, [
                'stage' => 'resolving',
                'message' => 'Fetching provider metadata, dependencies, and server pack information...',
                'current' => 0,
                'total' => 0,
                'percent' => 5,
                'details' => [
                    'Contacting the selected provider for the requested version.',
                    'Checking dependencies, loader compatibility, and CurseForge server pack metadata.',
                ],
            ]);
            $resolved = $this->providers->resolveFiles($server, $data['provider'], $data['project_id'], $data['version_id'], $data['type']);
            if (empty($resolved)) {
                throw new BadRequestHttpException('No downloadable files were returned for this version.');
            }

            $this->setInstallStatus($server, [
                'stage' => 'resolving',
                'message' => 'Validating resolved files and checking for manual CurseForge downloads...',
                'current' => 0,
                'total' => count($resolved),
                'percent' => 6,
                'details' => [
                    'Checking whether every required file has an automatic download URL.',
                    'If CurseForge blocks a file, the install will stop and show manual download links before touching server files.',
                ],
            ]);
            $missingManualDownloads = $this->missingManualDownloads($server, $resolved);
            if ($missingManualDownloads) {
                $this->setInstallStatus($server, [
                    'stage' => 'manual',
                    'message' => sprintf('%d CurseForge files must be downloaded manually before installation can continue.', count($missingManualDownloads)),
                    'current' => 0,
                    'total' => count($missingManualDownloads),
                    'percent' => 100,
                    'manual_downloads' => $missingManualDownloads,
                ]);

                throw new BadRequestHttpException($this->manualDownloadMessage($missingManualDownloads));
            }

            $installed = [];
            $completed = 0;
            $total = $this->resolvedFileCount($resolved);
            $this->setInstallStatus($server, [
                'stage' => 'installing',
                'message' => sprintf('Installing %d files...', $total),
                'current' => 0,
                'total' => $total,
                'percent' => 8,
                'details' => [
                    'Files are installed one by one to avoid the Wings simultaneous download limit.',
                    'Server pack archives are downloaded first, then extracted on the node by Wings.',
                ],
            ]);

            foreach ($resolved as $index => $file) {
                if ($this->installWasCancelled($server)) {
                    return $this->cancelInstallRun($server, $installed, $oldFiles);
                }

                $target = isset($file['content'], $file['path'])
                    ? $file['path']
                    : trim($file['directory'], '/') . '/' . $file['filename'];

                $this->setInstallStatus($server, [
                    'stage' => 'installing',
                    'message' => sprintf('Installing %s', basename($target)),
                    'current' => $completed,
                    'total' => $total,
                    'percent' => $this->installPercent($completed, $total),
                    'file' => $target,
                ]);

                if (!empty($file['archive'])) {
                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => sprintf('Downloading server pack archive %s...', basename($target)),
                        'current' => $completed,
                        'total' => $total,
                        'percent' => $this->installPercent($completed, $total),
                        'file' => $target,
                        'details' => [
                            'Downloading the CurseForge server pack zip to the server root.',
                            'This is a single large remote download handled by Wings; file-by-file progress is not available until extraction finishes.',
                            'The temporary archive will be removed after extraction.',
                        ],
                    ]);

                    $before = $this->serverFileSnapshot($server);
                    $this->ensureDirectory($server, trim($file['directory'], '/'));
                    $this->files->setServer($server)->pull($file['url'], $file['directory'], [
                        'filename' => $file['filename'],
                        'foreground' => true,
                        'timeout' => 3600,
                    ]);

                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => sprintf('Extracting server pack archive %s...', basename($target)),
                        'current' => $completed,
                        'total' => $total,
                        'percent' => max($this->installPercent($completed, $total), 20),
                        'file' => $target,
                        'details' => [
                            'The zip download finished.',
                            'Wings is extracting the archive into the server files now.',
                            'Large server packs can stay here for several minutes because Wings returns only after extraction completes.',
                        ],
                    ]);

                    $this->files->setServer($server)->decompressFile($file['directory'], $file['filename']);
                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => sprintf('Removing temporary archive %s...', basename($target)),
                        'current' => $completed,
                        'total' => $total,
                        'percent' => max($this->installPercent($completed, $total), 30),
                        'file' => $target,
                        'details' => [
                            'Archive extraction finished.',
                            'Removing the temporary .pterodactyl-serverpack zip from the server root.',
                        ],
                    ]);
                    $this->files->setServer($server)->deleteFiles($file['directory'], [$file['filename']]);

                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => 'Scanning extracted server pack files...',
                        'current' => $completed,
                        'total' => $total,
                        'percent' => max($this->installPercent($completed, $total), 40),
                        'file' => $target,
                        'details' => [
                            'Collecting the list of files created by the archive.',
                            'Those paths will be stored in the manifest so removal/update only touches files installed by this addon.',
                        ],
                    ]);
                    $archiveFiles = array_values(array_diff($this->serverFileSnapshot($server), $before));
                    $installed = array_merge($installed, $archiveFiles);
                    $completed += count($archiveFiles) ?: 1;
                    $total = max($total, $completed);

                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => sprintf('Installed %s', basename($target)),
                        'current' => min($completed, $total),
                        'total' => $total,
                        'percent' => $this->installPercent($completed, $total),
                        'file' => $target,
                        'details' => [
                            sprintf('Detected %d extracted files from the server pack.', count($archiveFiles)),
                            'Next step: write the manifest and helper scripts.',
                        ],
                    ]);

                    if ($this->installWasCancelled($server)) {
                        return $this->cancelInstallRun($server, $installed, $oldFiles);
                    }

                    continue;
                }

                if (!empty($file['manual'])) {
                    $installed[] = $target;
                    ++$completed;

                    $this->setInstallStatus($server, [
                        'stage' => 'installing',
                        'message' => sprintf('Validated manually uploaded file %s', basename($target)),
                        'current' => $completed,
                        'total' => $total,
                        'percent' => $this->installPercent($completed, $total),
                        'file' => $target,
                    ]);

                    if ($this->installWasCancelled($server)) {
                        return $this->cancelInstallRun($server, $installed, $oldFiles);
                    }

                    continue;
                }

                if (isset($file['content'], $file['path'])) {
                    $this->ensureDirectory($server, dirname($file['path']));
                    $this->files->setServer($server)->putContent('/' . $file['path'], $file['content']);
                    $installed[] = $file['path'];
                    ++$completed;

                    if ($this->installWasCancelled($server)) {
                        return $this->cancelInstallRun($server, $installed, $oldFiles);
                    }

                    continue;
                }

                $this->ensureDirectory($server, trim($file['directory'], '/'));
                $this->files->setServer($server)->pull($file['url'], $file['directory'], [
                    'filename' => $file['filename'],
                    'foreground' => true,
                    'timeout' => 600,
                ]);

                $installed[] = $target;
                ++$completed;

                $this->setInstallStatus($server, [
                    'stage' => 'installing',
                    'message' => sprintf('Installed %s', basename($target)),
                    'current' => $completed,
                    'total' => $total,
                    'percent' => $this->installPercent($completed, $total),
                    'file' => $target,
                ]);

                if ($this->installWasCancelled($server)) {
                    return $this->cancelInstallRun($server, $installed, $oldFiles);
                }
            }
        } catch (DaemonConnectionException $exception) {
            $message = $this->daemonErrorMessage($exception);
            $this->setInstallStatus($server, [
                'stage' => 'error',
                'message' => $message,
                'percent' => 100,
            ]);

            throw new BadRequestHttpException($message, $exception);
        } catch (Throwable $exception) {
            if ((Cache::get($this->installStatusKey($server))['stage'] ?? null) === 'manual') {
                throw $exception;
            }

            $this->setInstallStatus($server, [
                'stage' => 'error',
                'message' => $exception->getMessage(),
                'percent' => 100,
            ]);

            throw $exception;
        }

        if ($this->installWasCancelled($server)) {
            return $this->cancelInstallRun($server, $installed, $oldFiles);
        }

        $installed = array_values(array_unique($this->safeFileList($installed)));
        $staleFiles = array_values(array_diff($oldFiles, $installed));
        if ($replace && $staleFiles) {
            try {
                $this->files->setServer($server)->deleteFiles('/', $staleFiles);
            } catch (Throwable) {
            }
        }

        $manifest[$key] = [
            'provider' => $data['provider'],
            'type' => $data['type'],
            'project_id' => $data['project_id'],
            'version_id' => $data['version_id'],
            'name' => $data['name'],
            'loader' => $this->providers->loaderFor($server),
            'files' => $installed,
        ];

        $this->writeManifest($server, $manifest);
        $this->setInstallStatus($server, [
            'stage' => 'installing',
            'message' => 'Writing helper scripts and finalizing manifest...',
            'current' => count($installed),
            'total' => count($installed),
            'percent' => 99,
            'details' => [
                'The manifest has been written.',
                'Generating helper scripts inside .pterodactyl-modpacks.',
            ],
        ]);
        $this->writeScripts($server, $manifest);
        $this->setInstallStatus($server, [
            'stage' => 'complete',
            'message' => $replace ? sprintf('Updated %d files.', count($installed)) : sprintf('Installed %d files.', count($installed)),
            'current' => count($installed),
            'total' => count($installed),
            'percent' => 100,
        ]);

        Activity::event($replace ? 'server:modpacks.update' : 'server:modpacks.install')
            ->property('provider', $data['provider'])
            ->property('type', $data['type'])
            ->property('project_id', $data['project_id'])
            ->property('version_id', $data['version_id'])
            ->property('previous_version_id', $existing['version_id'] ?? null)
            ->log();

        return new JsonResponse(['data' => $manifest[$key]], Response::HTTP_CREATED);
        } finally {
            $this->releaseInstallLock($server);
        }
    }

    public function installStatus(Request $request, Server $server): array
    {
        $this->authorizeOwner($request, $server);

        return [
            'data' => Cache::get($this->installStatusKey($server), [
                'stage' => 'idle',
                'message' => 'No install is running.',
                'current' => 0,
                'total' => 0,
                'percent' => 0,
                'file' => null,
            ]),
        ];
    }

    public function cancelInstall(Request $request, Server $server): JsonResponse
    {
        $this->authorizeOwner($request, $server);

        $status = Cache::get($this->installStatusKey($server), []);
        if (!$this->installStatusIsActive($status)) {
            return new JsonResponse([], Response::HTTP_NO_CONTENT);
        }

        Cache::put($this->installCancelKey($server), true, now()->addHours(4));
        $this->setInstallStatus($server, [
            'stage' => $status['stage'] ?? 'installing',
            'message' => 'Cancelling installation after the current file finishes...',
            'current' => (int) ($status['current'] ?? 0),
            'total' => (int) ($status['total'] ?? 0),
            'percent' => (int) ($status['percent'] ?? 0),
            'file' => $status['file'] ?? null,
        ]);

        return new JsonResponse([], Response::HTTP_ACCEPTED);
    }

    public function installed(Request $request, Server $server): array
    {
        $this->authorizeOwner($request, $server);

        return ['data' => array_values($this->readManifest($server)), 'meta' => $this->meta($server)];
    }

    public function delete(Request $request, Server $server): JsonResponse
    {
        $this->authorizeOwner($request, $server);

        $data = $request->validate([
            'provider' => 'required|in:curseforge,modrinth',
            'type' => 'required|in:mod,modpack',
            'project_id' => 'required|string',
        ]);

        $manifest = $this->readManifest($server);
        $key = $data['provider'] . ':' . $data['type'] . ':' . $data['project_id'];
        if (!isset($manifest[$key])) {
            throw new BadRequestHttpException('This project is not installed on the server.');
        }

        $files = $this->safeFileList($manifest[$key]['files'] ?? []);
        if ($files) {
            $this->files->setServer($server)->deleteFiles('/', $files);
        }

        unset($manifest[$key]);
        $this->writeManifest($server, $manifest);
        $this->writeScripts($server, $manifest);

        Activity::event('server:modpacks.delete')
            ->property('provider', $data['provider'])
            ->property('type', $data['type'])
            ->property('project_id', $data['project_id'])
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    private function authorizeOwner(Request $request, Server $server): void
    {
        $user = $request->user();
        if (!$user->root_admin && $server->owner_id !== $user->id) {
            throw new AccessDeniedHttpException('Only the server owner or an administrator can manage mods and modpacks.');
        }
    }

    private function ensureSupported(Server $server): void
    {
        if (!$this->providers->loaderFor($server)) {
            throw new BadRequestHttpException('This server egg does not look like a Forge, NeoForge, or Fabric server.');
        }
    }

    private function meta(Server $server): array
    {
        return [
            'loader' => $this->providers->loaderFor($server),
            'curseforge_enabled' => (bool) config('pterodactyl.modpacks.curseforge.enabled', true) && (bool) config('pterodactyl.modpacks.curseforge.api_key'),
            'modrinth_enabled' => (bool) config('pterodactyl.modpacks.modrinth.enabled', true),
            'default_page_size' => (int) config('pterodactyl.modpacks.default_page_size', 50),
        ];
    }

    private function cleanGameVersion(mixed $version): ?string
    {
        if (!is_string($version)) {
            return null;
        }

        $version = trim($version);

        return preg_match('/^\d+(?:\.\d+){1,2}$/', $version) ? $version : null;
    }

    private function setInstallStatus(Server $server, array $status): void
    {
        Cache::put($this->installStatusKey($server), array_merge([
            'stage' => 'installing',
            'message' => '',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'file' => null,
            'manual_downloads' => null,
            'details' => [],
            'updated_at' => now()->toIso8601String(),
        ], $status), now()->addHours(4));
    }

    private function installStatusKey(Server $server): string
    {
        return 'modpacks:install:' . $server->uuid;
    }

    private function installCancelKey(Server $server): string
    {
        return 'modpacks:install-cancel:' . $server->uuid;
    }

    private function safeFileList(array $files): array
    {
        return array_values(array_unique(array_filter($files, function ($path) {
            return is_string($path)
                && $path !== ''
                && !str_contains($path, '..')
                && !str_starts_with($path, '/');
        })));
    }

    private function targetPath(array $file): string
    {
        return isset($file['content'], $file['path'])
            ? $file['path']
            : trim($file['directory'], '/') . '/' . $file['filename'];
    }

    private function missingManualDownloads(Server $server, array $files): array
    {
        $manual = array_values(array_filter($files, fn (array $file) => !empty($file['manual'])));
        if (!$manual) {
            return [];
        }

        $existingFiles = $this->serverFileSnapshot($server);

        return array_values(array_filter(array_map(function (array $file) use ($existingFiles) {
            $target = $this->targetPath($file);
            if (in_array($target, $existingFiles, true)) {
                return null;
            }

            return [
                'project' => $file['project'] ?? 'CurseForge project',
                'filename' => $file['filename'] ?? basename($target),
                'directory' => $file['directory'] ?? '/mods',
                'target' => $target,
                'url' => $file['url'] ?? null,
                'reason' => $file['reason'] ?? 'This file must be downloaded manually.',
            ];
        }, $manual)));
    }

    private function manualDownloadMessage(array $downloads): string
    {
        $lines = ['Some CurseForge files cannot be downloaded automatically. Upload these files, then retry the install:'];
        foreach ($downloads as $download) {
            $lines[] = sprintf(
                '- %s: %s -> /%s (%s)',
                $download['project'] ?? 'CurseForge project',
                $download['url'] ?? 'open the project on CurseForge',
                $download['target'] ?? (($download['directory'] ?? '/mods') . '/' . ($download['filename'] ?? 'file.jar')),
                $download['reason'] ?? 'manual download required'
            );
        }

        return implode("\n", $lines);
    }

    private function installLockKey(Server $server): string
    {
        return 'modpacks:install-lock:' . $server->uuid;
    }

    private function installWasCancelled(Server $server): bool
    {
        return (bool) Cache::pull($this->installCancelKey($server), false);
    }

    private function cancelInstallRun(Server $server, array $installed, array $oldFiles): JsonResponse
    {
        $partialFiles = array_values(array_diff($this->safeFileList($installed), $oldFiles));
        if ($partialFiles) {
            try {
                $this->files->setServer($server)->deleteFiles('/', $partialFiles);
            } catch (Throwable) {
            }
        }

        $this->setInstallStatus($server, [
            'stage' => 'cancelled',
            'message' => $partialFiles
                ? sprintf('Installation cancelled. Removed %d partially installed files.', count($partialFiles))
                : 'Installation cancelled.',
            'current' => 0,
            'total' => 0,
            'percent' => 100,
            'file' => null,
        ]);

        return new JsonResponse(['data' => null], Response::HTTP_ACCEPTED);
    }

    private function acquireInstallLock(Server $server): bool
    {
        $status = Cache::get($this->installStatusKey($server), []);

        if (Cache::has($this->installLockKey($server))) {
            if ($this->installStatusIsActive($status) && !$this->installStatusIsStale($status)) {
                return false;
            }

            Cache::forget($this->installLockKey($server));
        }

        if ($this->installStatusIsActive($status) && !$this->installStatusIsStale($status)) {
            return false;
        }

        return Cache::add($this->installLockKey($server), true, now()->addHours(4));
    }

    private function releaseInstallLock(Server $server): void
    {
        Cache::forget($this->installLockKey($server));
    }

    private function installStatusIsActive(array $status): bool
    {
        return in_array($status['stage'] ?? null, ['resolving', 'installing'], true);
    }

    private function installStatusIsStale(array $status): bool
    {
        $updatedAt = $status['updated_at'] ?? null;

        if (!is_string($updatedAt)) {
            return false;
        }

        $timestamp = strtotime($updatedAt);

        return $timestamp !== false && $timestamp < now()->subHours(4)->getTimestamp();
    }

    private function installPercent(int $current, int $total): int
    {
        if ($total <= 0) {
            return 8;
        }

        return min(99, max(8, (int) floor(($current / $total) * 100)));
    }

    private function resolvedFileCount(array $files): int
    {
        return array_reduce($files, function (int $count, array $file) {
            if (!empty($file['archive']) && !empty($file['files']) && is_array($file['files'])) {
                return $count + count($file['files']);
            }

            return $count + 1;
        }, 0);
    }

    private function serverFileSnapshot(Server $server, string $directory = '/'): array
    {
        $files = [];

        foreach ($this->files->setServer($server)->getDirectory($directory) as $entry) {
            $name = $entry['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $path = trim(trim($directory, '/') . '/' . $name, '/');
            if ($path === '' || str_starts_with($path, '.pterodactyl-modpacks')) {
                continue;
            }

            if ((bool) ($entry['file'] ?? true)) {
                if (!str_starts_with($name, '.pterodactyl-serverpack-')) {
                    $files[] = $path;
                }

                continue;
            }

            $files = array_merge($files, $this->serverFileSnapshot($server, '/' . $path));
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function daemonErrorMessage(DaemonConnectionException $exception): string
    {
        $previous = $exception->getPrevious();
        $response = method_exists($previous, 'getResponse') ? $previous->getResponse() : null;
        if ($response) {
            $body = json_decode($response->getBody()->__toString(), true);
            if (!empty($body['error'])) {
                return 'Wings failed while installing the modpack: ' . $body['error'];
            }
        }

        return $exception->getMessage();
    }

    private function prepareWorkspace(Server $server): void
    {
        $directories = [
            ['name' => '.pterodactyl-modpacks', 'root' => '/'],
            ['name' => 'downloads', 'root' => self::ROOT],
            ['name' => 'mods', 'root' => '/'],
        ];

        foreach ($directories as $directory) {
            try {
                $this->files->setServer($server)->createDirectory($directory['name'], $directory['root']);
            } catch (\Throwable) {
            }
        }
    }

    private function ensureDirectory(Server $server, string $path): void
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || $path === '.') {
            return;
        }

        $root = '/';
        foreach (explode('/', $path) as $part) {
            if ($part === '') {
                continue;
            }

            try {
                $this->files->setServer($server)->createDirectory($part, $root);
            } catch (\Throwable) {
            }

            $root = rtrim($root, '/') . '/' . $part;
        }
    }

    private function readManifest(Server $server): array
    {
        try {
            return json_decode($this->files->setServer($server)->getContent(self::MANIFEST, 1024 * 1024), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function writeManifest(Server $server, array $manifest): void
    {
        $this->files->setServer($server)->putContent(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeScripts(Server $server, array $manifest): void
    {
        $commands = [];
        foreach ($manifest as $installation) {
            foreach (($installation['files'] ?? []) as $file) {
                if (is_string($file) && $file !== '' && !str_contains($file, '..') && !str_starts_with($file, '/')) {
                    $commands[] = sprintf("rm -f -- '%s'", str_replace("'", "'\\''", $file));
                }
            }
        }

        $remove = sprintf(<<<'SH'
#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
MANIFEST=".pterodactyl-modpacks/manifest.json"
if [ ! -s "$MANIFEST" ]; then
  echo "No tracked modpack manifest found."
  exit 0
fi
%s
TMP_MANIFEST="${MANIFEST}.tmp"
printf "{}" > "$TMP_MANIFEST"
mv "$TMP_MANIFEST" "$MANIFEST"
echo "Removed tracked modpack files and cleared $MANIFEST."
SH, $commands ? implode("\n", array_unique($commands)) : ':');

        $install = <<<'SH'
#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
MANIFEST=".pterodactyl-modpacks/manifest.json"

echo "Pterodactyl Modpacks helper"
echo
echo "Install and update mods/modpacks from the Panel's Modpacks tab."
echo "That UI resolves provider metadata, dependencies, CurseForge server packs, and download permissions."
echo

if [ ! -s "$MANIFEST" ]; then
  echo "No mods or modpacks are currently tracked in $MANIFEST."
  exit 0
fi

echo "Tracked installations:"
php -r '
$manifest = json_decode(file_get_contents(".pterodactyl-modpacks/manifest.json"), true) ?: [];
if (!$manifest) {
    echo "  none\n";
    exit(0);
}
foreach ($manifest as $item) {
    $name = $item["name"] ?? "Unknown";
    $provider = $item["provider"] ?? "unknown";
    $type = $item["type"] ?? "unknown";
    $version = $item["version_id"] ?? "unknown";
    $files = is_array($item["files"] ?? null) ? count($item["files"]) : 0;
    echo "  - {$name} ({$provider} {$type}, version {$version}, {$files} files)\n";
}
'
SH;

        $this->files->setServer($server)->putContent(self::ROOT . '/install.sh', $install);
        $this->files->setServer($server)->putContent(self::ROOT . '/uninstall.sh', $remove);
        try {
            $this->files->setServer($server)->chmodFiles(self::ROOT, [
                ['file' => 'install.sh', 'mode' => '0755'],
                ['file' => 'uninstall.sh', 'mode' => '0755'],
            ]);
        } catch (\Throwable) {
        }
    }
}
