<?php

namespace Pterodactyl\Services\Modpacks;

use GuzzleHttp\Client;
use Pterodactyl\Models\Server;

class ModpackProviderService
{
    private const MINECRAFT_GAME_ID = 432;

    private Client $http;
    private array $curseForgeModCache = [];

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => config('pterodactyl.guzzle.timeout'),
            'connect_timeout' => config('pterodactyl.guzzle.connect_timeout'),
            'headers' => [
                'User-Agent' => 'Pterodactyl-Modpacks/1.0',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function loaderFor(Server $server): ?string
    {
        $variables = $server->variables
            ->map(fn ($variable) => $variable->server_value ?? $variable->default_value ?? $variable->env_variable)
            ->filter()
            ->implode(' ');

        $value = strtolower(implode(' ', array_filter([
            $server->name,
            $server->egg?->name,
            $server->nest?->name,
            $server->image,
            $server->startup,
            $variables,
        ])));

        return match (true) {
            str_contains($value, 'neoforge') => 'neoforge',
            str_contains($value, 'fabric') => 'fabric',
            str_contains($value, 'forge') => 'forge',
            default => null,
        };
    }

    public function search(Server $server, string $provider, string $type, ?string $query, int $limit, ?string $gameVersion = null): array
    {
        return match ($provider) {
            'curseforge' => $this->searchCurseForge($server, $type, $query, $limit, $gameVersion),
            'modrinth' => $this->searchModrinth($server, $type, $query, $limit, $gameVersion),
            default => [],
        };
    }

    public function versions(Server $server, string $provider, string $projectId, string $type, ?string $gameVersion = null): array
    {
        return match ($provider) {
            'curseforge' => $this->curseForgeVersions($projectId, $type, $gameVersion),
            'modrinth' => $this->modrinthVersions($server, $projectId, $type, $gameVersion),
            default => [],
        };
    }

    public function resolveFiles(Server $server, string $provider, string $projectId, string $versionId, string $type): array
    {
        $files = match ($provider) {
            'curseforge' => $this->curseForgeFiles($projectId, $versionId, $type),
            'modrinth' => $this->modrinthFiles($server, $versionId, $type),
            default => [],
        };

        return array_map(function (array $file) {
            if (!empty($file['url'])) {
                $file['url'] = $this->resolveRedirects($file['url']);
            }

            return $file;
        }, $files);
    }

    private function searchModrinth(Server $server, string $type, ?string $query, int $limit, ?string $gameVersion = null): array
    {
        if (!config('pterodactyl.modpacks.modrinth.enabled', true)) {
            return [];
        }

        $loader = $this->loaderFor($server);
        $facets = [["project_type:$type"]];
        if ($loader) {
            $facets[] = ["categories:$loader"];
        }
        if ($gameVersion) {
            $facets[] = ["versions:$gameVersion"];
        }

        $response = $this->http->get('https://api.modrinth.com/v2/search', [
            'query' => [
                'query' => $query,
                'limit' => $limit,
                'facets' => json_encode($facets),
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return array_map(fn (array $hit) => [
            'id' => $hit['project_id'],
            'slug' => $hit['slug'],
            'name' => $hit['title'],
            'description' => $hit['description'],
            'icon' => $hit['icon_url'] ?? null,
            'url' => sprintf('https://modrinth.com/%s/%s', $type, $hit['slug']),
        ], $data['hits'] ?? []);
    }

    private function modrinthVersions(Server $server, string $projectId, string $type, ?string $gameVersion = null): array
    {
        $loader = $this->loaderFor($server);
        $query = ['include_changelog' => 'false'];
        if ($loader && $type === 'mod') {
            $query['loaders'] = json_encode([$loader]);
        }
        if ($gameVersion) {
            $query['game_versions'] = json_encode([$gameVersion]);
        }

        $response = $this->http->get(sprintf('https://api.modrinth.com/v2/project/%s/version', $projectId), [
            'query' => $query,
        ]);

        $versions = json_decode($response->getBody()->getContents(), true);

        if ($gameVersion) {
            $versions = array_values(array_filter($versions, fn (array $version) => in_array($gameVersion, $version['game_versions'] ?? [], true)));
        }

        return array_map(fn (array $version) => [
            'id' => $version['id'],
            'name' => $version['name'],
            'version' => $version['version_number'],
            'game_versions' => $version['game_versions'] ?? [],
            'loaders' => $version['loaders'] ?? [],
        ], $versions);
    }

    private function modrinthFiles(Server $server, string $versionId, string $type, array $seen = []): array
    {
        if (isset($seen[$versionId])) {
            return [];
        }

        $seen[$versionId] = true;
        $version = $this->getModrinthVersion($versionId);
        $files = $this->modrinthFileList($version, $type);

        foreach ($version['dependencies'] ?? [] as $dependency) {
            if (($dependency['dependency_type'] ?? null) !== 'required') {
                continue;
            }

            $dependencyVersionId = $dependency['version_id'] ?? null;
            if (!$dependencyVersionId && isset($dependency['project_id'])) {
                $versions = $this->modrinthVersions($server, $dependency['project_id'], 'mod');
                $dependencyVersionId = $versions[0]['id'] ?? null;
            }

            if ($dependencyVersionId) {
                $files = array_merge($files, $this->modrinthFiles($server, $dependencyVersionId, 'mod', $seen));
            }
        }

        return $files;
    }

    private function getModrinthVersion(string $versionId): array
    {
        $response = $this->http->get(sprintf('https://api.modrinth.com/v2/version/%s', $versionId));

        return json_decode($response->getBody()->getContents(), true);
    }

    private function modrinthFileList(array $version, string $type): array
    {
        $primary = collect($version['files'] ?? [])->firstWhere('primary', true) ?? ($version['files'][0] ?? null);
        if (!$primary) {
            return [];
        }

        if ($type === 'modpack') {
            return $this->expandModrinthPack($primary['url']);
        }

        return [[
            'url' => $primary['url'],
            'filename' => $primary['filename'],
            'directory' => '/mods',
        ]];
    }

    private function searchCurseForge(Server $server, string $type, ?string $query, int $limit, ?string $gameVersion = null): array
    {
        if (!config('pterodactyl.modpacks.curseforge.enabled', true) || !config('pterodactyl.modpacks.curseforge.api_key')) {
            return [];
        }

        $searchQuery = [
            'gameId' => self::MINECRAFT_GAME_ID,
            'classId' => $type === 'mod' ? 6 : 4471,
            'searchFilter' => $query,
            'pageSize' => $limit,
            'sortField' => 2,
            'sortOrder' => 'desc',
        ];
        if ($gameVersion) {
            $searchQuery['gameVersion'] = $gameVersion;
        }

        $response = $this->http->get('https://api.curseforge.com/v1/mods/search', [
            'headers' => $this->curseForgeHeaders(),
            'query' => $searchQuery,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return array_map(fn (array $mod) => [
            'id' => (string) $mod['id'],
            'slug' => $mod['slug'],
            'name' => $mod['name'],
            'description' => $mod['summary'] ?? '',
            'icon' => $mod['logo']['thumbnailUrl'] ?? null,
            'url' => $mod['links']['websiteUrl'] ?? null,
        ], $data['data'] ?? []);
    }

    private function curseForgeVersions(string $projectId, string $type = 'modpack', ?string $gameVersion = null): array
    {
        $query = ['pageSize' => 50];
        if ($gameVersion) {
            $query['gameVersion'] = $gameVersion;
        }

        $response = $this->http->get(sprintf('https://api.curseforge.com/v1/mods/%s/files', $projectId), [
            'headers' => $this->curseForgeHeaders(),
            'query' => $query,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        $files = $data['data'] ?? [];
        if ($type === 'modpack') {
            $clientFiles = array_values(array_filter($files, fn (array $file) => !($file['isServerPack'] ?? false)));
            if (!empty($clientFiles)) {
                $files = $clientFiles;
            }
        }
        if ($gameVersion) {
            $files = array_values(array_filter($files, fn (array $file) => in_array($gameVersion, $file['gameVersions'] ?? [], true)));
        }

        return array_map(fn (array $file) => [
            'id' => (string) $file['id'],
            'name' => $file['displayName'] ?? $file['fileName'],
            'version' => $file['fileName'],
            'game_versions' => $file['gameVersions'] ?? [],
            'loaders' => [],
            'server_pack_file_id' => empty($file['serverPackFileId']) ? null : (string) $file['serverPackFileId'],
            'is_server_pack' => (bool) ($file['isServerPack'] ?? false),
        ], $files);
    }

    private function curseForgeFiles(string $projectId, string $fileId, string $type, array $seen = []): array
    {
        $seenKey = $projectId . ':' . $fileId;
        if (isset($seen[$seenKey])) {
            return [];
        }

        $seen[$seenKey] = true;
        $file = $this->curseForgeFile($projectId, $fileId);
        if ($type === 'modpack') {
            if (!($file['isServerPack'] ?? false) && !empty($file['serverPackFileId'])) {
                $serverPack = $this->curseForgeFile($projectId, (string) $file['serverPackFileId']);
                if (!empty($serverPack['downloadUrl'])) {
                    return $this->curseForgeServerPackArchive($serverPack);
                }
            }

            if (($file['isServerPack'] ?? false) && !empty($file['downloadUrl'])) {
                return $this->curseForgeServerPackArchive($file);
            }

            $this->ensureCurseForgePackDownloadable($projectId, $file);

            return $this->expandCurseForgePack($file['downloadUrl']);
        }

        $files = [$this->curseForgeFileEntry($file, $type, $projectId)];

        foreach ($file['dependencies'] ?? [] as $dependency) {
            if ((int) ($dependency['relationType'] ?? 0) !== 3) {
                continue;
            }

            $versions = $this->curseForgeVersions((string) $dependency['modId'], 'mod');
            if (isset($versions[0])) {
                $files = array_merge($files, $this->curseForgeFiles((string) $dependency['modId'], $versions[0]['id'], 'mod', $seen));
            }
        }

        return array_values(array_filter($files));
    }

    private function curseForgeFile(string $projectId, string $fileId): array
    {
        $response = $this->http->get(sprintf('https://api.curseforge.com/v1/mods/%s/files/%s', $projectId, $fileId), [
            'headers' => $this->curseForgeHeaders(),
        ]);

        return json_decode($response->getBody()->getContents(), true)['data'] ?? [];
    }

    private function curseForgeMod(string $projectId): array
    {
        if (isset($this->curseForgeModCache[$projectId])) {
            return $this->curseForgeModCache[$projectId];
        }

        $response = $this->http->get(sprintf('https://api.curseforge.com/v1/mods/%s', $projectId), [
            'headers' => $this->curseForgeHeaders(),
        ]);

        return $this->curseForgeModCache[$projectId] = json_decode($response->getBody()->getContents(), true)['data'] ?? [];
    }

    private function curseForgeDownloadUrl(string $projectId, string $fileId): ?string
    {
        try {
            $response = $this->http->get(sprintf('https://api.curseforge.com/v1/mods/%s/files/%s/download-url', $projectId, $fileId), [
                'headers' => $this->curseForgeHeaders(),
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $url = json_decode($response->getBody()->getContents(), true)['data'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureCurseForgePackDownloadable(string $projectId, array &$file): void
    {
        if (!empty($file['downloadUrl'])) {
            return;
        }

        $fileId = (string) ($file['id'] ?? '');
        if ($fileId !== '') {
            $downloadUrl = $this->curseForgeDownloadUrl($projectId, $fileId);
            if ($downloadUrl) {
                $file['downloadUrl'] = $downloadUrl;

                return;
            }
        }

        $mod = $this->curseForgeMod($projectId);
        $name = $mod['name'] ?? $file['displayName'] ?? $file['fileName'] ?? sprintf('CurseForge project %s', $projectId);
        $fileName = $file['displayName'] ?? $file['fileName'] ?? ($fileId ? sprintf('file %s', $fileId) : 'selected file');

        if (($mod['allowModDistribution'] ?? null) === false) {
            throw new \RuntimeException(sprintf(
                '%s (%s) cannot be downloaded automatically because the author disabled third-party distribution on CurseForge. Download it manually from CurseForge or choose a modpack version with a server pack.',
                $name,
                $fileName
            ));
        }

        throw new \RuntimeException(sprintf(
            '%s (%s) does not provide a downloadable CurseForge URL for third-party installers. Download it manually from CurseForge or choose another version.',
            $name,
            $fileName
        ));
    }

    private function curseForgeFileEntry(array $file, string $type, string $projectId = ''): ?array
    {
        if (empty($file['downloadUrl'])) {
            return $projectId ? $this->curseForgeManualFileEntry($projectId, $file) : null;
        }

        return [
            'url' => $file['downloadUrl'],
            'filename' => $file['fileName'],
            'directory' => '/mods',
        ];
    }

    private function curseForgeManualFileEntry(string $projectId, array $file): array
    {
        $mod = $this->curseForgeMod($projectId);
        $fileId = (string) ($file['id'] ?? '');
        $projectUrl = rtrim((string) ($mod['links']['websiteUrl'] ?? sprintf('https://www.curseforge.com/minecraft/mc-mods/%s', $mod['slug'] ?? $projectId)), '/');

        return [
            'manual' => true,
            'url' => $fileId ? $projectUrl . '/files/' . $fileId : $projectUrl,
            'filename' => $file['fileName'] ?? sprintf('%s.jar', $fileId ?: $projectId),
            'directory' => '/mods',
            'project' => $mod['name'] ?? sprintf('CurseForge project %s', $projectId),
            'reason' => (($mod['allowModDistribution'] ?? null) === false)
                ? 'The author disabled third-party distribution on CurseForge.'
                : 'CurseForge did not provide a third-party download URL for this file.',
        ];
    }

    private function curseForgeHeaders(): array
    {
        return ['x-api-key' => config('pterodactyl.modpacks.curseforge.api_key')];
    }

    private function curseForgeServerPackArchive(array $file): array
    {
        $filename = sprintf('.pterodactyl-serverpack-%s.zip', $file['id'] ?? uniqid());

        return [[
            'url' => $file['downloadUrl'],
            'filename' => $filename,
            'directory' => '/',
            'archive' => true,
            'files' => [],
        ]];
    }

    private function resolveRedirects(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'stream' => true,
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => 10,
                    'track_redirects' => true,
                ],
            ]);

            $history = $response->getHeader('X-Guzzle-Redirect-History');
            $response->getBody()->close();
            $resolved = end($history);
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return $url;
    }

    private function expandModrinthPack(string $url): array
    {
        return $this->expandPack($url, 'modrinth.index.json', 'overrides', function (array $manifest) {
            return array_map(function (array $file) {
                $path = $this->normalizePackPath($file['path'] ?? '');
                if (!$path || empty($file['downloads'][0])) {
                    return null;
                }

                return [
                    'url' => $file['downloads'][0],
                    'filename' => basename($path),
                    'directory' => '/' . trim(dirname($path), '.\\/'),
                ];
            }, $manifest['files'] ?? []);
        });
    }

    private function expandCurseForgePack(string $url): array
    {
        return $this->expandPack($url, 'manifest.json', null, function (array $manifest, \ZipArchive $zip) {
            $files = [];

            foreach ($manifest['files'] ?? [] as $file) {
                if (isset($file['required']) && !$file['required']) {
                    continue;
                }

                $projectId = (string) ($file['projectID'] ?? '');
                $fileId = (string) ($file['fileID'] ?? '');
                if (!$projectId || !$fileId) {
                    continue;
                }

                $remote = $this->curseForgeFile($projectId, $fileId);
                $entry = $this->curseForgeFileEntry($remote, 'mod', $projectId);
                if ($entry) {
                    $files[] = $entry;
                }
            }

            $overrides = trim($manifest['overrides'] ?? 'overrides', '/');

            return array_merge($files, $this->zipOverrides($zip, $overrides));
        });
    }

    private function expandPack(string $url, string $manifestPath, ?string $overridesPath, callable $files): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ptero-pack-');

        try {
            $this->http->get($url, ['sink' => $path]);

            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }

            $manifest = json_decode($zip->getFromName($manifestPath) ?: '[]', true) ?: [];
            $resolved = array_values(array_filter($files($manifest, $zip)));

            if ($overridesPath) {
                $resolved = array_merge($resolved, $this->zipOverrides($zip, $overridesPath));
            }

            $zip->close();

            return $resolved;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function zipOverrides(\ZipArchive $zip, string $overridesPath): array
    {
        $files = [];
        $prefix = trim($overridesPath, '/') . '/';

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (!$name || str_ends_with($name, '/') || !str_starts_with($name, $prefix)) {
                continue;
            }

            $path = $this->normalizePackPath(substr($name, strlen($prefix)));
            if (!$path) {
                continue;
            }

            $content = $zip->getFromIndex($index);
            if ($content === false) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'content' => $content,
            ];
        }

        return $files;
    }

    private function withZip(string $url, callable $callback): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ptero-pack-');

        try {
            $this->http->get($url, ['sink' => $path]);

            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }

            $result = $callback($zip);
            $zip->close();

            return is_array($result) ? $result : [];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function normalizePackPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (!$path || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
