import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import { mutate } from 'swr';
import { getDirectorySwrKey } from '@/plugins/useFileManagerSwr';
import { useLocation } from 'react-router';

const InstallListener = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const location = useLocation();
    const getServer = ServerContext.useStoreActions((actions) => actions.server.getServer);
    const setServerFromState = ServerContext.useStoreActions((actions) => actions.server.setServerFromState);
    const isModpacksPage = location.pathname.includes('/modpacks');

    useWebsocketEvent(SocketEvent.BACKUP_RESTORE_COMPLETED, () => {
        mutate(getDirectorySwrKey(uuid, '/'), undefined);
        setServerFromState((s) => ({ ...s, status: null }));
    });

    // Listen for the installation completion event and then fire off a request to fetch the updated
    // server information. This allows the server to automatically become available to the user if they
    // just sit on the page.
    useWebsocketEvent(SocketEvent.INSTALL_COMPLETED, () => {
        if (isModpacksPage) {
            return;
        }

        getServer(uuid).catch((error) => console.error(error));
    });

    // When we see the install started event immediately update the state to indicate such so that the
    // screens automatically update.
    useWebsocketEvent(SocketEvent.INSTALL_STARTED, () => {
        if (isModpacksPage) {
            return;
        }

        setServerFromState((s) => ({ ...s, status: 'installing' }));
    });

    return null;
};

export default InstallListener;
