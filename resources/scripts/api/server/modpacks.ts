import http from '@/api/http';

export type ModpackProvider = 'modrinth' | 'curseforge';
export type ModpackType = 'mod' | 'modpack';

export interface ModpackMeta {
    loader: string | null;
    curseforgeEnabled: boolean;
    modrinthEnabled: boolean;
    defaultPageSize: number;
}

export interface ModpackProject {
    id: string;
    slug: string;
    name: string;
    description: string;
    icon: string | null;
    url: string | null;
}

export interface ModpackVersion {
    id: string;
    name: string;
    version: string;
    gameVersions: string[];
    loaders: string[];
    serverPackFileId: string | null;
    isServerPack: boolean;
}

export interface InstalledModpack {
    provider: ModpackProvider;
    type: ModpackType;
    project_id: string;
    version_id: string;
    name: string;
    loader: string | null;
    files: string[];
}

export interface ModpackManualDownload {
    project: string;
    filename: string;
    directory: string;
    target: string;
    url: string | null;
    reason: string;
}

export interface ModpackInstallStatus {
    stage: 'idle' | 'resolving' | 'installing' | 'manual' | 'complete' | 'error' | 'cancelled';
    message: string;
    current: number;
    total: number;
    percent: number;
    file: string | null;
    manualDownloads: ModpackManualDownload[];
    details: string[];
}

const rawMeta = (meta: any): ModpackMeta => ({
    loader: meta?.loader || null,
    curseforgeEnabled: meta?.curseforge_enabled || false,
    modrinthEnabled: meta?.modrinth_enabled || false,
    defaultPageSize: meta?.default_page_size || 50,
});

const rawVersion = (version: any): ModpackVersion => ({
    id: `${version.id}`,
    name: version.name,
    version: version.version,
    gameVersions: version.game_versions || [],
    loaders: version.loaders || [],
    serverPackFileId: version.server_pack_file_id ? `${version.server_pack_file_id}` : null,
    isServerPack: version.is_server_pack || false,
});

export const searchModpacks = (
    uuid: string,
    provider: ModpackProvider,
    type: ModpackType,
    query: string,
    limit: number,
    gameVersion?: string
): Promise<{ projects: ModpackProject[]; meta: ModpackMeta }> =>
    http
        .get(`/api/client/servers/${uuid}/modpacks`, { params: { provider, type, query, limit, game_version: gameVersion || undefined } })
        .then(({ data }) => ({
            projects: data.data || [],
            meta: rawMeta(data.meta),
        }));

export const getModpackVersions = (
    uuid: string,
    provider: ModpackProvider,
    projectId: string,
    type: ModpackType,
    gameVersion?: string
): Promise<ModpackVersion[]> =>
    http
        .get(`/api/client/servers/${uuid}/modpacks/${provider}/${projectId}/versions`, { params: { type, game_version: gameVersion || undefined } })
        .then(({ data }) => (data.data || []).map(rawVersion));

export const installModpack = (
    uuid: string,
    provider: ModpackProvider,
    type: ModpackType,
    project: ModpackProject,
    version: ModpackVersion,
    replace = false
): Promise<InstalledModpack | null> =>
    http
        .post(`/api/client/servers/${uuid}/modpacks/install`, {
            provider,
            type,
            project_id: project.id,
            version_id: version.id,
            name: project.name,
            replace,
        }, { timeout: 900000 })
        .then(({ data }) => data.data);

export const getModpackInstallStatus = (uuid: string): Promise<ModpackInstallStatus> =>
    http.get(`/api/client/servers/${uuid}/modpacks/install-status`).then(({ data }) => ({
        stage: data.data?.stage || 'idle',
        message: data.data?.message || '',
        current: data.data?.current || 0,
        total: data.data?.total || 0,
        percent: data.data?.percent || 0,
        file: data.data?.file || null,
        manualDownloads: data.data?.manual_downloads || [],
        details: data.data?.details || [],
    }));

export const cancelModpackInstall = (uuid: string): Promise<void> =>
    http.post(`/api/client/servers/${uuid}/modpacks/install/cancel`).then(() => undefined);

export const getInstalledModpacks = (uuid: string): Promise<{ installed: InstalledModpack[]; meta: ModpackMeta }> =>
    http.get(`/api/client/servers/${uuid}/modpacks/installed`).then(({ data }) => ({
        installed: data.data || [],
        meta: rawMeta(data.meta),
    }));

export const deleteInstalledModpack = (uuid: string, item: InstalledModpack): Promise<void> =>
    http
        .delete(`/api/client/servers/${uuid}/modpacks/installed`, {
            data: {
                provider: item.provider,
                type: item.type,
                project_id: item.project_id,
            },
        })
        .then(() => undefined);
