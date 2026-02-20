import { TLSocketRoom } from '@tldraw/sync-core';
import { NcRoomStorage, createNcRoomStorage } from './nc-storage.js';

interface RoomEntry {
	room: TLSocketRoom<any, any>;
	ncStorage: NcRoomStorage;
	saveInterval: NodeJS.Timeout;
	fileId: string;
	storageToken: string;
}

const rooms = new Map<string, RoomEntry>();

export async function makeOrLoadRoom(
	roomToken: string,
	fileId: string,
	storageToken: string
): Promise<TLSocketRoom<any, any>> {
	const existing = rooms.get(roomToken);
	if (existing) {
		return existing.room;
	}

	// Load room state from Nextcloud via PHP callback (no WebDAV / no admin credentials)
	const ncStorage = await createNcRoomStorage(fileId, storageToken);

	const room = new TLSocketRoom<any, any>({
		storage: ncStorage.storage,
		onSessionRemoved: async (_room, args) => {
			if (args.numSessionsRemaining === 0) {
				const entry = rooms.get(roomToken);
				if (!entry) return;

				clearInterval(entry.saveInterval);
				await ncStorage.flush(fileId, storageToken);
				ncStorage.close();
				room.close();
				rooms.delete(roomToken);
				console.log(`Room closed: ${roomToken}`);
			}
		},
	});

	// Auto-save every 30 seconds
	const saveInterval = setInterval(() => {
		ncStorage.flush(fileId, storageToken).catch((err) => {
			console.error(`Auto-save failed for room ${roomToken}:`, err);
		});
	}, 30_000);

	rooms.set(roomToken, { room, ncStorage, saveInterval, fileId, storageToken });
	console.log(`Room created: ${roomToken} for file ${fileId}`);

	return room;
}
