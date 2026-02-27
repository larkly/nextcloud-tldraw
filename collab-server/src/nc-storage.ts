/**
 * Nextcloud tldraw
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 the nextcloud-tldraw contributors
 */
import { DatabaseSync } from 'node:sqlite';
import { NodeSqliteWrapper, SQLiteSyncStorage } from '@tldraw/sync-core';

// The Nextcloud base URL is the only Nextcloud configuration the collab server needs.
// No credentials — all file I/O goes through PHP callback endpoints authenticated
// by the storage token issued by TldrawController::token().
const NC_URL = (process.env.NC_URL || '').replace(/\/$/, '');

export interface NcRoomStorage {
	storage: SQLiteSyncStorage<any>;
	flush(fileId: string, storageToken: string): Promise<void>;
	close(): void;
}

/**
 * Call a Nextcloud file callback endpoint.
 * All endpoints use Bearer <storageToken> for authentication.
 */
async function ncFetch(path: string, options: RequestInit, storageToken: string): Promise<Response> {
	const url = `${NC_URL}${path}`;
	const headers = new Headers(options.headers as HeadersInit || {});
	headers.set('Authorization', `Bearer ${storageToken}`);
	return fetch(url, { ...options, headers });
}

export async function createNcRoomStorage(
	fileId: string,
	storageToken: string
): Promise<NcRoomStorage> {
	const db = new DatabaseSync(':memory:');
	const storage = new SQLiteSyncStorage<any>({ sql: new NodeSqliteWrapper(db) });

	// Load existing drawing content from Nextcloud via the PHP read callback
	try {
		const res = await ncFetch(`/apps/tldraw/file/${fileId}`, { method: 'GET' }, storageToken);
		if (res.ok) {
			const content = await res.text();
			if (content && content.trim()) {
				const snapshot = JSON.parse(content);
				if (typeof (storage as any).loadSnapshot === 'function') {
					await (storage as any).loadSnapshot(snapshot);
				}
			}
		}
	} catch (e) {
		console.error('Error loading file from Nextcloud:', e);
		// Start with an empty room if load fails or file is new
	}

	async function flush(fid: string, token: string) {
		try {
			const snapshot = await (storage as any).getSnapshot();
			if (!snapshot) return;

			const res = await ncFetch(`/apps/tldraw/file/${fid}`, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(snapshot),
			}, token);

			if (!res.ok) {
				console.error(`Flush failed: HTTP ${res.status}`);
			}
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

/**
 * Upload an image asset to Nextcloud via the PHP asset callback.
 * Returns the asset URL (served by Nextcloud, not the collab server).
 */
export async function uploadAsset(
	fileId: string,
	data: Buffer,
	mimeType: string,
	storageToken: string
): Promise<string> {
	const res = await ncFetch(`/apps/tldraw/file/${fileId}/asset`, {
		method: 'POST',
		headers: { 'Content-Type': mimeType },
		body: data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) as ArrayBuffer,
	}, storageToken);

	if (!res.ok) {
		throw new Error(`Asset upload failed: HTTP ${res.status}`);
	}

	const { assetKey } = await res.json() as { assetKey: string };
	// Assets are now served directly from Nextcloud — no proxy on the collab server
	return `${NC_URL}/apps/tldraw/asset/${encodeURIComponent(assetKey)}`;
}
