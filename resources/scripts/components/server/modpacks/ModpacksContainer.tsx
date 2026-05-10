import React, { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import { Actions, useStoreActions } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Select from '@/components/elements/Select';
import Input from '@/components/elements/Input';
import Button from '@/components/elements/Button';
import Modal from '@/components/elements/Modal';
import Spinner from '@/components/elements/Spinner';
import { httpErrorToHuman } from '@/api/http';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { SocketEvent } from '@/components/server/events';
import {
    cancelModpackInstall,
    deleteInstalledModpack,
    getInstalledModpacks,
    getModpackInstallStatus,
    getModpackVersions,
    installModpack,
    InstalledModpack,
    ModpackInstallStatus,
    ModpackManualDownload,
    ModpackProject,
    ModpackProvider,
    ModpackType,
    ModpackVersion,
    searchModpacks,
} from '@/api/server/modpacks';

const isActiveInstallStatus = (status: ModpackInstallStatus) => status.stage === 'resolving' || status.stage === 'installing';
const installedKey = (provider: ModpackProvider, type: ModpackType, projectId: string) => `${provider}:${type}:${projectId}`;

const stageTitle = (status: ModpackInstallStatus) => {
    switch (status.stage) {
        case 'resolving':
            return 'Preparing install';
        case 'installing':
            return 'Installing files';
        case 'manual':
            return 'Manual files required';
        case 'complete':
            return 'Install complete';
        case 'cancelled':
            return 'Install cancelled';
        case 'error':
            return 'Install failed';
        default:
            return 'Install status';
    }
};

const manualDownloadLabel = (download: ModpackManualDownload) =>
    `${download.project || 'CurseForge file'} - ${download.filename || download.target}`;

const ModpacksContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addFlash, clearFlashes } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const [provider, setProvider] = useState<ModpackProvider>('modrinth');
    const [type, setType] = useState<ModpackType>('modpack');
    const [query, setQuery] = useState('');
    const [gameVersion, setGameVersion] = useState('');
    const [limit, setLimit] = useState(50);
    const [loader, setLoader] = useState<string | null>(null);
    const [projects, setProjects] = useState<ModpackProject[]>([]);
    const [installed, setInstalled] = useState<InstalledModpack[]>([]);
    const [selectedProject, setSelectedProject] = useState<ModpackProject | null>(null);
    const [selectedInstalled, setSelectedInstalled] = useState<InstalledModpack | null>(null);
    const [versions, setVersions] = useState<ModpackVersion[]>([]);
    const [selectedVersion, setSelectedVersion] = useState('');
    const [loading, setLoading] = useState(false);
    const [modalLoading, setModalLoading] = useState(false);
    const [installing, setInstalling] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [progressModalOpen, setProgressModalOpen] = useState(false);
    const [removing, setRemoving] = useState<string | null>(null);
    const [installStatus, setInstallStatus] = useState<ModpackInstallStatus>({
        stage: 'idle',
        message: '',
        current: 0,
        total: 0,
        percent: 0,
        file: null,
        manualDownloads: [],
        details: [],
    });
    const completedInstallRef = useRef(false);
    const activeInstallNameRef = useRef<string | null>(null);
    const activeInstallReplaceRef = useRef(false);
    const statusRequestInFlightRef = useRef(false);

    const loadInstalled = () =>
        getInstalledModpacks(uuid)
            .then(({ installed, meta }) => {
                setInstalled(installed);
                setLoader(meta.loader);
                setLimit((current) => current || meta.defaultPageSize);
                if (!meta.curseforgeEnabled && provider === 'curseforge') {
                    setProvider('modrinth');
                }

                return meta;
            })
            .catch((error) => addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) }));

    const search = (overrides?: Partial<{ provider: ModpackProvider; type: ModpackType; limit: number; query: string; gameVersion: string }>) => {
        const nextProvider = overrides?.provider || provider;
        const nextType = overrides?.type || type;
        const nextLimit = overrides?.limit || limit;
        const nextQuery = typeof overrides?.query === 'string' ? overrides.query : query;
        const nextGameVersion = typeof overrides?.gameVersion === 'string' ? overrides.gameVersion : gameVersion;

        clearFlashes('modpacks');
        setLoading(true);
        searchModpacks(uuid, nextProvider, nextType, nextQuery, nextLimit, nextGameVersion)
            .then(({ projects, meta }) => {
                setProjects(projects);
                setLoader(meta.loader);
            })
            .catch((error) => addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) }))
            .then(() => setLoading(false));
    };

    const findInstalled = (itemProvider: ModpackProvider, itemType: ModpackType, projectId: string) =>
        installed.find((item) => installedKey(item.provider, item.type, item.project_id) === installedKey(itemProvider, itemType, projectId)) || null;

    const openInstallModal = (
        project: ModpackProject,
        existing: InstalledModpack | null = null,
        nextProvider: ModpackProvider = provider,
        nextType: ModpackType = type
    ) => {
        setProvider(nextProvider);
        setType(nextType);
        setSelectedProject(project);
        setSelectedInstalled(existing || findInstalled(nextProvider, nextType, project.id));
        setVersions([]);
        setSelectedVersion('');
        setModalLoading(true);
        getModpackVersions(uuid, nextProvider, project.id, nextType, gameVersion)
            .then((versions) => {
                setVersions(versions);
                setSelectedVersion(versions[0]?.id || '');
            })
            .catch((error) => addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) }))
            .then(() => setModalLoading(false));
    };

    const install = () => {
        const version = versions.find((version) => version.id === selectedVersion);
        if (!selectedProject || !version) return;

        clearFlashes('modpacks');
        if (isActiveInstallStatus(installStatus)) {
            setInstalling(true);
            addFlash({
                key: 'modpacks',
                type: 'warning',
                message: 'An installation is already running on this server. Wait for it to finish before starting another one.',
            });
            return;
        }

        completedInstallRef.current = false;
        activeInstallNameRef.current = selectedProject.name;
        setInstallStatus({
            stage: 'resolving',
            message: 'Resolving modpack files...',
            current: 0,
            total: 0,
            percent: 5,
            file: null,
            manualDownloads: [],
            details: [
                'Resolving provider metadata and selecting a compatible file.',
                'If a CurseForge server pack exists, it will be downloaded as a zip archive.',
                'Wings extracts that archive on the node; large packs can stay on this step for several minutes.',
                'After extraction the addon scans the server files, writes the manifest, and removes the temporary archive.',
            ],
        });
        setInstalling(true);
        const replacing = !!selectedInstalled;
        activeInstallReplaceRef.current = replacing;
        installModpack(uuid, provider, type, selectedProject, version, replacing)
            .then((result) => {
                if (!result) {
                    return getModpackInstallStatus(uuid).then((status) => {
                        setInstallStatus(status);
                        if (status.stage === 'cancelled') {
                            activeInstallNameRef.current = null;
                            activeInstallReplaceRef.current = false;
                            setSelectedInstalled(null);
                            setInstalling(false);
                            setCancelling(false);
                            setProgressModalOpen(false);
                            addFlash({
                                key: 'modpacks',
                                type: 'warning',
                                message: status.message || 'The modpack installation was cancelled.',
                            });
                        }

                        if (status.stage === 'manual') {
                            activeInstallNameRef.current = null;
                            activeInstallReplaceRef.current = false;
                            setInstalling(false);
                            setCancelling(false);
                            setProgressModalOpen(false);
                            addFlash({
                                key: 'modpacks',
                                type: 'warning',
                                message:
                                    status.message ||
                                    'Some CurseForge files must be downloaded manually. Upload them to the listed paths and run the install again.',
                            });
                        }
                    });
                }

                completedInstallRef.current = true;
                setInstallStatus((status) => ({
                    ...status,
                    stage: 'complete',
                    message:
                        status.total > 0
                            ? `${replacing ? 'Updated' : 'Installed'} ${status.total} files.`
                            : `${selectedProject.name} has been ${replacing ? 'updated' : 'installed'}.`,
                    current: status.total,
                    percent: 100,
                }));
                addFlash({
                    key: 'modpacks',
                    type: 'success',
                    message: `${selectedProject.name} has been ${replacing ? 'updated' : 'installed'}.`,
                });
                setSelectedProject(null);
                setSelectedInstalled(null);
                activeInstallReplaceRef.current = false;
                setInstalling(false);
                setProgressModalOpen(false);
                return loadInstalled();
            })
            .catch((error) =>
                getModpackInstallStatus(uuid)
                    .then((status) => {
                        setInstallStatus(status);
                        const isConflict = error?.response?.status === 409;

                        if (isActiveInstallStatus(status)) {
                            if (isConflict) {
                                activeInstallNameRef.current = null;
                                activeInstallReplaceRef.current = false;
                                setSelectedProject(null);
                                setSelectedInstalled(null);
                            }

                            addFlash({
                                key: 'modpacks',
                                type: 'warning',
                                message:
                                    isConflict
                                        ? 'Another installation is already running on this server. Progress will continue to update here.'
                                        : 'The install request timed out, but the server is still installing files. Progress will continue to update here.',
                            });
                            return;
                        }

                        if (status.stage === 'complete') {
                            completedInstallRef.current = true;
                            const name = isConflict ? null : activeInstallNameRef.current;
                            addFlash({
                                key: 'modpacks',
                                type: 'success',
                                message: name
                                    ? `${name} has been ${replacing ? 'updated' : 'installed'}.`
                                    : 'The modpack installation has completed.',
                            });
                            setSelectedProject(null);
                            setSelectedInstalled(null);
                            activeInstallReplaceRef.current = false;
                            setInstalling(false);
                            setProgressModalOpen(false);
                            return loadInstalled();
                        }

                        if (status.stage === 'cancelled') {
                            activeInstallNameRef.current = null;
                            activeInstallReplaceRef.current = false;
                            setSelectedInstalled(null);
                            setInstalling(false);
                            setCancelling(false);
                            setProgressModalOpen(false);
                            addFlash({
                                key: 'modpacks',
                                type: 'warning',
                                message: status.message || 'The modpack installation was cancelled.',
                            });

                            return;
                        }

                        if (status.stage === 'manual') {
                            activeInstallNameRef.current = null;
                            activeInstallReplaceRef.current = false;
                            setInstalling(false);
                            setCancelling(false);
                            setProgressModalOpen(false);
                            addFlash({
                                key: 'modpacks',
                                type: 'warning',
                                message:
                                    status.message ||
                                    'Some CurseForge files must be downloaded manually. Upload them to the listed paths and run the install again.',
                            });

                            return;
                        }

                        activeInstallNameRef.current = null;
                        activeInstallReplaceRef.current = false;
                        setSelectedInstalled(null);
                        setInstalling(false);
                        addFlash({ key: 'modpacks', type: 'error', message: status.message || httpErrorToHuman(error) });
                    })
                    .catch(() => {
                        activeInstallNameRef.current = null;
                        activeInstallReplaceRef.current = false;
                        setSelectedInstalled(null);
                        setInstalling(false);
                        addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) });
                    })
            );
    };

    const remove = (item: InstalledModpack) => {
        const key = `${item.provider}:${item.type}:${item.project_id}`;
        if (removing) return;

        setRemoving(key);
        clearFlashes('modpacks');
        deleteInstalledModpack(uuid, item)
            .then(() => {
                addFlash({ key: 'modpacks', type: 'success', message: `${item.name} has been removed.` });
                return loadInstalled();
            })
            .catch((error) => addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) }))
            .then(() => setRemoving(null));
    };

    const cancelInstall = () => {
        setCancelling(true);
        clearFlashes('modpacks');
        cancelModpackInstall(uuid)
            .then(() => {
                setInstallStatus((status) => ({
                    ...status,
                    message: 'Cancelling installation after the current file finishes...',
                }));
                addFlash({
                    key: 'modpacks',
                    type: 'warning',
                    message: 'Cancellation requested. The current file operation may need to finish first.',
                });
            })
            .catch((error) => addFlash({ key: 'modpacks', type: 'error', message: httpErrorToHuman(error) }))
            .then(() => setCancelling(false));
    };

    const handleInstallStatus = (status: ModpackInstallStatus) => {
        setInstallStatus(status);

        if (isActiveInstallStatus(status)) {
            return;
        }

        if (status.stage === 'complete') {
            setInstalling(false);
            setCancelling(false);
            setSelectedProject(null);
            setSelectedInstalled(null);
            setProgressModalOpen(false);

            if (!completedInstallRef.current) {
                completedInstallRef.current = true;
                const name = activeInstallNameRef.current;
                const replacing = activeInstallReplaceRef.current;
                activeInstallNameRef.current = null;
                activeInstallReplaceRef.current = false;
                addFlash({
                    key: 'modpacks',
                    type: 'success',
                    message: name
                        ? `${name} has been ${replacing ? 'updated' : 'installed'}.`
                        : 'The modpack installation has completed.',
                });
                loadInstalled();
            }

            return;
        }

        if (status.stage === 'error') {
            activeInstallNameRef.current = null;
            activeInstallReplaceRef.current = false;
            setSelectedInstalled(null);
            setInstalling(false);
            setCancelling(false);
            setProgressModalOpen(false);
            addFlash({
                key: 'modpacks',
                type: 'error',
                message: status.message || 'The modpack installation failed.',
            });
        }

        if (status.stage === 'cancelled') {
            activeInstallNameRef.current = null;
            activeInstallReplaceRef.current = false;
            setSelectedInstalled(null);
            setInstalling(false);
            setCancelling(false);
            setProgressModalOpen(false);
            addFlash({
                key: 'modpacks',
                type: 'warning',
                message: status.message || 'The modpack installation was cancelled.',
            });
        }

        if (status.stage === 'manual') {
            activeInstallNameRef.current = null;
            activeInstallReplaceRef.current = false;
            setInstalling(false);
            setCancelling(false);
            setProgressModalOpen(false);
            addFlash({
                key: 'modpacks',
                type: 'warning',
                message:
                    status.message ||
                    'Some CurseForge files must be downloaded manually. Upload them to the listed paths and run the install again.',
            });
        }
    };

    const refreshInstallStatus = () => {
        if (!installing && installStatus.stage !== 'manual') {
            return;
        }

        if (statusRequestInFlightRef.current) {
            return;
        }

        statusRequestInFlightRef.current = true;
        getModpackInstallStatus(uuid)
            .then(handleInstallStatus)
            .catch(() => undefined)
            .then(() => {
                statusRequestInFlightRef.current = false;
            });
    };

    useWebsocketEvent(SocketEvent.INSTALL_COMPLETED, refreshInstallStatus);

    useEffect(() => {
        clearFlashes('modpacks');
        loadInstalled().then((meta) => search({ limit: meta?.defaultPageSize || limit }));
        getModpackInstallStatus(uuid)
            .then((status) => {
                setInstallStatus(status);
                setInstalling(isActiveInstallStatus(status));
                completedInstallRef.current = status.stage === 'complete';
                activeInstallNameRef.current = null;
                activeInstallReplaceRef.current = false;
                setSelectedInstalled(null);
            })
            .catch(() => undefined);
    }, [uuid]);

    useEffect(() => {
        const timeout = setTimeout(() => search({ query }), 450);

        return () => clearTimeout(timeout);
    }, [query]);

    useEffect(() => {
        const timeout = setTimeout(() => search({ gameVersion }), 450);

        return () => clearTimeout(timeout);
    }, [gameVersion]);

    const manualDownloads = installStatus.manualDownloads || [];
    const showInstallStatus = installing || installStatus.stage === 'manual';
    const topInstallControls = installing && !selectedProject && (
        <div css={tw`mt-3 flex flex-wrap justify-end gap-2`}>
            <Button size={'xsmall'} color={'grey'} onClick={() => setProgressModalOpen(true)}>
                Open progress
            </Button>
            <Button size={'xsmall'} color={'red'} disabled={cancelling} isLoading={cancelling} onClick={cancelInstall}>
                Stop install
            </Button>
        </div>
    );
    const installProgress = showInstallStatus && (
        <div css={tw`mt-4 rounded bg-neutral-800 p-4`}>
            <div css={tw`mb-3 flex items-center justify-between gap-4`}>
                <div css={tw`min-w-0`}>
                    <p css={tw`text-sm font-semibold text-neutral-100`}>{stageTitle(installStatus)}</p>
                    <p css={tw`mt-1 text-sm text-neutral-300`}>
                        {installStatus.message || 'Downloading and installing files on the server.'}
                    </p>
                </div>
                <p css={tw`flex-shrink-0 text-sm font-semibold text-neutral-100`}>{installStatus.percent}%</p>
            </div>
            <div css={tw`mb-2 h-4 overflow-hidden rounded bg-neutral-900`}>
                <div
                    css={tw`h-full rounded bg-primary-400 transition-all duration-500`}
                    style={{ width: `${Math.max(5, Math.min(100, installStatus.percent))}%` }}
                />
            </div>
            <p css={tw`text-xs text-neutral-300`}>
                {installStatus.total > 0
                    ? `${installStatus.current} / ${installStatus.total} files completed`
                    : 'Resolving the file list...'}
                {installStatus.file ? ` - ${installStatus.file}` : ''}
            </p>
            {manualDownloads.length > 0 && (
                <div css={tw`mt-4 rounded bg-neutral-900 p-3`}>
                    <p css={tw`text-sm font-semibold text-neutral-100`}>
                        Upload these files, then click {selectedInstalled ? 'Update' : 'Install'} again.
                    </p>
                    <div css={tw`mt-3 space-y-3`}>
                        {manualDownloads.map((download) => (
                            <div key={`${download.target}:${download.url}`} css={tw`rounded bg-neutral-800 p-3`}>
                                <div css={tw`flex flex-wrap items-center justify-between gap-2`}>
                                    <p css={tw`text-sm text-neutral-100`}>{manualDownloadLabel(download)}</p>
                                    {download.url && (
                                        <a
                                            href={download.url}
                                            target={'_blank'}
                                            rel={'noreferrer'}
                                            css={tw`text-xs text-primary-300`}
                                        >
                                            Open CurseForge
                                        </a>
                                    )}
                                </div>
                                <p css={tw`mt-1 text-xs text-neutral-300`}>{download.reason}</p>
                                <p css={tw`mt-2 text-xs text-neutral-200`}>
                                    Upload to: <span css={tw`font-mono`}>/{download.target}</span>
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {installStatus.details.length > 0 && (
                <div css={tw`mt-4 rounded bg-neutral-900 p-3`}>
                    <p css={tw`text-sm font-semibold text-neutral-100`}>What is happening</p>
                    <ul css={tw`mt-2 space-y-1 text-xs text-neutral-300`}>
                        {installStatus.details.map((detail) => (
                            <li key={detail}>{detail}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );

    return (
        <ServerContentBlock title={'Modpacks'} showFlashKey={'modpacks'}>
            {!loader && (
                <div css={tw`mb-4 rounded bg-neutral-700 p-4 text-sm text-neutral-200`}>
                    This server does not look like a Forge, NeoForge, or Fabric egg. Search is still available, but
                    installation will be blocked until a supported loader can be detected.
                </div>
            )}

            {showInstallStatus && !selectedProject && (
                <div css={tw`mb-4`}>
                    {installProgress}
                    {topInstallControls}
                </div>
            )}

            <div css={tw`grid gap-4 md:grid-cols-6 mb-4`}>
                <div>
                    <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Provider</p>
                    <Select
                        value={provider}
                        onChange={(event) => {
                            const value = event.currentTarget.value as ModpackProvider;
                            setProvider(value);
                            search({ provider: value });
                        }}
                    >
                        <option value={'modrinth'}>Modrinth</option>
                        <option value={'curseforge'}>CurseForge</option>
                    </Select>
                </div>
                <div>
                    <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Type</p>
                    <Select
                        value={type}
                        onChange={(event) => {
                            const value = event.currentTarget.value as ModpackType;
                            setType(value);
                            search({ type: value });
                        }}
                    >
                        <option value={'modpack'}>Modpacks</option>
                        <option value={'mod'}>Mods</option>
                    </Select>
                </div>
                <div>
                    <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Page Size</p>
                    <Select
                        value={limit}
                        onChange={(event) => {
                            const value = Number(event.currentTarget.value);
                            setLimit(value);
                            search({ limit: value });
                        }}
                    >
                        {[10, 25, 50, 100].map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Minecraft Version</p>
                    <Input
                        value={gameVersion}
                        placeholder={'1.20.1'}
                        onChange={(event) => setGameVersion(event.currentTarget.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                search({ gameVersion });
                            }
                        }}
                    />
                </div>
                <div css={tw`md:col-span-2`}>
                    <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Search Query</p>
                    <div css={tw`flex gap-2`}>
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.currentTarget.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    search({ query });
                                }
                            }}
                        />
                        <Button onClick={search} isLoading={loading}>
                            Search
                        </Button>
                    </div>
                </div>
            </div>

            {installed.length > 0 && (
                <div css={tw`mb-6 rounded bg-neutral-700 p-4`}>
                    <p css={tw`mb-3 text-sm uppercase text-neutral-100`}>Installed</p>
                    <div css={tw`space-y-2`}>
                        {installed.map((item) => (
                            <div key={`${item.provider}:${item.type}:${item.project_id}`} css={tw`flex items-center justify-between gap-3 rounded bg-neutral-800 p-3`}>
                                <div>
                                    <p css={tw`text-sm text-neutral-100`}>{item.name}</p>
                                    <p css={tw`text-xs text-neutral-300`}>
                                        {item.provider} / {item.type} / {item.files.length} files
                                    </p>
                                </div>
                                <div css={tw`flex items-center gap-2`}>
                                    <Button
                                        size={'xsmall'}
                                        disabled={installing || !!removing}
                                        onClick={() =>
                                            openInstallModal(
                                                {
                                                    id: item.project_id,
                                                    slug: item.project_id,
                                                    name: item.name,
                                                    description: '',
                                                    icon: null,
                                                    url: null,
                                                },
                                                item,
                                                item.provider,
                                                item.type
                                            )
                                        }
                                    >
                                        Update
                                    </Button>
                                    <Button
                                        color={'red'}
                                        size={'xsmall'}
                                        disabled={!!removing || installing}
                                        isLoading={removing === `${item.provider}:${item.type}:${item.project_id}`}
                                        onClick={() => remove(item)}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div css={tw`space-y-2`}>
                {loading ? (
                    <Spinner centered />
                ) : (
                    projects.map((project) => {
                        const existing = findInstalled(provider, type, project.id);

                        return (
                            <div key={project.id} css={tw`flex items-center gap-4 rounded bg-neutral-700 p-4`}>
                                {project.icon && <img src={project.icon} css={tw`h-12 w-12 rounded object-cover bg-neutral-600`} />}
                                <div css={tw`min-w-0 flex-1`}>
                                    <div css={tw`flex items-center gap-2`}>
                                        <p css={tw`truncate text-sm text-neutral-100`}>{project.name}</p>
                                        {project.url && (
                                            <a href={project.url} target={'_blank'} rel={'noreferrer'} css={tw`text-xs text-primary-300`}>
                                                Open
                                            </a>
                                        )}
                                    </div>
                                    <p css={tw`truncate text-sm text-neutral-300`}>{project.description}</p>
                                </div>
                                <Button size={'xsmall'} disabled={installing} onClick={() => openInstallModal(project, existing)}>
                                    {existing ? 'Update' : 'Install'}
                                </Button>
                            </div>
                        );
                    })
                )}
            </div>

            <Modal
                visible={!!selectedProject}
                onDismissed={() => {
                    if (!installing) {
                        setSelectedProject(null);
                        setSelectedInstalled(null);
                    }
                }}
                showSpinnerOverlay={modalLoading}
            >
                <h2 css={tw`mb-2 text-2xl text-neutral-100`}>
                    {selectedInstalled ? 'Update' : 'Install'} {type}
                </h2>
                <p css={tw`mb-4 text-sm text-neutral-300`}>
                    Select the version of {selectedProject?.name} to {selectedInstalled ? 'install as an update' : 'install'} on this{' '}
                    {loader || 'Minecraft'} server.
                </p>
                <p css={tw`mb-1 text-xs uppercase text-neutral-300`}>Version</p>
                <Select
                    value={selectedVersion}
                    disabled={installing}
                    onChange={(event) => setSelectedVersion(event.currentTarget.value)}
                >
                    {versions.map((version) => (
                        <option key={version.id} value={version.id}>
                            {version.name} {version.gameVersions.length > 0 ? `(${version.gameVersions.join(', ')})` : ''}
                            {provider === 'curseforge' && type === 'modpack' && version.serverPackFileId ? ' - server pack available' : ''}
                        </option>
                    ))}
                </Select>
                {provider === 'curseforge' && type === 'modpack' && versions.find((version) => version.id === selectedVersion)?.serverPackFileId && (
                    <p css={tw`mt-3 rounded bg-neutral-800 p-3 text-sm text-neutral-200`}>
                        Server files are available for this version. The CurseForge server pack will be installed automatically.
                    </p>
                )}
                <p css={tw`mt-4 text-sm text-neutral-300`}>
                    A manifest will be written to .pterodactyl-modpacks/manifest.json so removal only deletes files
                    installed by this tool.
                </p>
                {installProgress}
                <div css={tw`mt-6 flex justify-end gap-3`}>
                    {installing ? (
                        <Button color={'grey'} disabled={cancelling} isLoading={cancelling} onClick={cancelInstall}>
                            Stop install
                        </Button>
                    ) : (
                        <Button
                            color={'grey'}
                            onClick={() => {
                                setSelectedProject(null);
                                setSelectedInstalled(null);
                            }}
                        >
                            Cancel
                        </Button>
                    )}
                    <Button color={'red'} disabled={!selectedVersion || installing} isLoading={installing} onClick={install}>
                        {selectedInstalled ? 'Update' : 'Install'} {type}
                    </Button>
                </div>
            </Modal>
            <Modal visible={progressModalOpen && !selectedProject} onDismissed={() => setProgressModalOpen(false)}>
                <h2 css={tw`mb-2 text-2xl text-neutral-100`}>Install progress</h2>
                <p css={tw`mb-4 text-sm text-neutral-300`}>
                    The install continues on the server even if this window is closed.
                </p>
                {installProgress}
                <div css={tw`mt-6 flex justify-end gap-3`}>
                    <Button color={'grey'} onClick={() => setProgressModalOpen(false)}>
                        Close
                    </Button>
                    {installing && (
                        <Button color={'red'} disabled={cancelling} isLoading={cancelling} onClick={cancelInstall}>
                            Stop install
                        </Button>
                    )}
                </div>
            </Modal>
        </ServerContentBlock>
    );
};

export default ModpacksContainer;
