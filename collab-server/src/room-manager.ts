import { TLSocketRoom } from '@tldraw/sync-core';
import { NcRoomStorage, createNcRoomStorage } from './nc-storage.js';

interface RoomEntry {
	room: TLSocketRoom<any, any>;
	ncStorage: NcRoomStorage;
	saveInterval: NodeJS.Timeout;
	userId: string;
	filePath: string;
}

const rooms = new Map<string, RoomEntry>();

export async function makeOrLoadRoom(
	roomToken: string,
	fileId: string,
	userId: string,
	filePath: string // Relative path in user's Nextcloud, e.g. "Documents/drawing.tldr"
): Promise<TLSocketRoom<any, any>> {
	const existing = rooms.get(roomToken);
	if (existing) {
		return existing.room;
	}

	// Create new storage backed by Nextcloud WebDAV
	const ncStorage = await createNcRoomStorage(userId, filePath);

	const room = new TLSocketRoom<any, any>({
		storage: ncStorage.storage,
		// When the last session leaves, we save and close
		onSessionRemoved: async (_room, args) => {
			if (args.numSessionsRemaining === 0) {
				const entry = rooms.get(roomToken);
				if (!entry) return;

				clearInterval(entry.saveInterval);
				await ncStorage.flush(userId, filePath);
				ncStorage.close();
				room.close();
				rooms.delete(roomToken);
				console.log(`Room closed: ${roomToken}`);
			}
		},
	});

	// Auto-save every 30 seconds
	const saveInterval = setInterval(() => {
		ncStorage.flush(userId, filePath).catch((err) => {
			console.error(`Auto-save failed for room ${roomToken}:`, err);
		});
	}, 30_000);

	rooms.set(roomToken, { room, ncStorage, saveInterval, userId, filePath });
	console.log(`Room created: ${roomToken} for file ${fileId}`);

	return room;
}
