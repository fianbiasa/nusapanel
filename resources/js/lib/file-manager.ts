/**
 * Helpers of the file manager pages: paths relative to the home, which
 * files open in the editor or the preview, and the requests Inertia does
 * not make (chunked uploads with progress, and saving from the editor).
 */

export type FileEntry = {
    name: string;
    type: 'file' | 'directory' | 'link' | 'other';
    size: number;
    mode: string;
    modified: number;
    target?: string;
    target_type?: 'file' | 'directory' | 'other' | 'missing' | 'outside';
};

export type Listing = {
    path: string;
    entries: FileEntry[];
    truncated: boolean;
};

/** The path of `name` in folder `directory` ("" is the home). */
export function joinPath(directory: string, name: string): string {
    return directory === '' ? name : `${directory}/${name}`;
}

/** The folder `path` is in ("" for the home). */
export function parentPath(path: string): string {
    const slash = path.lastIndexOf('/');

    return slash === -1 ? '' : path.slice(0, slash);
}

/** The last name of `path`. */
export function baseName(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

export function isFolder(entry: FileEntry): boolean {
    return (
        entry.type === 'directory' ||
        (entry.type === 'link' && entry.target_type === 'directory')
    );
}

function extension(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot <= 0 ? '' : name.slice(dot + 1).toLowerCase();
}

const imageExtensions = new Set([
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'avif',
    'bmp',
    'ico',
    'svg',
]);

const textExtensions = new Set([
    'php',
    'phtml',
    'html',
    'htm',
    'css',
    'scss',
    'less',
    'js',
    'mjs',
    'cjs',
    'ts',
    'tsx',
    'jsx',
    'vue',
    'json',
    'xml',
    'svg',
    'txt',
    'md',
    'markdown',
    'csv',
    'log',
    'ini',
    'conf',
    'cnf',
    'cfg',
    'yml',
    'yaml',
    'toml',
    'env',
    'sql',
    'sh',
    'bash',
    'py',
    'rb',
    'pl',
    'twig',
    'blade',
    'tpl',
    'htaccess',
    'htpasswd',
    'lock',
    'gitignore',
    'editorconfig',
]);

/** Archives the agent can extract. */
export function isArchive(name: string): boolean {
    return /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tbz)$/i.test(name);
}

/** Images the preview can show. */
export function isImage(name: string): boolean {
    return imageExtensions.has(extension(name));
}

/**
 * Files that are most likely text: known extensions, and files without one
 * (".htaccess", "README"). The agent refuses anything that is not text.
 */
export function isEditable(name: string): boolean {
    const ext = extension(name);

    return (
        ext === '' ||
        textExtensions.has(ext) ||
        (name.startsWith('.') && !name.slice(1).includes('.'))
    );
}

/** The permission bits of a mode such as "0755": owner, group, others. */
export function modeBits(mode: string): boolean[] {
    const value = parseInt(mode, 8) & 0o777;

    return Array.from({ length: 9 }, (_, bit) =>
        Boolean(value & (1 << (8 - bit))),
    );
}

/** The mode ("755") of nine permission bits. */
export function bitsToMode(bits: boolean[]): string {
    const value = bits.reduce(
        (total, set, bit) => (set ? total | (1 << (8 - bit)) : total),
        0,
    );

    return value.toString(8).padStart(3, '0');
}

function xsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

/** The message to show for a failed request. */
function failureMessage(status: number, body: string): string {
    if (status === 419) {
        return 'Sesi kamu sudah habis. Muat ulang halaman, lalu coba lagi.';
    }

    if (status === 413) {
        return 'Server menolak bagian upload yang terlalu besar.';
    }

    try {
        const json = JSON.parse(body) as {
            errors?: Record<string, string[]>;
        };
        const first = Object.values(json.errors ?? {})[0]?.[0];

        if (first) {
            return first;
        }
    } catch {
        // Not JSON: fall through to the general message.
    }

    return `Permintaan gagal (HTTP ${status}). Coba lagi sebentar lagi.`;
}

/** Send JSON to the panel and return its answer, or throw its message. */
export async function sendJson<T>(
    url: string,
    method: 'POST' | 'PUT',
    data: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(data),
    });
    const body = await response.text();

    if (!response.ok) {
        throw new Error(failureMessage(response.status, body));
    }

    return JSON.parse(body) as T;
}

/** GET JSON from the panel, or throw its message. */
export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = await response.text();

    if (!response.ok) {
        throw new Error(failureMessage(response.status, body));
    }

    return JSON.parse(body) as T;
}

/** POST one chunk, reporting how much of it went out. */
function sendChunk(
    url: string,
    form: FormData,
    onProgress: (sent: number) => void,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();

        request.open('POST', url);
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        request.upload.onprogress = (event) => onProgress(event.loaded);
        request.onload = () => {
            if (request.status >= 200 && request.status < 300) {
                resolve();
            } else {
                reject(
                    new Error(
                        failureMessage(request.status, request.responseText),
                    ),
                );
            }
        };
        request.onerror = () =>
            reject(new Error('Koneksi terputus saat mengunggah.'));
        request.send(form);
    });
}

/**
 * Upload `file` as `path` in chunks of `chunkBytes`, one after another;
 * the panel stores the file once the last chunk is in.
 */
export async function uploadFile({
    url,
    file,
    path,
    overwrite,
    chunkBytes,
    onProgress,
}: {
    url: string;
    file: File;
    path: string;
    overwrite: boolean;
    chunkBytes: number;
    onProgress: (fraction: number) => void;
}): Promise<void> {
    const total = Math.max(1, Math.ceil(file.size / chunkBytes));
    const uploadId = crypto.randomUUID();

    for (let index = 0; index < total; index++) {
        const start = index * chunkBytes;
        const chunk = file.slice(
            start,
            Math.min(file.size, start + chunkBytes),
        );
        const form = new FormData();

        form.append('upload_id', uploadId);
        form.append('path', path);
        form.append('index', String(index));
        form.append('total', String(total));
        form.append('overwrite', overwrite ? '1' : '0');
        form.append('chunk', chunk, 'chunk');

        await sendChunk(url, form, (sent) =>
            onProgress(file.size === 0 ? 1 : (start + sent) / file.size),
        );
    }

    onProgress(1);
}
