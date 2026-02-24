import express from 'express';
import { WebSocket, WebSocketServer } from 'ws';
import { createServer, IncomingMessage } from 'http';
import cors from 'cors';
import crypto from 'crypto';
import multer from 'multer';
import { rateLimit } from 'express-rate-limit';
import { makeOrLoadRoom } from './room-manager.js';
import { uploadAsset } from './nc-storage.js';

const PORT = process.env.PORT || 3000;
const JWT_SECRET = process.env.JWT_SECRET_KEY;
const NC_URL = process.env.NC_URL;

// Per-IP WebSocket connection tracking (upgrade events bypass express-rate-limit)
const wsConnectionCounts = new Map<string, number>();
const MAX_WS_CONNECTIONS_PER_IP = 10;

function getClientIp(req: IncomingMessage): string {
	const forwarded = req.headers['x-forwarded-for'];
	if (forwarded) {
		// Use the rightmost entry: Traefik appends the real client IP to the end of the
		// chain. The leftmost entries are client-supplied and trivially spoofable.
		const chain = Array.isArray(forwarded) ? forwarded[0] : forwarded;
		return chain.split(',').at(-1)!.trim();
	}
	return req.socket.remoteAddress ?? 'unknown';
}

if (!JWT_SECRET) {
	console.error('FATAL: JWT_SECRET_KEY is not set.');
	process.exit(1);
}

const app = express();
app.use(cors());
app.use(express.json());

// Rate Limiting: 1000 requests per 15 minutes
const limiter = rateLimit({
	windowMs: 15 * 60 * 1000,
	max: 1000,
	standardHeaders: true,
	legacyHeaders: false,
});
app.use(limiter);

// --- Asset Uploads ---
const upload = multer({ limits: { fileSize: 50 * 1024 * 1024 } }); // 50MB limit

function verifyJwt(token: string): any {
	try {
		const [headerB64, payloadB64, signatureB64] = token.split('.');
		if (!headerB64 || !payloadB64 || !signatureB64) return null;

		const signatureInput = `${headerB64}.${payloadB64}`;
		const signature = crypto
			.createHmac('sha256', JWT_SECRET!)
			.update(signatureInput)
			.digest('base64url');

		if (signature !== signatureB64) return null;

		const payload = JSON.parse(Buffer.from(payloadB64, 'base64url').toString());
		if (payload.exp && Date.now() / 1000 > payload.exp) return null;

		return payload;
	} catch (e) {
		return null;
	}
}

// SVG is intentionally excluded: text-based format bypasses magic byte validation
// and requires a full XML sanitiser to be safe. Reject server-side, document client-side.
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

// Basic magic byte check
function validateMagicBytes(buffer: Buffer, mimetype: string): boolean {
    if (mimetype === 'image/png') {
        return buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4E && buffer[3] === 0x47;
    }
    if (mimetype === 'image/jpeg') {
        return buffer[0] === 0xFF && buffer[1] === 0xD8 && buffer[2] === 0xFF;
    }
    if (mimetype === 'image/gif') {
        return buffer[0] === 0x47 && buffer[1] === 0x49 && buffer[2] === 0x46 && buffer[3] === 0x38;
    }
    if (mimetype === 'image/webp') {
        // RIFF....WEBP
        return buffer[0] === 0x52 && buffer[1] === 0x49 && buffer[2] === 0x46 && buffer[3] === 0x46 &&
               buffer[8] === 0x57 && buffer[9] === 0x45 && buffer[10] === 0x42 && buffer[11] === 0x50;
    }
    return false;
}

app.post('/uploads', upload.single('file'), async (req, res) => {
	const authHeader = req.headers.authorization;
	if (!authHeader || !authHeader.startsWith('Bearer ')) {
		return res.status(401).json({ error: 'Missing token' });
	}
	const token = authHeader.split(' ')[1];
	const payload = verifyJwt(token);
	if (!payload) return res.status(403).json({ error: 'Invalid token' });

	if (!req.file) return res.status(400).json({ error: 'No file' });

    if (!ALLOWED_MIMES.includes(req.file.mimetype)) {
        return res.status(400).json({ error: 'Invalid file type' });
    }

    if (!validateMagicBytes(req.file.buffer, req.file.mimetype)) {
        return res.status(400).json({ error: 'File content does not match extension' });
    }

	// storageToken and fileId are forwarded from the WebSocket JWT the browser obtained
	const { storageToken, fileId } = payload;
	if (!storageToken || !fileId) {
		return res.status(403).json({ error: 'Token missing storage context' });
	}

	try {
        // Strip everything outside [a-zA-Z0-9._-] to prevent path traversal via originalname
        const safeOriginalName = req.file.originalname.replace(/[^a-zA-Z0-9._-]/g, '_');
		const filename = `${crypto.randomUUID()}-${safeOriginalName}`;
		// uploadAsset POSTs to the PHP callback and returns a Nextcloud-hosted URL
		const url = await uploadAsset(fileId, req.file.buffer, req.file.mimetype, storageToken);
		res.json({ url });
	} catch (e) {
        console.error(e);
		res.status(500).json({ error: 'Upload failed' });
	}
});

app.get('/health', (req, res) => res.json({ status: 'ok' }));

const server = createServer(app);
const wss = new WebSocketServer({ noServer: true });

// Wrapper to enforce read-only mode on a WebSocket
function makeReadOnlySocket(ws: WebSocket) {
	// We only need to proxy the 'on' method to filter incoming messages
	return new Proxy(ws, {
		get(target, prop) {
			if (prop === 'on') {
				return (event: string, listener: (...args: any[]) => void) => {
					if (event === 'message') {
						target.on('message', (data: any, isBinary: boolean) => {
							try {
								const msg = JSON.parse(data.toString());
								// Block 'update' messages which contain changes
								if (msg.type === 'update') {
                                    // console.log('Blocked write operation from read-only client');
									return;
								}
							} catch (e) {
								// Ignore parse errors, let the room handle or drop it
							}
							listener(data, isBinary);
						});
					} else {
						target.on(event, listener);
					}
				};
			}
			return (target as any)[prop];
		},
	});
}

server.on('upgrade', (req, socket, head) => {
	// Per-IP connection limit — checked first so JWT work is skipped for flooding IPs
	const clientIp = getClientIp(req);
	const currentCount = wsConnectionCounts.get(clientIp) ?? 0;
	if (currentCount >= MAX_WS_CONNECTIONS_PER_IP) {
		socket.write('HTTP/1.1 429 Too Many Requests\r\n\r\n');
		socket.destroy();
		return;
	}

	const url = new URL(req.url || '', 'http://localhost');
	const token = url.searchParams.get('token');

	if (!token) {
		socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
		socket.destroy();
		return;
	}

	const payload = verifyJwt(token);
	if (!payload) {
		socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
		socket.destroy();
		return;
	}

    // Origin Check
    const origin = req.headers.origin;
    if (NC_URL && origin && origin !== new URL(NC_URL).origin) {
        console.warn(`Blocked origin: ${origin}, expected ${NC_URL}`);
        socket.write('HTTP/1.1 403 Forbidden (Origin)\r\n\r\n');
        socket.destroy();
        return;
    }

	wss.handleUpgrade(req, socket, head, (ws) => {
		wsConnectionCounts.set(clientIp, (wsConnectionCounts.get(clientIp) ?? 0) + 1);
		ws.once('close', () => {
			const updated = (wsConnectionCounts.get(clientIp) ?? 1) - 1;
			if (updated <= 0) {
				wsConnectionCounts.delete(clientIp);
			} else {
				wsConnectionCounts.set(clientIp, updated);
			}
		});
		wss.emit('connection', ws, req, payload);
	});
});

wss.on('connection', async (ws: WebSocket, req: IncomingMessage, payload: any) => {
	const { roomToken, fileId, storageToken, canWrite } = payload;

	if (!storageToken) {
		console.error('No storageToken in JWT payload — rejecting connection');
		ws.close();
		return;
	}

	try {
		const room = await makeOrLoadRoom(roomToken, String(fileId), storageToken);

        // Enforce Read-Only if the token doesn't have write permission
        const socket = canWrite ? ws : makeReadOnlySocket(ws);

		room.handleSocketConnect({ sessionId: crypto.randomUUID(), socket: socket });
	} catch (e) {
		console.error('Room error:', e);
		ws.close();
	}
});

server.listen(PORT, () => {
	console.log(`Collab Server running on port ${PORT}`);
});
