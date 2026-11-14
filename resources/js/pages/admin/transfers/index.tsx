import { Head, router, usePoll } from '@inertiajs/react';
import { ArrowLeftRight, PlugZap, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { BackupChoice } from '@/components/backup-restore-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { connect, store } from '@/routes/admin/transfers';

type Transfer = {
    id: number;
    username: string;
    source: string;
    status: string;
    error: string | null;
    account_status: string | null;
    created_at: string | null;
};

type SourceAccount = {
    username: string;
    domain: string | null;
    package: string | null;
    status: string;
    taken: boolean;
};

type Source = {
    source: { username: string; role: string };
    accounts: SourceAccount[];
};

type Props = {
    transfers: Transfer[];
    going: boolean;
    permissions: string[];
};

const statusLabels: Record<string, string> = {
    queued: 'Antre',
    backing_up: 'Backup di server asal',
    downloading: 'Mengunduh',
    restoring: 'Dibuat di sini',
    failed: 'Gagal',
};

export default function Transfers({ transfers, going }: Props) {
    const [form, setForm] = useState({
        source_url: '',
        api_key: '',
        verify_tls: true,
    });
    const [source, setSource] = useState<Source | null>(null);
    const [chosen, setChosen] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    usePoll(5000, { only: ['transfers', 'going'] }, { autoStart: going });

    // The other panel's accounts come once, as flash data.
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.transfer_source) {
                const found = flash.transfer_source as Source;
                setSource(found);
                setChosen(
                    found.accounts
                        .filter((account) => !account.taken)
                        .map((account) => account.username),
                );
            }
        });
    }, []);

    const submit = (url: string, data: Record<string, unknown>) => {
        setProcessing(true);
        setErrors({});
        router.post(
            url,
            { ...form, ...data },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (url === store.url()) {
                        setSource(null);
                        setChosen([]);
                    }
                },
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const free = source?.accounts.filter((account) => !account.taken) ?? [];

    return (
        <>
            <Head title="Transfer" />

            <PageHeader
                title="Transfer"
                description="Pindahkan akun hosting dari server RAYXO lain ke server ini."
            />

            <Panel
                title="Hubungkan ke server asal"
                description="Di panel server asal, buat login key (Settings > Login key) milik admin atau reseller dengan izin: Lihat akun hosting, Buat backup akun hosting, serta Lihat dan unduh backup akun hosting. Server asal membuat backup tiap akun yang dipilih, server ini mengunduhnya lalu membuat akunnya lagi di sini. Akun di server asal tidak diubah; setelah selesai, arahkan DNS domain-domainnya ke server ini."
            >
                <div className="grid gap-4 sm:max-w-xl">
                    <div className="grid gap-2">
                        <Label htmlFor="transfer-url">Alamat panel asal</Label>
                        <Input
                            id="transfer-url"
                            value={form.source_url}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    source_url: event.target.value,
                                })
                            }
                            placeholder="https://server-lama.domain.com:2222"
                            autoComplete="off"
                        />
                        <InputError message={errors.source_url} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="transfer-key">Login key</Label>
                        <Input
                            id="transfer-key"
                            type="password"
                            value={form.api_key}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    api_key: event.target.value,
                                })
                            }
                            placeholder="rxk_..."
                            autoComplete="off"
                            className="font-mono"
                        />
                        <InputError message={errors.api_key} />
                    </div>
                    <BackupChoice
                        id="transfer-verify"
                        checked={form.verify_tls}
                        onChange={(verify_tls) =>
                            setForm({ ...form, verify_tls })
                        }
                        label="Periksa sertifikat HTTPS panel asal"
                        hint="Matikan hanya kalau panel asal memakai sertifikat self-signed."
                    />
                    <div>
                        <Button
                            onClick={() => submit(connect.url(), {})}
                            disabled={
                                processing ||
                                form.source_url === '' ||
                                form.api_key === ''
                            }
                        >
                            {processing && !source ? <Spinner /> : <PlugZap />}
                            Hubungkan
                        </Button>
                    </div>
                </div>
            </Panel>

            {source && (
                <Panel
                    flush
                    title={`Akun di server asal (${source.accounts.length})`}
                    description={`Masuk sebagai ${source.source.username} (${source.source.role === 'admin' ? 'admin' : 'reseller'}). Akun yang username-nya sudah dipakai di server ini tidak bisa dipindahkan. Paket dipilih dengan nama yang sama kalau ada di sini.`}
                    action={
                        <Button
                            onClick={() =>
                                submit(store.url(), { usernames: chosen })
                            }
                            disabled={processing || chosen.length === 0}
                        >
                            {processing ? <Spinner /> : <Send />}
                            Pindahkan {chosen.length} akun
                        </Button>
                    }
                >
                    <InputError
                        className="px-6 pb-3"
                        message={errors.usernames}
                    />
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>
                                        <Checkbox
                                            aria-label="Pilih semua"
                                            checked={
                                                free.length > 0 &&
                                                chosen.length === free.length
                                            }
                                            onCheckedChange={(checked) =>
                                                setChosen(
                                                    checked === true
                                                        ? free.map(
                                                              (account) =>
                                                                  account.username,
                                                          )
                                                        : [],
                                                )
                                            }
                                        />
                                    </th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Domain</th>
                                    <th className={tableStyles.th}>Paket</th>
                                    <th className={tableStyles.th}>Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {source.accounts.map((account) => (
                                    <tr
                                        key={account.username}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <Checkbox
                                                aria-label={`Pilih ${account.username}`}
                                                disabled={account.taken}
                                                checked={chosen.includes(
                                                    account.username,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setChosen(
                                                        checked === true
                                                            ? [
                                                                  ...chosen,
                                                                  account.username,
                                                              ]
                                                            : chosen.filter(
                                                                  (name) =>
                                                                      name !==
                                                                      account.username,
                                                              ),
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="font-medium">
                                                {account.username}
                                            </div>
                                            {account.taken && (
                                                <div className="text-xs text-destructive">
                                                    Username sudah dipakai di
                                                    sini
                                                </div>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.domain ?? '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.package ?? 'Tanpa paket'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={account.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}

            <Panel
                flush
                title="Riwayat transfer"
                description="Setelah akun dibuat di sini, domain, database, email, dan file-nya kembali di latar belakang; perkembangannya ada di menu Backup bagian Akun yang dibuat lagi dari backup."
            >
                {transfers.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <ArrowLeftRight className="size-8" />
                        Belum ada akun yang dipindahkan.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Dari</th>
                                    <th className={tableStyles.th}>Mulai</th>
                                    <th className={tableStyles.th}>Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {transfers.map((transfer) => (
                                    <tr
                                        key={transfer.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} font-medium`}
                                        >
                                            {transfer.username}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {transfer.source}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {transfer.created_at
                                                ? formatDateTime(
                                                      transfer.created_at,
                                                  )
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={transfer.status}
                                                label={
                                                    statusLabels[
                                                        transfer.status
                                                    ]
                                                }
                                            />
                                            {transfer.error && (
                                                <p className="mt-1 max-w-md text-xs text-destructive">
                                                    {transfer.error}
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}
