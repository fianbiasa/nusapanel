import { Form, Head, Link } from '@inertiajs/react';
import { Check, FileCog, History } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, restore, update } from '@/routes/admin/system-files';

type EditableFile = { id: string; path: string };

type OpenFile = EditableFile & {
    content: string;
    /** What the file was when it was read; a save names it. */
    hash: string;
    backups: { id: string; size: number; created_at: string }[];
};

/** What each file is for, in the order the agent lists them. */
function describe(id: string): { title: string; description: string } {
    const php = /^php-(\d\.\d)$/.exec(id);

    if (php) {
        return {
            title: `PHP ${php[1]}: semua website`,
            description: `php.ini tambahan untuk pool PHP ${php[1]} semua akun (bukan untuk panel), dibaca setelah pengaturan sistem. Pool PHP ${php[1]} dinyalakan ulang saat disimpan.`,
        };
    }

    return (
        {
            'nginx-http': {
                title: 'Nginx: blok http',
                description:
                    'Tambahan untuk konfigurasi Nginx utama, misalnya gzip atau batas koneksi. Diuji dengan nginx -t sebelum dipakai.',
            },
            'nginx-website': {
                title: 'Nginx: semua website',
                description:
                    'Disisipkan ke server block setiap website, misalnya header atau location tambahan. Diuji dengan nginx -t.',
            },
            'apache-website': {
                title: 'Apache: semua website',
                description:
                    'Disisipkan ke VirtualHost Apache setiap website. Diuji dengan apache2ctl configtest.',
            },
            mariadb: {
                title: 'MariaDB',
                description:
                    'Opsi MariaDB di bagian seperti [mariadbd], misalnya max_connections. MariaDB di-restart; kalau gagal menyala, file lama dipasang lagi.',
            },
            'php-fpm-pool': {
                title: 'Template pool PHP-FPM',
                description:
                    'pm, pm.max_children, pm.max_requests, dan pengaturan proses lain untuk pool setiap website. Semua website diterapkan ulang setelah disimpan.',
            },
        }[id] ?? { title: id, description: '' }
    );
}

/**
 * The server's configuration files administrators edit: RAYXO's own
 * additions, tested before they are used and backed up.
 */
export default function SystemFilesIndex({
    files,
    file,
}: {
    files: EditableFile[] | null;
    file: OpenFile | null;
}) {
    return (
        <>
            <Head title="File Konfigurasi" />

            <PageHeader
                title="File Konfigurasi"
                description="Tambahan konfigurasi Nginx, Apache, PHP, dan MariaDB milik RAYXO; setup.sh tidak pernah menimpanya. Setiap perubahan diuji dulu, dibatalkan otomatis kalau ditolak, dan versi sebelumnya disimpan."
            />

            {files === null ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">
                        Agent tidak bisa dihubungi, jadi file tidak bisa
                        ditampilkan.
                    </p>
                </Panel>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
                    <Panel flush title="File">
                        <nav className="grid gap-1 px-3 pb-3">
                            {files.map((item) => (
                                <Link
                                    key={item.id}
                                    href={index({ query: { file: item.id } })}
                                    preserveScroll
                                    className={cn(
                                        'rounded-xl px-3 py-2 text-sm hover:bg-accent',
                                        file?.id === item.id &&
                                            'bg-accent font-semibold text-accent-foreground',
                                    )}
                                >
                                    {describe(item.id).title}
                                </Link>
                            ))}
                        </nav>
                    </Panel>

                    {file && <Editor key={file.hash} file={file} />}
                </div>
            )}
        </>
    );
}

function Editor({ file }: { file: OpenFile }) {
    const { title, description } = describe(file.id);

    return (
        <div className="grid min-w-0 content-start gap-6">
            <Panel title={title} description={description}>
                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="file" value={file.id} />
                            <input
                                type="hidden"
                                name="hash"
                                value={file.hash}
                            />
                            <p className="flex items-center gap-2 font-mono text-xs text-muted-foreground">
                                <FileCog className="size-4" />
                                {file.path}
                            </p>
                            <textarea
                                name="content"
                                aria-label={title}
                                defaultValue={file.content}
                                spellCheck={false}
                                rows={20}
                                className="w-full rounded-xl border bg-card p-3 font-mono text-sm leading-relaxed outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder="Kosong: tidak ada tambahan."
                            />
                            <InputError
                                message={errors.content}
                                className="font-mono text-xs whitespace-pre-wrap"
                            />
                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Check />}
                                    Uji dan simpan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>

            <Panel
                flush
                title="Versi sebelumnya"
                description="Sepuluh versi terakhir sebelum setiap perubahan."
            >
                {file.backups.length === 0 ? (
                    <p className="px-6 pb-6 text-sm text-muted-foreground">
                        Belum ada versi sebelumnya.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {file.backups.map((backup) => (
                            <li
                                key={backup.id}
                                className="flex flex-wrap items-center gap-3 px-6 py-3 text-sm"
                            >
                                <History className="size-4 text-muted-foreground" />
                                <span>{formatDateTime(backup.created_at)}</span>
                                <span className="text-muted-foreground">
                                    {backup.size.toLocaleString('id-ID')} byte
                                </span>
                                <Form
                                    {...restore.form()}
                                    options={{ preserveScroll: true }}
                                    className="ml-auto"
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="file"
                                                value={file.id}
                                            />
                                            <input
                                                type="hidden"
                                                name="backup"
                                                value={backup.id}
                                            />
                                            <input
                                                type="hidden"
                                                name="hash"
                                                value={file.hash}
                                            />
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Pulihkan
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}
