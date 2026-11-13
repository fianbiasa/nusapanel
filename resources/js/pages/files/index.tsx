import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArrowUp,
    CheckCircle2,
    Copy,
    Download,
    Eye,
    File,
    FileCode2,
    FilePlus,
    Folder,
    FolderInput,
    FolderOpen,
    FolderPlus,
    HardDrive,
    House,
    Image,
    Link2,
    LoaderCircle,
    MoreHorizontal,
    PackageOpen,
    Pencil,
    Search,
    ShieldCheck,
    Trash2,
    Upload,
    XCircle,
} from 'lucide-react';
import type { DragEvent, ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { FileEntry, Listing } from '@/lib/file-manager';
import {
    baseName,
    bitsToMode,
    getJson,
    isArchive,
    isEditable,
    isFolder,
    isImage,
    joinPath,
    modeBits,
    parentPath,
    sendJson,
    uploadFile,
} from '@/lib/file-manager';
import { formatBytes, formatDateTime } from '@/lib/format';
import {
    compress,
    create,
    deleteMethod,
    download,
    edit,
    extract,
    index,
    permissions,
    preview,
    rename,
    search,
    size,
    sizes as sizesRoute,
    transfer,
    trash,
    upload,
} from '@/routes/files';

type Props = {
    path: string;
    listing: Listing | null;
    error: string | null;
    home: string;
    chunk_bytes: number;
    max_upload_bytes: number;
};

type Action =
    | { type: 'create'; kind: 'file' | 'folder' }
    | { type: 'rename'; entry: FileEntry }
    | { type: 'transfer'; paths: string[]; move: boolean }
    | { type: 'permissions'; paths: string[]; mode: string }
    | { type: 'delete'; paths: string[] }
    | { type: 'preview'; path: string }
    | { type: 'compress'; paths: string[] }
    | { type: 'extract'; path: string }
    | { type: 'search'; query: string };

type Usage = { path: string; size: number; files: number; folders: number };

type UsageAnswer = {
    usage: Usage[];
    failed: { path: string; message: string }[];
};

type UploadItem = {
    id: string;
    name: string;
    progress: number;
    status: 'waiting' | 'uploading' | 'done' | 'failed';
    message?: string;
};

type PendingUpload = { files: File[]; conflicts: string[] };

/** The page of a folder ("" is the home). */
function folderHref(path: string): string {
    return path === '' ? index().url : index({ query: { path } }).url;
}

export default function FilesIndex({
    path,
    listing,
    error,
    home,
    chunk_bytes,
    max_upload_bytes,
}: Props) {
    const entries = useMemo(() => listing?.entries ?? [], [listing]);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [uploads, setUploads] = useState<UploadItem[]>([]);
    const [pending, setPending] = useState<PendingUpload | null>(null);
    const [dragging, setDragging] = useState(false);
    const [sizes, setSizes] = useState<Record<string, Usage | 'counting'>>({});
    const [query, setQuery] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        setSelected(new Set());
        setSizes({});
    }, [path]);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    const clearSelection = () => setSelected(new Set());

    /** Count what folders hold, showing it next to them. */
    const countSizes = (names: string[]) => {
        setSizes((current) => ({
            ...current,
            ...Object.fromEntries(names.map((name) => [name, 'counting'])),
        }));

        sendJson<UsageAnswer>(size().url, 'POST', {
            paths: names.map((name) => joinPath(path, name)),
        })
            .then((answer) => {
                const counted = Object.fromEntries(
                    answer.usage.map((usage) => [baseName(usage.path), usage]),
                );

                setSizes((current) => {
                    const next = { ...current, ...counted };

                    for (const name of names) {
                        if (next[name] === 'counting') {
                            delete next[name];
                        }
                    }

                    return next;
                });

                if (names.length > 1) {
                    const total = answer.usage.reduce(
                        (sum, usage) => sum + usage.size,
                        0,
                    );
                    const files = answer.usage.reduce(
                        (sum, usage) => sum + usage.files,
                        0,
                    );

                    toast.info(
                        `${names.length} item: ${formatBytes(total)} dalam ${files.toLocaleString('id-ID')} file.`,
                    );
                }

                if (answer.failed.length > 0) {
                    toast.error(
                        `${answer.failed[0].path}: ${answer.failed[0].message}`,
                    );
                }
            })
            .catch((failure: Error) => {
                toast.error(failure.message);
                setSizes((current) => {
                    const next = { ...current };

                    for (const name of names) {
                        delete next[name];
                    }

                    return next;
                });
            });
    };

    const selectedPaths = [...selected].map((name) => joinPath(path, name));
    const allSelected =
        entries.length > 0 && entries.every((e) => selected.has(e.name));

    const toggle = (name: string) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(name)) {
                next.delete(name);
            } else {
                next.add(name);
            }

            return next;
        });

    const startUploads = (files: File[], overwrite: boolean) => {
        const items = files.map((file) => ({
            file,
            item: {
                id: crypto.randomUUID(),
                name: file.name,
                progress: 0,
                status: 'waiting',
            } as UploadItem,
        }));

        for (const { file, item } of items) {
            if (file.size > max_upload_bytes) {
                item.status = 'failed';
                item.message = `Lebih besar dari ${formatBytes(max_upload_bytes)}.`;
            }
        }

        setUploads((current) => [...current, ...items.map((i) => i.item)]);

        const update = (id: string, change: Partial<UploadItem>) =>
            setUploads((current) =>
                current.map((item) =>
                    item.id === id ? { ...item, ...change } : item,
                ),
            );

        void (async () => {
            for (const { file, item } of items) {
                if (item.status === 'failed') {
                    continue;
                }

                update(item.id, { status: 'uploading' });

                try {
                    await uploadFile({
                        url: upload().url,
                        file,
                        path: joinPath(path, file.name),
                        overwrite,
                        chunkBytes: chunk_bytes,
                        onProgress: (progress) => update(item.id, { progress }),
                    });
                    update(item.id, { status: 'done', progress: 1 });
                } catch (failure) {
                    update(item.id, {
                        status: 'failed',
                        message:
                            failure instanceof Error
                                ? failure.message
                                : 'Upload gagal.',
                    });
                }
            }

            router.reload({ only: ['listing'] });
        })();
    };

    /** Upload files, asking first when some would replace existing ones. */
    const queueUploads = (files: File[]) => {
        if (files.length === 0) {
            return;
        }

        const existing = new Set(entries.map((entry) => entry.name));
        const conflicts = files
            .map((file) => file.name)
            .filter((name) => existing.has(name));

        if (conflicts.length > 0) {
            setPending({ files, conflicts });

            return;
        }

        startUploads(files, false);
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);

        // Folders cannot be uploaded (yet): only files are taken.
        const files = [...event.dataTransfer.items]
            .filter(
                (item) =>
                    item.kind === 'file' &&
                    !item.webkitGetAsEntry()?.isDirectory,
            )
            .map((item) => item.getAsFile())
            .filter((file): file is File => file !== null);

        queueUploads(files);
    };

    const segments = path === '' ? [] : path.split('/');
    const firstSelected = entries.find((entry) => selected.has(entry.name));

    return (
        <>
            <Head title="File Manager" />

            <PageHeader
                title="File Manager"
                description="Kelola file website kamu langsung dari browser: unggah, edit, salin, pindah, atur izin, dan pulihkan yang terhapus."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" asChild>
                            <Link
                                href={
                                    path === ''
                                        ? sizesRoute()
                                        : sizesRoute({ query: { path } })
                                }
                            >
                                <HardDrive />
                                Pemakaian disk
                            </Link>
                        </Button>
                        <Button variant="secondary" asChild>
                            <Link href={trash()}>
                                <Trash2 />
                                Trash
                            </Link>
                        </Button>
                    </div>
                }
            />

            <Panel flush>
                <div className="flex flex-col gap-4 px-6 pt-6 pb-4 lg:flex-row lg:items-center lg:justify-between">
                    <nav
                        aria-label="Lokasi folder"
                        className="flex min-w-0 flex-wrap items-center gap-1 font-mono text-sm"
                    >
                        <Link
                            href={folderHref('')}
                            className="flex items-center gap-1.5 rounded-md px-2 py-1 font-semibold hover:bg-muted"
                            title={home}
                        >
                            <House className="size-4" />
                            home
                        </Link>
                        {segments.map((segment, position) => {
                            const target = segments
                                .slice(0, position + 1)
                                .join('/');
                            const last = position === segments.length - 1;

                            return (
                                <span
                                    key={target}
                                    className="flex items-center gap-1"
                                >
                                    <span className="text-muted-foreground">
                                        /
                                    </span>
                                    {last ? (
                                        <span className="rounded-md px-2 py-1 font-semibold break-all">
                                            {segment}
                                        </span>
                                    ) : (
                                        <Link
                                            href={folderHref(target)}
                                            className="rounded-md px-2 py-1 break-all hover:bg-muted"
                                        >
                                            {segment}
                                        </Link>
                                    )}
                                </span>
                            );
                        })}
                    </nav>

                    <div className="flex flex-wrap gap-2">
                        <form
                            className="flex"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (query.trim() !== '') {
                                    openDialog({
                                        type: 'search',
                                        query: query.trim(),
                                    });
                                }
                            }}
                        >
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Cari nama file…"
                                    aria-label="Cari file di folder ini"
                                    maxLength={100}
                                    className="w-48 pl-9"
                                    disabled={listing === null}
                                />
                            </div>
                        </form>
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            className="hidden"
                            onChange={(event) => {
                                queueUploads([...(event.target.files ?? [])]);
                                event.target.value = '';
                            }}
                        />
                        <Button
                            onClick={() => fileInput.current?.click()}
                            disabled={listing === null}
                        >
                            <Upload />
                            Unggah
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() =>
                                openDialog({ type: 'create', kind: 'folder' })
                            }
                            disabled={listing === null}
                        >
                            <FolderPlus />
                            Folder baru
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() =>
                                openDialog({ type: 'create', kind: 'file' })
                            }
                            disabled={listing === null}
                        >
                            <FilePlus />
                            File baru
                        </Button>
                    </div>
                </div>

                {selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-t bg-accent/60 px-6 py-3 text-sm">
                        <span className="mr-2 font-semibold">
                            {selected.size} dipilih
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                openDialog({
                                    type: 'transfer',
                                    paths: selectedPaths,
                                    move: false,
                                })
                            }
                        >
                            <Copy />
                            Salin
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                openDialog({
                                    type: 'transfer',
                                    paths: selectedPaths,
                                    move: true,
                                })
                            }
                        >
                            <FolderInput />
                            Pindah
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                openDialog({
                                    type: 'permissions',
                                    paths: selectedPaths,
                                    mode: firstSelected?.mode ?? '0644',
                                })
                            }
                        >
                            <ShieldCheck />
                            Izin
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                openDialog({
                                    type: 'compress',
                                    paths: selectedPaths,
                                })
                            }
                        >
                            <Archive />
                            Kompres
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => countSizes([...selected])}
                        >
                            <HardDrive />
                            Ukuran
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() =>
                                openDialog({
                                    type: 'delete',
                                    paths: selectedPaths,
                                })
                            }
                        >
                            <Trash2 />
                            Hapus
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={clearSelection}
                        >
                            Batal pilih
                        </Button>
                    </div>
                )}

                <div
                    className="relative"
                    onDragOver={(event) => {
                        if (listing !== null) {
                            event.preventDefault();
                            setDragging(true);
                        }
                    }}
                    onDragLeave={(event) => {
                        if (event.currentTarget === event.target) {
                            setDragging(false);
                        }
                    }}
                    onDrop={onDrop}
                >
                    {dragging && (
                        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-b-2xl border-2 border-dashed border-primary bg-primary/5 text-sm font-semibold text-primary">
                            Lepas file di sini untuk mengunggah ke folder ini
                        </div>
                    )}

                    {error !== null ? (
                        <div className="px-6 pb-6">
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {error}{' '}
                                    <Link
                                        href={folderHref(parentPath(path))}
                                        className="font-semibold underline"
                                    >
                                        Kembali ke folder sebelumnya
                                    </Link>
                                </AlertDescription>
                            </Alert>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableStyles.table}>
                                <thead className={tableStyles.head}>
                                    <tr>
                                        <th className="w-10 py-3 pl-6">
                                            <Checkbox
                                                aria-label="Pilih semua"
                                                checked={allSelected}
                                                onCheckedChange={(checked) =>
                                                    setSelected(
                                                        checked
                                                            ? new Set(
                                                                  entries.map(
                                                                      (e) =>
                                                                          e.name,
                                                                  ),
                                                              )
                                                            : new Set(),
                                                    )
                                                }
                                            />
                                        </th>
                                        <th className={tableStyles.th}>Nama</th>
                                        <th
                                            className={`${tableStyles.th} hidden text-right sm:table-cell`}
                                        >
                                            Ukuran
                                        </th>
                                        <th
                                            className={`${tableStyles.th} hidden md:table-cell`}
                                        >
                                            Diubah
                                        </th>
                                        <th
                                            className={`${tableStyles.th} hidden sm:table-cell`}
                                        >
                                            Izin
                                        </th>
                                        <th className={tableStyles.th}>
                                            <span className="sr-only">
                                                Aksi
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {path !== '' && (
                                        <tr className={tableStyles.row}>
                                            <td className="py-3 pl-6" />
                                            <td
                                                className="px-6 py-3"
                                                colSpan={5}
                                            >
                                                <Link
                                                    href={folderHref(
                                                        parentPath(path),
                                                    )}
                                                    className="flex items-center gap-3 font-semibold text-muted-foreground hover:text-foreground"
                                                >
                                                    <ArrowUp className="size-4" />
                                                    ..
                                                </Link>
                                            </td>
                                        </tr>
                                    )}
                                    {entries.map((entry) => (
                                        <EntryRow
                                            key={entry.name}
                                            entry={entry}
                                            path={joinPath(path, entry.name)}
                                            checked={selected.has(entry.name)}
                                            onToggle={() => toggle(entry.name)}
                                            onAction={openDialog}
                                            usage={sizes[entry.name]}
                                            onCountSize={() =>
                                                countSizes([entry.name])
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>

                            {entries.length === 0 && (
                                <div className="flex flex-col items-center gap-3 px-6 pt-8 pb-12 text-center">
                                    <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                                        <FolderOpen className="size-6" />
                                    </span>
                                    <p className="max-w-sm text-sm text-muted-foreground">
                                        Folder ini kosong. Unggah file, atau
                                        seret file ke sini.
                                    </p>
                                </div>
                            )}

                            {listing?.truncated && (
                                <p className="border-t px-6 py-3 text-sm text-muted-foreground">
                                    Folder ini berisi lebih dari{' '}
                                    {entries.length.toLocaleString('id-ID')}{' '}
                                    item; hanya sebagian yang ditampilkan.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </Panel>

            {uploads.length > 0 && (
                <UploadQueue
                    uploads={uploads}
                    onClear={() =>
                        setUploads((current) =>
                            current.filter(
                                (item) =>
                                    item.status === 'waiting' ||
                                    item.status === 'uploading',
                            ),
                        )
                    }
                />
            )}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
            >
                <DialogContent className="rounded-2xl">
                    {pending && (
                        <OverwriteQuestion
                            pending={pending}
                            onSkip={() => {
                                const skip = new Set(pending.conflicts);
                                startUploads(
                                    pending.files.filter(
                                        (file) => !skip.has(file.name),
                                    ),
                                    false,
                                );
                                setPending(null);
                            }}
                            onOverwrite={() => {
                                startUploads(pending.files, true);
                                setPending(null);
                            }}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent
                    className={
                        action?.type === 'preview'
                            ? 'rounded-2xl sm:max-w-3xl'
                            : 'rounded-2xl'
                    }
                >
                    {action?.type === 'create' && (
                        <NameForm
                            title={
                                action.kind === 'folder'
                                    ? 'Folder baru'
                                    : 'File baru'
                            }
                            description={`Dibuat di ${path === '' ? home : `${home}/${path}`}.`}
                            form={create.form()}
                            hidden={{ directory: path, type: action.kind }}
                            initial=""
                            submitLabel="Buat"
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'rename' && (
                        <NameForm
                            title={`Ganti nama ${action.entry.name}`}
                            form={rename.form()}
                            hidden={{
                                path: joinPath(path, action.entry.name),
                            }}
                            initial={action.entry.name}
                            submitLabel="Simpan"
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'transfer' && (
                        <TransferForm
                            paths={action.paths}
                            move={action.move}
                            current={path}
                            home={home}
                            onSuccess={() => {
                                closeDialog();
                                clearSelection();
                            }}
                        />
                    )}
                    {action?.type === 'permissions' && (
                        <PermissionsForm
                            paths={action.paths}
                            mode={action.mode}
                            onSuccess={() => {
                                closeDialog();
                                clearSelection();
                            }}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <DeleteForm
                            paths={action.paths}
                            onSuccess={() => {
                                closeDialog();
                                clearSelection();
                            }}
                        />
                    )}
                    {action?.type === 'preview' && (
                        <ImagePreview path={action.path} />
                    )}
                    {action?.type === 'compress' && (
                        <CompressForm
                            paths={action.paths}
                            directory={path}
                            onSuccess={() => {
                                closeDialog();
                                clearSelection();
                            }}
                        />
                    )}
                    {action?.type === 'extract' && (
                        <ExtractForm
                            path={action.path}
                            home={home}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'search' && (
                        <SearchResults
                            path={path}
                            query={action.query}
                            home={home}
                            onOpen={closeDialog}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function EntryIcon({ entry }: { entry: FileEntry }) {
    const className = 'size-4 shrink-0';

    if (entry.type === 'link') {
        return <Link2 className={`${className} text-sky-500`} />;
    }

    if (entry.type === 'directory') {
        return (
            <Folder className={`${className} fill-amber-200 text-amber-500`} />
        );
    }

    if (isImage(entry.name)) {
        return <Image className={`${className} text-violet-500`} />;
    }

    if (isEditable(entry.name)) {
        return <FileCode2 className={`${className} text-primary`} />;
    }

    return <File className={`${className} text-muted-foreground`} />;
}

function EntryRow({
    entry,
    path,
    checked,
    onToggle,
    onAction,
    usage,
    onCountSize,
}: {
    entry: FileEntry;
    path: string;
    checked: boolean;
    onToggle: () => void;
    onAction: (action: Action) => void;
    usage: Usage | 'counting' | undefined;
    onCountSize: () => void;
}) {
    const folder = isFolder(entry);
    const file =
        entry.type === 'file' ||
        (entry.type === 'link' && entry.target_type === 'file');
    const editable = entry.type === 'file' && isEditable(entry.name);
    const image = file && isImage(entry.name);
    const archive = entry.type === 'file' && isArchive(entry.name);
    let sizeLabel = '—';

    if (entry.type === 'file') {
        sizeLabel = formatBytes(entry.size);
    } else if (usage === 'counting') {
        sizeLabel = 'menghitung…';
    } else if (usage) {
        sizeLabel = formatBytes(usage.size);
    }

    const name = (
        <span className="flex min-w-0 items-center gap-3">
            <EntryIcon entry={entry} />
            <span className="font-medium break-all">{entry.name}</span>
            {entry.type === 'link' && entry.target && (
                <span className="truncate font-mono text-xs text-muted-foreground">
                    → {entry.target}
                </span>
            )}
        </span>
    );

    let link: ReactNode = name;

    if (folder) {
        link = (
            <Link href={folderHref(path)} className="hover:text-primary">
                {name}
            </Link>
        );
    } else if (editable) {
        link = (
            <Link
                href={edit({ query: { path } })}
                className="hover:text-primary"
            >
                {name}
            </Link>
        );
    } else if (image) {
        link = (
            <button
                type="button"
                className="text-left hover:text-primary"
                onClick={() => onAction({ type: 'preview', path })}
            >
                {name}
            </button>
        );
    } else if (file) {
        link = (
            <a
                href={download({ query: { path } }).url}
                className="hover:text-primary"
            >
                {name}
            </a>
        );
    }

    return (
        <tr className={`${tableStyles.row} ${checked ? 'bg-accent/40' : ''}`}>
            <td className="py-3 pl-6">
                <Checkbox
                    aria-label={`Pilih ${entry.name}`}
                    checked={checked}
                    onCheckedChange={onToggle}
                />
            </td>
            <td className="px-6 py-3">{link}</td>
            <td className="hidden px-6 py-3 text-right whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell">
                <span
                    title={
                        usage && usage !== 'counting'
                            ? `${usage.files.toLocaleString('id-ID')} file, ${usage.folders.toLocaleString('id-ID')} folder`
                            : undefined
                    }
                >
                    {sizeLabel}
                </span>
            </td>
            <td className="hidden px-6 py-3 whitespace-nowrap text-muted-foreground md:table-cell">
                {formatDateTime(new Date(entry.modified * 1000).toISOString())}
            </td>
            <td className="hidden px-6 py-3 font-mono text-xs text-muted-foreground sm:table-cell">
                {entry.type === 'link' ? '—' : entry.mode}
            </td>
            <td className="px-4 py-2 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Aksi untuk ${entry.name}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                        {folder && (
                            <DropdownMenuItem asChild>
                                <Link href={folderHref(path)}>
                                    <FolderOpen />
                                    Buka
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {editable && (
                            <DropdownMenuItem asChild>
                                <Link href={edit({ query: { path } })}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {image && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    onAction({ type: 'preview', path })
                                }
                            >
                                <Eye />
                                Lihat
                            </DropdownMenuItem>
                        )}
                        {file && (
                            <DropdownMenuItem asChild>
                                <a href={download({ query: { path } }).url}>
                                    <Download />
                                    Download
                                </a>
                            </DropdownMenuItem>
                        )}
                        {archive && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    onAction({ type: 'extract', path })
                                }
                            >
                                <PackageOpen />
                                Ekstrak…
                            </DropdownMenuItem>
                        )}
                        {entry.type === 'directory' && (
                            <DropdownMenuItem onSelect={onCountSize}>
                                <HardDrive />
                                Hitung ukuran
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction({ type: 'compress', paths: [path] })
                            }
                        >
                            <Archive />
                            Kompres…
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => onAction({ type: 'rename', entry })}
                        >
                            <Pencil />
                            Ganti nama
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction({
                                    type: 'transfer',
                                    paths: [path],
                                    move: false,
                                })
                            }
                        >
                            <Copy />
                            Salin ke…
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction({
                                    type: 'transfer',
                                    paths: [path],
                                    move: true,
                                })
                            }
                        >
                            <FolderInput />
                            Pindah ke…
                        </DropdownMenuItem>
                        {entry.type !== 'link' && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    onAction({
                                        type: 'permissions',
                                        paths: [path],
                                        mode: entry.mode,
                                    })
                                }
                            >
                                <ShieldCheck />
                                Izin
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() =>
                                onAction({ type: 'delete', paths: [path] })
                            }
                        >
                            <Trash2 />
                            Hapus
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
        </tr>
    );
}

function OverwriteQuestion({
    pending,
    onSkip,
    onOverwrite,
}: {
    pending: PendingUpload;
    onSkip: () => void;
    onOverwrite: () => void;
}) {
    const { conflicts } = pending;
    const shown = conflicts.slice(0, 5).join(', ');

    return (
        <>
            <DialogHeader>
                <DialogTitle>File sudah ada</DialogTitle>
                <DialogDescription className="break-all">
                    {conflicts.length === 1
                        ? `${conflicts[0]} sudah ada di folder ini.`
                        : `${conflicts.length} file sudah ada di folder ini: ${shown}${conflicts.length > 5 ? ', …' : ''}.`}{' '}
                    Timpa dengan yang baru?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2">
                <Button variant="secondary" onClick={onSkip}>
                    Lewati yang sudah ada
                </Button>
                <Button variant="destructive" onClick={onOverwrite}>
                    Timpa
                </Button>
            </DialogFooter>
        </>
    );
}

function ImagePreview({ path }: { path: string }) {
    return (
        <>
            <DialogHeader>
                <DialogTitle className="break-all">{path}</DialogTitle>
            </DialogHeader>
            <div className="flex max-h-[70vh] justify-center overflow-auto rounded-xl bg-muted/50 p-4">
                <img
                    src={preview({ query: { path } }).url}
                    alt={path}
                    className="max-h-[65vh] object-contain"
                />
            </div>
            <DialogFooter>
                <Button variant="secondary" asChild>
                    <a href={download({ query: { path } }).url}>
                        <Download />
                        Download
                    </a>
                </Button>
            </DialogFooter>
        </>
    );
}

function NameForm({
    title,
    description,
    form,
    hidden,
    initial,
    submitLabel,
    onSuccess,
}: {
    title: string;
    description?: string;
    form: { action: string; method: 'post' };
    hidden: Record<string, string>;
    initial: string;
    submitLabel: string;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle className="break-all">{title}</DialogTitle>
                        {description && (
                            <DialogDescription className="break-all">
                                {description}
                            </DialogDescription>
                        )}
                    </DialogHeader>

                    {Object.entries(hidden).map(([name, value]) => (
                        <input
                            key={name}
                            type="hidden"
                            name={name}
                            value={value}
                        />
                    ))}

                    <div className="grid gap-2">
                        <Label htmlFor="file-name">Nama</Label>
                        <Input
                            id="file-name"
                            name="name"
                            defaultValue={initial}
                            maxLength={255}
                            autoFocus
                            required
                            aria-invalid={Boolean(errors.name)}
                        />
                        <InputError
                            message={
                                errors.name ?? errors.path ?? errors.directory
                            }
                        />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function TransferForm({
    paths,
    move,
    current,
    home,
    onSuccess,
}: {
    paths: string[];
    move: boolean;
    current: string;
    home: string;
    onSuccess: () => void;
}) {
    const [target, setTarget] = useState(current);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const verb = move ? 'Pindahkan' : 'Salin';

    const submit = () => {
        setProcessing(true);
        router.post(
            transfer().url,
            { paths, target: target.replace(/^\/+|\/+$/g, ''), move },
            {
                preserveScroll: true,
                onSuccess,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle className="break-all">
                    {verb}{' '}
                    {paths.length === 1 ? paths[0] : `${paths.length} item`}
                </DialogTitle>
                <DialogDescription>
                    Tulis folder tujuan, relatif terhadap {home}. Kosongkan
                    untuk folder home itu sendiri.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="transfer-target">Folder tujuan</Label>
                <div className="flex items-center rounded-md border bg-background focus-within:ring-2 focus-within:ring-ring">
                    <span className="pl-3 font-mono text-sm text-muted-foreground">
                        {home}/
                    </span>
                    <input
                        id="transfer-target"
                        value={target}
                        onChange={(event) => setTarget(event.target.value)}
                        className="h-9 min-w-0 flex-1 bg-transparent pr-3 font-mono text-sm outline-none"
                        placeholder="domains/contoh.com/public_html"
                        autoFocus
                    />
                </div>
                <InputError
                    message={errors.target ?? errors.paths ?? errors['paths.0']}
                />
            </div>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {verb}
                </Button>
            </DialogFooter>
        </form>
    );
}

const permissionGroups = ['Pemilik', 'Grup', 'Lainnya'];
const permissionNames = ['Baca', 'Tulis', 'Jalankan'];

function PermissionsForm({
    paths,
    mode,
    onSuccess,
}: {
    paths: string[];
    mode: string;
    onSuccess: () => void;
}) {
    const [bits, setBits] = useState(() => modeBits(mode));
    const [typed, setTyped] = useState(() => bitsToMode(modeBits(mode)));
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const octal = bitsToMode(bits);

    const changeBit = (position: number, value: boolean) => {
        const next = bits.map((bit, index) =>
            index === position ? value : bit,
        );

        setBits(next);
        setTyped(bitsToMode(next));
    };

    const submit = () => {
        setProcessing(true);
        router.post(
            permissions().url,
            { paths, mode: octal },
            {
                preserveScroll: true,
                onSuccess,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle className="break-all">
                    Izin{' '}
                    {paths.length === 1 ? paths[0] : `${paths.length} item`}
                </DialogTitle>
                <DialogDescription>
                    File website biasanya 644 dan folder 755. File berisi
                    password (seperti wp-config.php) cukup 640 atau 600.
                </DialogDescription>
            </DialogHeader>

            <div className="grid grid-cols-4 gap-y-3 text-sm">
                <span />
                {permissionNames.map((name) => (
                    <span
                        key={name}
                        className="text-center font-semibold text-muted-foreground"
                    >
                        {name}
                    </span>
                ))}
                {permissionGroups.map((group, row) => (
                    <PermissionRow
                        key={group}
                        group={group}
                        bits={bits.slice(row * 3, row * 3 + 3)}
                        onChange={(column, value) =>
                            changeBit(row * 3 + column, value)
                        }
                    />
                ))}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="permissions-octal">Angka</Label>
                <Input
                    id="permissions-octal"
                    value={typed}
                    inputMode="numeric"
                    maxLength={3}
                    className="w-24 font-mono"
                    onChange={(event) => {
                        setTyped(event.target.value);

                        if (/^[0-7]{3}$/.test(event.target.value)) {
                            setBits(modeBits(event.target.value));
                        }
                    }}
                />
                <InputError message={errors.mode ?? errors.paths} />
            </div>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Simpan izin
                </Button>
            </DialogFooter>
        </form>
    );
}

function PermissionRow({
    group,
    bits,
    onChange,
}: {
    group: string;
    bits: boolean[];
    onChange: (column: number, value: boolean) => void;
}) {
    return (
        <>
            <span className="font-medium">{group}</span>
            {bits.map((bit, column) => (
                <span key={column} className="flex justify-center">
                    <Checkbox
                        aria-label={`${group}: ${permissionNames[column]}`}
                        checked={bit}
                        onCheckedChange={(value) =>
                            onChange(column, value === true)
                        }
                    />
                </span>
            ))}
        </>
    );
}

function DeleteForm({
    paths,
    onSuccess,
}: {
    paths: string[];
    onSuccess: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <DialogHeader>
                <DialogTitle className="break-all">
                    Hapus{' '}
                    {paths.length === 1 ? paths[0] : `${paths.length} item`}?
                </DialogTitle>
                <DialogDescription>
                    Dipindahkan ke Trash, jadi masih bisa dipulihkan. Isinya
                    tetap memakai kuota disk sampai Trash dikosongkan.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    disabled={processing}
                    onClick={() => {
                        setProcessing(true);
                        router.post(
                            deleteMethod().url,
                            { paths },
                            {
                                preserveScroll: true,
                                onSuccess,
                                onFinish: () => setProcessing(false),
                            },
                        );
                    }}
                >
                    {processing ? <Spinner /> : <Trash2 />}
                    Hapus
                </Button>
            </DialogFooter>
        </>
    );
}

const archiveSuffix = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tbz)$/i;

function CompressForm({
    paths,
    directory,
    onSuccess,
}: {
    paths: string[];
    directory: string;
    onSuccess: () => void;
}) {
    const [format, setFormat] = useState<'zip' | 'tar.gz'>('zip');
    const [name, setName] = useState(
        () =>
            `${paths.length === 1 ? baseName(paths[0]) : `arsip-${new Date().toISOString().slice(0, 10)}`}.zip`,
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const changeFormat = (next: 'zip' | 'tar.gz') => {
        setFormat(next);
        setName(
            (current) =>
                current.replace(archiveSuffix, '') +
                (next === 'zip' ? '.zip' : '.tar.gz'),
        );
    };

    const submit = () => {
        setProcessing(true);
        router.post(
            compress().url,
            { paths, directory, name: name.trim(), format },
            {
                preserveScroll: true,
                onSuccess,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle className="break-all">
                    Kompres{' '}
                    {paths.length === 1 ? paths[0] : `${paths.length} item`}
                </DialogTitle>
                <DialogDescription>
                    Arsipnya dibuat di folder ini.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <Label>Format</Label>
                <div className="flex gap-2">
                    {(['zip', 'tar.gz'] as const).map((option) => (
                        <Button
                            key={option}
                            type="button"
                            size="sm"
                            variant={
                                format === option ? 'default' : 'secondary'
                            }
                            onClick={() => changeFormat(option)}
                        >
                            {option === 'zip' ? 'ZIP' : 'TAR.GZ'}
                        </Button>
                    ))}
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="archive-name">Nama arsip</Label>
                <Input
                    id="archive-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    maxLength={255}
                    autoFocus
                    required
                />
                <InputError
                    message={
                        errors.name ??
                        errors.paths ??
                        errors['paths.0'] ??
                        errors.format
                    }
                />
            </div>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner /> : <Archive />}
                    Kompres
                </Button>
            </DialogFooter>
        </form>
    );
}

function ExtractForm({
    path,
    home,
    onSuccess,
}: {
    path: string;
    home: string;
    onSuccess: () => void;
}) {
    const folder = parentPath(path);
    const ownFolder = joinPath(
        folder,
        baseName(path).replace(archiveSuffix, ''),
    );
    const [target, setTarget] = useState(folder);
    const [overwrite, setOverwrite] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () => {
        setProcessing(true);
        router.post(
            extract().url,
            { path, target: target.replace(/^\/+|\/+$/g, ''), overwrite },
            {
                preserveScroll: true,
                onSuccess,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form
            className="grid gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle className="break-all">
                    Ekstrak {baseName(path)}
                </DialogTitle>
                <DialogDescription>
                    ZIP, TAR, TAR.GZ dan TAR.BZ2. Folder tujuan dibuat kalau
                    belum ada.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="extract-target">Folder tujuan</Label>
                <div className="flex items-center rounded-md border bg-background focus-within:ring-2 focus-within:ring-ring">
                    <span className="pl-3 font-mono text-sm text-muted-foreground">
                        {home}/
                    </span>
                    <input
                        id="extract-target"
                        value={target}
                        onChange={(event) => setTarget(event.target.value)}
                        className="h-9 min-w-0 flex-1 bg-transparent pr-3 font-mono text-sm outline-none"
                    />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => setTarget(folder)}
                    >
                        Folder ini
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => setTarget(ownFolder)}
                    >
                        Folder baru {baseName(ownFolder)}
                    </Button>
                </div>
                <InputError message={errors.target ?? errors.path} />
            </div>

            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={overwrite}
                    onCheckedChange={(value) => setOverwrite(value === true)}
                />
                Timpa file yang sudah ada
            </label>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner /> : <PackageOpen />}
                    Ekstrak
                </Button>
            </DialogFooter>
        </form>
    );
}

type Hit = {
    path: string;
    type: FileEntry['type'];
    size: number;
    modified: number;
};

function SearchResults({
    path,
    query,
    home,
    onOpen,
}: {
    path: string;
    query: string;
    home: string;
    onOpen: () => void;
}) {
    const [answer, setAnswer] = useState<{
        hits: Hit[];
        truncated: boolean;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        getJson<{ hits: Hit[]; truncated: boolean }>(
            search({ query: { path, query } }).url,
        )
            .then(setAnswer)
            .catch((failure: Error) => setError(failure.message));
    }, [path, query]);

    const target = (hit: Hit): string => {
        if (hit.type === 'directory') {
            return folderHref(hit.path);
        }

        if (hit.type === 'file' && isEditable(baseName(hit.path))) {
            return edit({ query: { path: hit.path } }).url;
        }

        return folderHref(parentPath(hit.path));
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="break-all">
                    Hasil pencarian “{query}”
                </DialogTitle>
                <DialogDescription className="break-all">
                    Di {path === '' ? home : `${home}/${path}`} dan semua
                    subfoldernya.
                </DialogDescription>
            </DialogHeader>

            {error !== null && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {answer === null && error === null && (
                <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                    <Spinner />
                    Mencari…
                </div>
            )}

            {answer !== null && (
                <div className="grid gap-3">
                    {answer.hits.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            Tidak ada file atau folder yang namanya memuat “
                            {query}”.
                        </p>
                    ) : (
                        <ul className="max-h-[55vh] divide-y overflow-y-auto rounded-xl border">
                            {answer.hits.map((hit) => (
                                <li key={hit.path}>
                                    <Link
                                        href={target(hit)}
                                        onClick={onOpen}
                                        className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-muted/60"
                                    >
                                        {hit.type === 'directory' ? (
                                            <Folder className="size-4 shrink-0 fill-amber-200 text-amber-500" />
                                        ) : (
                                            <FileCode2 className="size-4 shrink-0 text-primary" />
                                        )}
                                        <span className="min-w-0 font-mono text-xs break-all">
                                            {hit.path}
                                        </span>
                                        {hit.type === 'file' && (
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {formatBytes(hit.size)}
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {answer.truncated && (
                        <p className="text-xs text-muted-foreground">
                            Hasilnya terlalu banyak; persempit kata kuncinya
                            atau cari dari subfolder.
                        </p>
                    )}
                </div>
            )}
        </>
    );
}

function UploadQueue({
    uploads,
    onClear,
}: {
    uploads: UploadItem[];
    onClear: () => void;
}) {
    const busy = uploads.some(
        (item) => item.status === 'waiting' || item.status === 'uploading',
    );

    return (
        <div className="fixed right-4 bottom-4 z-40 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border bg-card shadow-card">
            <div className="flex items-center justify-between border-b px-4 py-3">
                <span className="text-sm font-semibold">
                    {busy ? 'Mengunggah…' : 'Upload selesai'}
                </span>
                {!busy && (
                    <Button size="sm" variant="ghost" onClick={onClear}>
                        Tutup
                    </Button>
                )}
            </div>
            <ul className="max-h-64 divide-y overflow-y-auto">
                {uploads.map((item) => (
                    <li key={item.id} className="grid gap-1.5 px-4 py-2.5">
                        <div className="flex items-center gap-2 text-sm">
                            {item.status === 'done' && (
                                <CheckCircle2 className="size-4 shrink-0 text-success" />
                            )}
                            {item.status === 'failed' && (
                                <XCircle className="size-4 shrink-0 text-destructive" />
                            )}
                            {(item.status === 'uploading' ||
                                item.status === 'waiting') && (
                                <LoaderCircle className="size-4 shrink-0 animate-spin text-muted-foreground" />
                            )}
                            <span className="truncate font-medium">
                                {item.name}
                            </span>
                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                {Math.round(item.progress * 100)}%
                            </span>
                        </div>
                        {item.status === 'uploading' && (
                            <div className="h-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full bg-primary transition-[width]"
                                    style={{
                                        width: `${item.progress * 100}%`,
                                    }}
                                />
                            </div>
                        )}
                        {item.message && (
                            <p className="text-xs text-destructive">
                                {item.message}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
