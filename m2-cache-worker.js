'use strict';

const WORKER_URL = new URL(self.location.href);
const ARCHIVE_VERSION = WORKER_URL.searchParams.get('v') || 'unversioned';
const PAGE_CODE_VERSION = WORKER_URL.searchParams.get('p') || 'unversioned';
const CACHE_PREFIX = 'm2-absolute-archive-';
const CACHE_NAME = CACHE_PREFIX + ARCHIVE_VERSION;
const SURVIVAL_CACHE_NAME = 'm2-survival-shell';
const FINGERPRINT_PROTOCOL = 1;
const SAMPLE_BYTES = 65536;
const CACHEABLE_DESTINATIONS = new Set(['audio', 'font', 'image', 'style', 'video']);
const inflightFetches = new Map();
const fingerprintMemo = new Map();

let archiveMetaLoaded = false;
let archiveComplete = false;
let archiveManifest = new Set();
let archiveDocuments = new Set();
let archiveAssets = [];

self.addEventListener('install', event => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

function metaRequest(version) {
    return new Request(
        new URL('/__m2_archive_meta__/' + encodeURIComponent(version), self.location.origin).href
    );
}

function reply(target, message) {
    if (target && typeof target.postMessage === 'function') target.postMessage(message);
}

function canonicalDocumentUrl(value) {
    const url = new URL(String(value), self.location.origin);
    url.hash = '';
    if (url.origin === self.location.origin &&
        (url.pathname === '/musik' || url.pathname === '/musik/')) {
        url.pathname = '/m/m2.php';
    }
    return url.href;
}

function isM2DocumentUrl(value) {
    const url = new URL(String(value), self.location.origin);
    return url.origin === self.location.origin &&
        (url.pathname === '/m/m2.php' || url.pathname === '/musik' || url.pathname === '/musik/');
}

function survivalDocumentRequest() {
    return new Request(new URL('/__m2_survival_shell__/document', self.location.origin).href);
}

function survivalMetaRequest() {
    return new Request(new URL('/__m2_survival_shell__/meta', self.location.origin).href);
}

async function readSurvivalMeta(cache) {
    const response = await cache.match(survivalMetaRequest());
    if (!response) return null;
    try {
        return await response.json();
    } catch (_) {
        return null;
    }
}

async function storeSurvivalDocument(response, sourceUrl) {
    if (!response || !response.ok) throw new Error('Survival document response is not successful');
    const contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
    if (contentType && !contentType.includes('text/html') && !contentType.includes('application/xhtml+xml')) {
        throw new Error('Survival document is not HTML');
    }
    const cache = await caches.open(SURVIVAL_CACHE_NAME);
    await cache.put(survivalDocumentRequest(), response.clone());
    await cache.put(survivalMetaRequest(), new Response(JSON.stringify({
        pageVersion: PAGE_CODE_VERSION,
        source: canonicalDocumentUrl(sourceUrl),
        storedAt: Date.now()
    }), {
        headers: {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control': 'no-store'
        }
    }));
}

async function readSurvivalDocument() {
    const cache = await caches.open(SURVIVAL_CACHE_NAME);
    return cache.match(survivalDocumentRequest(), { ignoreVary: true });
}

async function armSurvivalDocument(message, target) {
    const requestId = message.requestId;
    if (String(message.pageVersion) !== PAGE_CODE_VERSION) {
        throw new Error('Page code version ' + message.pageVersion + ' does not match worker page version ' + PAGE_CODE_VERSION);
    }
    if (!isM2DocumentUrl(message.documentUrl)) throw new Error('Survival document URL is not M2');
    const cache = await caches.open(SURVIVAL_CACHE_NAME);
    const stored = await cache.match(survivalDocumentRequest(), { ignoreVary: true });
    const meta = await readSurvivalMeta(cache);
    if (stored && String(meta?.pageVersion) === PAGE_CODE_VERSION) {
        reply(target, {
            type: 'M2_SURVIVAL_READY',
            requestId,
            pageVersion: PAGE_CODE_VERSION,
            reused: true
        });
        return;
    }
    const documentUrl = canonicalDocumentUrl(message.documentUrl);
    const response = await fetch(fullAssetRequest(documentUrl));
    if (!response.ok) throw new Error('Survival document HTTP ' + response.status);
    await storeSurvivalDocument(response, documentUrl);
    reply(target, {
        type: 'M2_SURVIVAL_READY',
        requestId,
        pageVersion: PAGE_CODE_VERSION,
        reused: false
    });
}

function normalizeDocuments(documents) {
    const urls = new Set();
    for (const value of Array.isArray(documents) ? documents : []) {
        try {
            const url = canonicalDocumentUrl(value);
            if (new URL(url).origin === self.location.origin) urls.add(url);
        } catch (_) {
        }
    }
    return [...urls];
}

function canonicalAssetKey(value) {
    const url = new URL(String(value), self.location.origin);
    url.hash = '';
    if (url.origin === self.location.origin) {
        url.searchParams.delete('v');
        return url.pathname + url.search;
    }
    return url.href;
}

function normalizedMime(url, rawMime = '') {
    const extension = new URL(url, self.location.origin).pathname
        .split('.').pop().toLowerCase();
    const types = {
        mp3: 'audio/mpeg',
        mp4: 'video/mp4',
        webm: 'video/webm',
        png: 'image/png',
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        gif: 'image/gif',
        svg: 'image/svg+xml',
        ico: 'image/x-icon',
        woff: 'font/woff',
        woff2: 'font/woff2',
        ttf: 'font/ttf',
        otf: 'font/otf',
        css: 'text/css',
        js: 'text/javascript'
    };
    if (types[extension]) return types[extension];
    return String(rawMime).split(';', 1)[0].trim().toLowerCase() || 'application/octet-stream';
}

function normalizeAssets(values) {
    const assets = new Map();
    for (const value of Array.isArray(values) ? values : []) {
        const entry = typeof value === 'string' ? { url: value } : value;
        if (!entry || typeof entry.url !== 'string') continue;
        try {
            const url = new URL(entry.url, self.location.origin);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') continue;
            if (url.href === self.location.href) continue;
            url.hash = '';
            const fingerprint = typeof entry.fingerprint === 'string' &&
                /^[a-f0-9]{64}$/i.test(entry.fingerprint)
                ? entry.fingerprint.toLowerCase()
                : null;
            const size = Number.isSafeInteger(Number(entry.size)) && Number(entry.size) >= 0
                ? Number(entry.size)
                : null;
            const sampleBytes = Number.isSafeInteger(Number(entry.sampleBytes)) && Number(entry.sampleBytes) > 0
                ? Number(entry.sampleBytes)
                : SAMPLE_BYTES;
            assets.set(url.href, {
                url: url.href,
                key: canonicalAssetKey(url.href),
                fingerprint,
                size,
                mime: entry.mime ? normalizedMime(url.href, entry.mime) : null,
                sampleBytes
            });
        } catch (_) {
        }
    }
    return [...assets.values()];
}

async function readStoredMeta(cache, version) {
    const response = await cache.match(metaRequest(version));
    if (!response) return null;
    try {
        return await response.json();
    } catch (_) {
        return null;
    }
}

async function readArchiveMeta(cache) {
    if (archiveMetaLoaded) {
        return {
            version: ARCHIVE_VERSION,
            complete: archiveComplete,
            urls: [...archiveManifest],
            documents: [...archiveDocuments],
            assets: archiveAssets
        };
    }
    archiveMetaLoaded = true;
    const meta = await readStoredMeta(cache, ARCHIVE_VERSION);
    if (!meta) return null;
    archiveComplete = meta.version === ARCHIVE_VERSION && meta.complete === true;
    archiveManifest = new Set(Array.isArray(meta.urls) ? meta.urls : []);
    archiveDocuments = new Set(Array.isArray(meta.documents) ? meta.documents : []);
    archiveAssets = normalizeAssets(meta.assets || meta.urls);
    return meta;
}

async function writeArchiveMeta(cache, meta) {
    archiveMetaLoaded = true;
    archiveComplete = meta.complete === true;
    archiveManifest = new Set(meta.urls || []);
    archiveDocuments = new Set(meta.documents || []);
    archiveAssets = normalizeAssets(meta.assets || meta.urls);
    await cache.put(metaRequest(ARCHIVE_VERSION), new Response(JSON.stringify(meta), {
        headers: {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control': 'no-store'
        }
    }));
}

function fullAssetRequest(url) {
    const parsed = new URL(url);
    const sameOrigin = parsed.origin === self.location.origin;
    return new Request(parsed.href, {
        method: 'GET',
        mode: sameOrigin ? 'same-origin' : 'no-cors',
        credentials: sameOrigin ? 'include' : 'omit',
        cache: sameOrigin ? 'reload' : 'no-store',
        redirect: 'follow'
    });
}

function toHex(buffer) {
    return [...new Uint8Array(buffer)].map(value => value.toString(16).padStart(2, '0')).join('');
}

async function sampledFingerprint(response, asset) {
    if (!asset.fingerprint || response.type === 'opaque') return null;
    const blob = await response.clone().blob();
    if (asset.size !== null && blob.size !== asset.size) return null;
    const mime = normalizedMime(asset.url, response.headers.get('Content-Type') || blob.type);
    if (asset.mime && mime !== asset.mime) return null;
    const sampleBytes = asset.sampleBytes || SAMPLE_BYTES;
    const firstLength = Math.min(sampleBytes, blob.size);
    const lastStart = Math.max(firstLength, blob.size - sampleBytes);
    const metadata = new TextEncoder().encode(JSON.stringify({
        protocol: FINGERPRINT_PROTOCOL,
        key: asset.key,
        mime,
        size: blob.size,
        sampleBytes
    }) + '\n');
    const first = new Uint8Array(await blob.slice(0, firstLength).arrayBuffer());
    const last = new Uint8Array(await blob.slice(lastStart).arrayBuffer());
    const material = new Uint8Array(metadata.length + first.length + last.length);
    material.set(metadata, 0);
    material.set(first, metadata.length);
    material.set(last, metadata.length + first.length);
    return toHex(await crypto.subtle.digest('SHA-256', material));
}

async function fetchAndStore(cache, url, asset = null) {
    if (inflightFetches.has(url)) return inflightFetches.get(url);
    const operation = (async () => {
        const request = fullAssetRequest(url);
        const response = await fetch(request);
        const acceptable = response.type === 'opaque' || response.ok;
        if (!acceptable) throw new Error('HTTP ' + response.status + ' for ' + url);
        if (response.status === 206) throw new Error('Refusing partial archive entry for ' + url);
        if (asset?.fingerprint) {
            const fingerprint = await sampledFingerprint(response, asset);
            if (fingerprint !== asset.fingerprint) {
                throw new Error('Fingerprint mismatch for ' + url);
            }
        }
        await cache.put(request, response);
        const stored = await cache.match(request, { ignoreVary: true });
        if (!stored) throw new Error('Browser did not retain ' + url);
        return stored;
    })().finally(() => inflightFetches.delete(url));
    inflightFetches.set(url, operation);
    return operation;
}

async function cacheOne(cache, url) {
    const existing = await cache.match(url, { ignoreVary: true });
    if (existing) return existing;
    return fetchAndStore(cache, url);
}

async function archiveSources(currentCache) {
    const names = (await caches.keys()).filter(name => name.startsWith(CACHE_PREFIX));
    names.sort((a, b) => Number(b === CACHE_NAME) - Number(a === CACHE_NAME));
    const sources = [];
    for (const name of names) {
        const version = name.slice(CACHE_PREFIX.length);
        const cache = name === CACHE_NAME ? currentCache : await caches.open(name);
        const meta = await readStoredMeta(cache, version);
        const assets = new Map(normalizeAssets(meta?.assets || meta?.urls)
            .map(asset => [asset.url, asset]));
        const requests = await cache.keys();
        for (const request of requests) {
            if (new URL(request.url).pathname.startsWith('/__m2_archive_meta__/')) continue;
            if (!assets.has(request.url)) {
                const asset = normalizeAssets([request.url])[0];
                if (asset) assets.set(asset.url, asset);
            }
        }
        const byKey = new Map();
        for (const asset of assets.values()) {
            if (!byKey.has(asset.key)) byKey.set(asset.key, []);
            byKey.get(asset.key).push(asset);
        }
        sources.push({ name, cache, byKey });
    }
    return sources;
}

async function reusableResponse(asset, sources) {
    for (const source of sources) {
        for (const candidate of source.byKey.get(asset.key) || []) {
            const response = await source.cache.match(candidate.url, { ignoreVary: true });
            if (!response) continue;
            if (!asset.fingerprint) return response;
            if (candidate.fingerprint) {
                if (candidate.fingerprint === asset.fingerprint) return response;
                continue;
            }
            if (response.type === 'opaque') continue;
            const memoKey = source.name + '\n' + candidate.url + '\n' + asset.fingerprint;
            let fingerprint = fingerprintMemo.get(memoKey);
            if (!fingerprint) {
                fingerprint = sampledFingerprint(response, asset);
                fingerprintMemo.set(memoKey, fingerprint);
            }
            if (await fingerprint === asset.fingerprint) return response;
        }
    }
    return null;
}

async function reusableDocument(url, sources) {
    for (const source of sources) {
        const direct = await source.cache.match(url, { ignoreVary: true });
        if (direct) return direct;
        const requests = await source.cache.keys();
        for (const request of requests) {
            if (new URL(request.url).pathname.startsWith('/__m2_archive_meta__/')) continue;
            if (canonicalDocumentUrl(request.url) !== url) continue;
            const response = await source.cache.match(request, { ignoreVary: true });
            if (response) return response;
        }
    }
    return null;
}

async function reconcileLocalArchive(cache, assets, documents, progress = null) {
    const sources = await archiveSources(cache);
    const missing = [];
    let completed = 0;
    let inspected = 0;
    const total = assets.length + documents.length;
    for (const asset of assets) {
        const local = await reusableResponse(asset, sources);
        if (local) {
            const request = fullAssetRequest(asset.url);
            if (!await cache.match(request, { ignoreVary: true })) {
                await cache.put(request, local.clone());
            }
            if (!await cache.match(request, { ignoreVary: true })) {
                throw new Error('Browser did not retain reconciled ' + asset.url);
            }
            completed++;
        } else {
            missing.push({ type: 'asset', asset, url: asset.url });
        }
        inspected++;
        if (progress) progress({ completed, inspected, total, current: asset.url });
    }
    for (const url of documents) {
        const local = await reusableDocument(url, sources);
        if (local) {
            if (!await cache.match(url, { ignoreVary: true })) {
                await cache.put(fullAssetRequest(url), local.clone());
            }
            if (!await cache.match(url, { ignoreVary: true })) {
                throw new Error('Browser did not retain reconciled ' + url);
            }
            completed++;
        } else {
            missing.push({ type: 'document', url });
        }
        inspected++;
        if (progress) progress({ completed, inspected, total, current: url });
    }
    return { completed, inspected, total, missing };
}

async function deleteSupersededArchives() {
    const names = await caches.keys();
    await Promise.all(names
        .filter(name => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
        .map(name => caches.delete(name)));
}

async function checkArchive(message, target) {
    const requestId = message.requestId;
    if (String(message.version) !== ARCHIVE_VERSION) {
        throw new Error('Page version ' + message.version + ' does not match worker version ' + ARCHIVE_VERSION);
    }
    const cache = await caches.open(CACHE_NAME);
    const requestedAssets = normalizeAssets(message.assets);
    const requestedUrls = requestedAssets.map(asset => asset.url);
    const requestedDocuments = normalizeDocuments(message.documents);
    const local = await reconcileLocalArchive(cache, requestedAssets, requestedDocuments, status => {
        reply(target, {
            type: 'M2_ARCHIVE_CHECK_PROGRESS',
            requestId,
            version: ARCHIVE_VERSION,
            ...status
        });
    });
    const complete = local.missing.length === 0;
    await writeArchiveMeta(cache, {
        version: ARCHIVE_VERSION,
        protocol: FINGERPRINT_PROTOCOL,
        complete,
        urls: [...new Set([...requestedUrls, ...requestedDocuments])],
        documents: requestedDocuments,
        assets: requestedAssets,
        completed: local.completed,
        failures: []
    });
    if (complete) {
        await deleteSupersededArchives();
        reply(target, {
            type: 'M2_ARCHIVE_COMPLETE',
            requestId,
            version: ARCHIVE_VERSION,
            total: local.total,
            alreadyInstalled: true,
            reused: local.completed,
            fetched: 0
        });
        return;
    }
    reply(target, {
        type: 'M2_ARCHIVE_RECONCILE_REQUIRED',
        requestId,
        version: ARCHIVE_VERSION,
        completed: local.completed,
        total: local.total,
        reused: local.completed,
        missing: local.missing.length,
        fetched: 0
    });
}

async function installArchive(message, target) {
    const requestId = message.requestId;
    if (String(message.version) !== ARCHIVE_VERSION) {
        throw new Error('Page version ' + message.version + ' does not match worker version ' + ARCHIVE_VERSION);
    }
    const assets = normalizeAssets(message.assets);
    const documents = normalizeDocuments(message.documents);
    const cache = await caches.open(CACHE_NAME);
    const urls = [...new Set([...assets.map(asset => asset.url), ...documents])];
    const concurrency = Math.max(1, Math.min(6, Math.trunc(Number(message.concurrency)) || 3));
    const local = await reconcileLocalArchive(cache, assets, documents);
    const tasks = local.missing;
    let cursor = 0;
    let completed = local.completed;
    let reused = local.completed;
    let fetched = 0;
    const failures = [];
    await writeArchiveMeta(cache, {
        version: ARCHIVE_VERSION,
        protocol: FINGERPRINT_PROTOCOL,
        complete: false,
        urls,
        documents,
        assets,
        completed,
        failures: []
    });

    reply(target, {
        type: 'M2_ARCHIVE_PROGRESS',
        requestId,
        version: ARCHIVE_VERSION,
        completed,
        total: local.total,
        current: null,
        reused,
        fetched,
        failures: 0
    });

    async function workerLane() {
        while (cursor < tasks.length) {
            const task = tasks[cursor++];
            try {
                if (task.type === 'asset') {
                    await fetchAndStore(cache, task.url, task.asset);
                    fetched++;
                } else {
                    await fetchAndStore(cache, task.url);
                    fetched++;
                }
                completed++;
            } catch (error) {
                failures.push({ url: task.url, error: String(error?.message || error) });
            }
            reply(target, {
                type: 'M2_ARCHIVE_PROGRESS',
                requestId,
                version: ARCHIVE_VERSION,
                completed,
                total: local.total,
                current: task.url,
                reused,
                fetched,
                failures: failures.length
            });
        }
    }

    await Promise.all(Array.from(
        { length: Math.min(concurrency, Math.max(1, tasks.length)) },
        () => workerLane()
    ));
    if (failures.length > 0) {
        await writeArchiveMeta(cache, {
            version: ARCHIVE_VERSION,
            protocol: FINGERPRINT_PROTOCOL,
            complete: false,
            urls,
            documents,
            assets,
            completed,
            failures
        });
        throw new Error(failures.length + ' of ' + tasks.length + ' archive resources failed; installation will resume');
    }
    await writeArchiveMeta(cache, {
        version: ARCHIVE_VERSION,
        protocol: FINGERPRINT_PROTOCOL,
        complete: true,
        urls,
        documents,
        assets,
        completed: local.total,
        failures: []
    });
    await deleteSupersededArchives();
    reply(target, {
        type: 'M2_ARCHIVE_COMPLETE',
        requestId,
        version: ARCHIVE_VERSION,
        total: local.total,
        alreadyInstalled: false,
        reused,
        fetched
    });
}

self.addEventListener('message', event => {
    const message = event.data;
    if (!message) return;
    if (message.type === 'M2_SURVIVAL_ARM') {
        event.waitUntil(armSurvivalDocument(message, event.source).catch(error => {
            reply(event.source, {
                type: 'M2_SURVIVAL_ERROR',
                requestId: message.requestId,
                pageVersion: PAGE_CODE_VERSION,
                error: String(error?.message || error)
            });
        }));
        return;
    }
    const operation = message.type === 'M2_ARCHIVE_CHECK'
        ? checkArchive(message, event.source)
        : message.type === 'M2_ARCHIVE_INSTALL'
            ? installArchive(message, event.source)
            : null;
    if (!operation) return;
    event.waitUntil(operation.catch(error => {
        reply(event.source, {
            type: 'M2_ARCHIVE_ERROR',
            requestId: message.requestId,
            version: ARCHIVE_VERSION,
            error: String(error?.message || error)
        });
    }));
});

function rangeNotSatisfiable(size) {
    return new Response(null, {
        status: 416,
        headers: {
            'Content-Range': 'bytes */' + size,
            'Accept-Ranges': 'bytes'
        }
    });
}

async function rangedResponse(response, rangeHeader) {
    if (!rangeHeader || response.type === 'opaque' || response.status !== 200) return response;
    const match = /^bytes=(\d*)-(\d*)$/i.exec(rangeHeader.trim());
    if (!match) return rangeNotSatisfiable(Number(response.headers.get('Content-Length')) || 0);
    const blob = await response.blob();
    const size = blob.size;
    let start;
    let end;
    if (match[1] === '') {
        const suffixLength = Number(match[2]);
        if (!Number.isFinite(suffixLength) || suffixLength <= 0) return rangeNotSatisfiable(size);
        start = Math.max(0, size - suffixLength);
        end = size - 1;
    } else {
        start = Number(match[1]);
        end = match[2] === '' ? size - 1 : Number(match[2]);
    }
    if (!Number.isInteger(start) || !Number.isInteger(end) || start < 0 || start >= size || end < start) {
        return rangeNotSatisfiable(size);
    }
    end = Math.min(end, size - 1);
    const body = blob.slice(start, end + 1, response.headers.get('Content-Type') || undefined);
    const headers = new Headers();
    headers.set('Accept-Ranges', 'bytes');
    headers.set('Content-Range', 'bytes ' + start + '-' + end + '/' + size);
    headers.set('Content-Length', String(end - start + 1));
    const contentType = response.headers.get('Content-Type');
    if (contentType) headers.set('Content-Type', contentType);
    const cacheControl = response.headers.get('Cache-Control');
    if (cacheControl) headers.set('Cache-Control', cacheControl);
    return new Response(body, { status: 206, statusText: 'Partial Content', headers });
}

function lockedCacheMiss(url) {
    return new Response(
        'M2 archive ' + ARCHIVE_VERSION + ' is complete; network fallback is locked for ' + url,
        {
            status: 504,
            statusText: 'M2 Archive Miss',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        }
    );
}

function canonicalVersionedMediaUrl(request) {
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return null;
    const isMedia = request.destination === 'audio' ||
        request.destination === 'video' ||
        /\.(?:mp3|mp4|webm)$/i.test(url.pathname);
    if (!isMedia || url.searchParams.get('v') === ARCHIVE_VERSION) return null;
    url.searchParams.set('v', ARCHIVE_VERSION);
    return url.href;
}

async function archiveFetch(request) {
    const cache = await caches.open(CACHE_NAME);
    const canonicalMediaUrl = canonicalVersionedMediaUrl(request);
    const cached = await cache.match(request, { ignoreVary: true }) ||
        (canonicalMediaUrl
            ? await cache.match(canonicalMediaUrl, { ignoreVary: true })
            : null);
    if (cached) return rangedResponse(cached, request.headers.get('Range'));
    await readArchiveMeta(cache);
    const url = request.url;
    const parsed = new URL(url);
    const versioned = parsed.origin === self.location.origin &&
        parsed.searchParams.get('v') === ARCHIVE_VERSION;
    const known = archiveManifest.has(url);
    const canonicalKnown = canonicalMediaUrl && archiveManifest.has(canonicalMediaUrl);
    if (archiveComplete && (versioned || known || canonicalKnown)) return lockedCacheMiss(url);
    if (versioned || known) {
        const stored = await fetchAndStore(cache, url);
        return rangedResponse(stored, request.headers.get('Range'));
    }
    if (canonicalKnown) {
        const stored = await fetchAndStore(cache, canonicalMediaUrl);
        return rangedResponse(stored, request.headers.get('Range'));
    }
    return fetch(request);
}

async function archiveNavigation(request) {
    const cache = await caches.open(CACHE_NAME);
    await readArchiveMeta(cache);
    const documentUrl = canonicalDocumentUrl(request.url);
    if (!archiveDocuments.has(documentUrl) && !isM2DocumentUrl(request.url)) return fetch(request);
    try {
        const response = await fetch(fullAssetRequest(documentUrl));
        if (!response.ok) throw new Error('HTTP ' + response.status + ' for ' + documentUrl);
        if (response.status === 206) throw new Error('Refusing partial document for ' + documentUrl);
        if (archiveDocuments.has(documentUrl)) {
            await cache.put(fullAssetRequest(documentUrl), response.clone());
        }
        await storeSurvivalDocument(response.clone(), documentUrl);
        return response;
    } catch (_) {
        const cached = await cache.match(documentUrl, { ignoreVary: true });
        if (cached) return cached;
        const survival = await readSurvivalDocument();
        if (survival) return survival;
        return lockedCacheMiss(documentUrl);
    }
}

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;
    if (request.mode === 'navigate') {
        event.respondWith(archiveNavigation(request));
        return;
    }
    const url = new URL(request.url);
    const versioned = url.origin === self.location.origin &&
        url.searchParams.get('v') === ARCHIVE_VERSION;
    if (!versioned && !CACHEABLE_DESTINATIONS.has(request.destination)) return;
    event.respondWith(archiveFetch(request));
});
