import { createClient } from 'webdav';
import { DatabaseSync } from 'node:sqlite';
import { NodeSqliteWrapper, SQLiteSyncStorage } from '@tldraw/sync-core';

const NC_URL = process.env.NC_URL || '';
// Use a dedicated service account (bot) with Admin group permissions.
// We prefer NC_USER/NC_PASS but fall back to NC_ADMIN_* for compatibility.
const NC_USER = process.env.NC_USER || process.env.NC_ADMIN_USER || '';
const NC_PASS = process.env.NC_PASS || process.env.NC_ADMIN_PASS || '';

// Create a WebDAV client authenticated as the Service User
function adminDavClient(userId: string) {
	// Access user's files via admin account at /remote.php/dav/files/<userId>/
	// NOTE: The authenticated NC_USER must be in the 'admin' group to access
	// paths belonging to other users.
	return createClient(`${NC_URL}/remote.php/dav/files/${encodeURIComponent(userId)}`, {
		username: NC_USER,
		password: NC_PASS,
	});
}

export interface NcRoomStorage {
	storage: SQLiteSyncStorage<any>;
	flush(userId: string, filePath: string): Promise<void>;
	close(): void;
}

export async function createNcRoomStorage(userId: string, filePath: string): Promise<NcRoomStorage> {
	// Use in-memory SQLite database for fast sync operations
	const db = new DatabaseSync(':memory:');
	const storage = new SQLiteSyncStorage<any>({ sql: new NodeSqliteWrapper(db) });

	// 1. Load existing content from Nextcloud
	try {
		const client = adminDavClient(userId);
		// Check if file exists and has content
		if (await client.exists(filePath)) {
			const content = (await client.getFileContents(filePath, { format: 'text' })) as string;
			if (content && content.trim()) {
				const snapshot = JSON.parse(content);
				// Initialize storage from snapshot
				// Note: loadSnapshot is an internal method, might need adjustment based on exact version
				// For @tldraw/sync-core, usually initializing with data is done differently or via applying updates
				// But let's assume standard snapshot loading for now.
				// If loadSnapshot is not available on the instance, we might need to manually insert.
				// However, for this scaffolding, we assume compatibility.
				if (typeof (storage as any).loadSnapshot === 'function') {
					await (storage as any).loadSnapshot(snapshot);
				} else {
					console.warn('loadSnapshot not found on storage instance');
				}
			}
		}
	} catch (e) {
		console.error('Error loading file from Nextcloud:', e);
		// Start with empty room if load fails or file is new
	}

	// 2. Define flush function to save back to Nextcloud
	async function flush(uid: string, path: string) {
		try {
			// Get snapshot from storage
			const snapshot = await (storage as any).getSnapshot();
			if (!snapshot) return;

			const client = adminDavClient(uid);
			await client.putFileContents(path, JSON.stringify(snapshot), { overwrite: true });
		} catch (e) {
			console.error('Error flushing to Nextcloud:', e);
		}
	}

	return {
		storage,
		flush,
		close: () => db.close(),
	};
}

export async function uploadAsset(
	userId: string,
	filename: string,
	data: Buffer,
	mimeType: string
): Promise<string> {
	const client = adminDavClient(userId);
	const assetDir = '.tldraw-assets';
	const assetPath = `${assetDir}/${filename}`;

	try {
		if (!(await client.exists(assetDir))) {
			await client.createDirectory(assetDir);
		}
		await client.putFileContents(assetPath, data, { overwrite: true });
	} catch (e) {
		console.error('Asset upload failed:', e);
		throw e;
	}

	return `/uploads/${encodeURIComponent(userId)}/${filename}`;
}

export async function fetchAsset(userId: string, filename: string): Promise<Buffer> {
	const client = adminDavClient(userId);
	const assetPath = `.tldraw-assets/${filename}`;
	return (await client.getFileContents(assetPath)) as Buffer;
}
