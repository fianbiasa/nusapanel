import { Form, router, useHttp } from '@inertiajs/react';
import { FileUp, Globe, Plus, Trash2, Wrench } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { uploadFile } from '@/lib/file-manager';
import {
    destroy as destroyRemoteHost,
    store as storeRemoteHost,
} from '@/routes/database-remote-hosts';
import { importMethod as importRoute, maintenance } from '@/routes/databases';
import { upload } from '@/routes/files';

export type RemoteAccess = {
    enabled: boolean;
    host: string | null;
    hosts: { id: number; host: string }[];
    max: number;
};

/**
 * The hosts besides the server the account's database users may connect
 * from, with how to connect from them.
 */
export function RemoteAccessPanel({ remote }: { remote: RemoteAccess }) {
    return (
        <Panel
            title="Akses dari luar server"
            description={`Izinkan aplikasi di komputer atau server lain terhubung ke database kamu, dengan user database yang sama. Paling banyak ${remote.max} host.`}
        >
            <div className="grid gap-5">
                {!remote.enabled && (
                    <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        Administrator belum membuka akses database dari luar
                        server. Host yang ditambahkan di sini baru bisa
                        terhubung setelah dibuka.
                    </p>
                )}

                <Form
                    {...storeRemoteHost.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-wrap items-start gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid min-w-64 flex-1 gap-1.5">
                                <Input
                                    name="host"
                                    required
                                    maxLength={64}
                                    placeholder="203.0.113.7, 192.168.1.0/24 atau %"
                                    aria-label="Host"
                                    className="font-mono"
                                    aria-invalid={
                                        errors.host ? true : undefined
                                    }
                                />
                                <InputError message={errors.host} />
                            </div>
                            <Button disabled={processing}>
                                {processing ? <Spinner /> : <Plus />}
                                Izinkan
                            </Button>
                        </>
                    )}
                </Form>

                {remote.hosts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada; database hanya bisa dipakai dari server ini.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {remote.hosts.map((host) => (
                            <li
                                key={host.id}
                                className="flex items-center justify-between gap-3 px-4 py-2"
                            >
                                <span className="flex items-center gap-2 font-mono text-sm">
                                    <Globe className="size-4 text-muted-foreground" />
                                    {host.host === '%'
                                        ? '% (semua host)'
                                        : host.host}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Hapus ${host.host}`}
                                    onClick={() =>
                                        router.delete(
                                            destroyRemoteHost.url(host.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {remote.host && (
                    <p className="text-sm text-muted-foreground">
                        Dari luar, hubungkan ke{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">
                            {remote.host}
                        </code>{' '}
                        port 3306. Port itu mungkin juga perlu dibuka di
                        firewall penyedia VPS.
                    </p>
                )}
            </div>
        </Panel>
    );
}

/**
 * Upload a .sql or .sql.gz file and run it in the database, as the
 * account. The file goes to ~/tmp first and is removed after the import.
 */
export function DatabaseImportForm({
    database,
    chunkBytes,
    onSuccess,
}: {
    database: { id: number; name: string };
    chunkBytes: number;
    onSuccess: () => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (file === null) {
            return;
        }

        const extension = file.name.toLowerCase().endsWith('.gz')
            ? '.sql.gz'
            : '.sql';
        const path = `tmp/rayxo-import-${crypto.randomUUID()}${extension}`;

        setError(null);
        setProgress(0);

        try {
            await uploadFile({
                url: upload.url(),
                file,
                path,
                overwrite: true,
                chunkBytes,
                onProgress: setProgress,
            });
        } catch (exception) {
            setProgress(null);
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'Upload gagal.',
            );

            return;
        }

        router.post(
            importRoute.url(database.id),
            { path },
            {
                preserveScroll: true,
                onSuccess,
                onError: (errors) => {
                    setProgress(null);
                    setError(Object.values(errors)[0] ?? 'Import gagal.');
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-5">
            <DialogHeader>
                <DialogTitle>Import ke {database.name}</DialogTitle>
                <DialogDescription>
                    File .sql atau .sql.gz (misalnya hasil export phpMyAdmin
                    atau mysqldump). Isinya dijalankan di database ini dan bisa
                    menimpa tabel yang namanya sama.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="import-file">File</Label>
                <Input
                    id="import-file"
                    type="file"
                    accept=".sql,.gz,application/sql,application/gzip"
                    required
                    onChange={(event) =>
                        setFile(event.target.files?.[0] ?? null)
                    }
                />
                {progress !== null && (
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full bg-primary transition-all"
                            style={{ width: `${Math.round(progress * 100)}%` }}
                        />
                    </div>
                )}
                <InputError message={error ?? undefined} />
            </div>

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Batal
                    </Button>
                </DialogClose>
                <Button
                    type="submit"
                    disabled={file === null || progress !== null}
                >
                    {progress !== null ? <Spinner /> : <FileUp />}
                    {progress !== null && progress < 1
                        ? `Mengunggah ${Math.round(progress * 100)}%`
                        : 'Import'}
                </Button>
            </DialogFooter>
        </form>
    );
}

const operations: { value: string; label: string; description: string }[] = [
    {
        value: 'check',
        label: 'Periksa',
        description: 'Cek apakah ada tabel yang rusak.',
    },
    {
        value: 'repair',
        label: 'Perbaiki',
        description:
            'Perbaiki tabel MyISAM/Aria yang rusak; tabel InnoDB memperbaiki dirinya sendiri.',
    },
    {
        value: 'optimize',
        label: 'Optimalkan',
        description: 'Rapikan tabel dan kembalikan ruang yang tidak terpakai.',
    },
    {
        value: 'analyze',
        label: 'Analisis',
        description: 'Perbarui statistik indeks supaya query lebih cepat.',
    },
];

/** Check, repair, optimize or analyze the tables of a database. */
export function DatabaseMaintenanceForm({
    database,
}: {
    database: { id: number; name: string };
}) {
    const http = useHttp<{ operation: string }, { lines: string[] }>({
        operation: 'check',
    });
    const [lines, setLines] = useState<string[] | null>(null);

    const run = async () => {
        setLines(null);

        try {
            const response = await http.post(maintenance.url(database.id));

            setLines(response?.lines ?? []);
        } catch {
            // What went wrong is in http.errors.
        }
    };

    return (
        <div className="grid gap-5">
            <DialogHeader>
                <DialogTitle>Periksa tabel {database.name}</DialogTitle>
                <DialogDescription>
                    Dijalankan di semua tabel database ini. Pada database besar
                    bisa makan waktu beberapa menit.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2 sm:grid-cols-2">
                {operations.map((option) => (
                    <label
                        key={option.value}
                        className="flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                    >
                        <input
                            type="radio"
                            name="operation"
                            value={option.value}
                            checked={http.data.operation === option.value}
                            onChange={() =>
                                http.setData('operation', option.value)
                            }
                            className="mt-1 accent-primary"
                        />
                        <span className="grid gap-0.5">
                            <span className="font-medium">{option.label}</span>
                            <span className="text-xs text-muted-foreground">
                                {option.description}
                            </span>
                        </span>
                    </label>
                ))}
            </div>

            <InputError message={http.errors.operation} />

            {lines !== null && (
                <pre className="max-h-64 overflow-auto rounded-xl bg-muted px-3 py-2 font-mono text-xs whitespace-pre-wrap">
                    {lines.length === 0 ? 'Tidak ada tabel.' : lines.join('\n')}
                </pre>
            )}

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Tutup
                    </Button>
                </DialogClose>
                <Button onClick={run} disabled={http.processing}>
                    {http.processing ? <Spinner /> : <Wrench />}
                    Jalankan
                </Button>
            </DialogFooter>
        </div>
    );
}
