import { Form, Head } from '@inertiajs/react';
import { History, Search } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { AccountScope } from '@/lib/scoped-routes';
import { index as adminLoginHistory } from '@/routes/admin/login-history';
import { index as resellerLoginHistory } from '@/routes/reseller/login-history';
import type { Paginated } from '@/types';

type LoginRow = {
    id: number;
    succeeded: boolean;
    /** The account, while it exists. */
    username: string | null;
    /** For an attempt on a name that is no account: the name tried. */
    attempted: string | null;
    ip_address: string | null;
    browser: string | null;
    os: string | null;
    created_at: string;
};

const resultOptions = [
    { value: 'succeeded', label: 'Berhasil' },
    { value: 'failed', label: 'Gagal' },
];

/**
 * The logins and failed attempts of every account (administrators) or of
 * a reseller's hosting accounts, newest first.
 */
export default function LoginHistoryIndex({
    scope,
    logins,
    filters,
}: {
    scope: AccountScope;
    logins: Paginated<LoginRow>;
    filters: { search: string; result: string };
}) {
    const [result, setResult] = useState(filters.result || 'all');
    const route = scope === 'admin' ? adminLoginHistory : resellerLoginHistory;
    const isFiltered = filters.search !== '' || filters.result !== '';

    return (
        <>
            <Head title="Riwayat Login" />

            <PageHeader
                title="Riwayat Login"
                description={
                    scope === 'admin'
                        ? 'Login ke panel dari semua akun, termasuk yang gagal dan percobaan dengan nama yang tidak terdaftar.'
                        : 'Login ke panel dari akun-akun Anda, termasuk yang gagal.'
                }
            />

            <Panel
                flush
                title="Login"
                description={`${logins.total} catatan`}
                action={
                    <Form
                        {...route.form()}
                        options={{ preserveState: true, replace: true }}
                        className="flex w-full max-w-xl flex-wrap items-center gap-2"
                    >
                        {result !== 'all' && (
                            <input type="hidden" name="result" value={result} />
                        )}
                        <Select value={result} onValueChange={setResult}>
                            <SelectTrigger
                                aria-label="Filter hasil"
                                className="h-10 w-36 rounded-xl bg-card"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                <SelectItem value="all">Semua hasil</SelectItem>
                                {resultOptions.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <div className="relative min-w-48 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Cari username atau alamat IP"
                                aria-label="Cari login"
                                maxLength={100}
                                className="h-10 pl-10"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Cari
                        </Button>
                    </Form>
                }
            >
                {logins.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <History className="size-6" />
                        </span>
                        <p className="text-sm text-muted-foreground">
                            {isFiltered
                                ? 'Tidak ada login yang cocok dengan filter ini.'
                                : 'Belum ada login yang tercatat.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Waktu</th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Hasil</th>
                                    <th className={tableStyles.th}>
                                        Perangkat
                                    </th>
                                    <th className={tableStyles.th}>
                                        Alamat IP
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logins.data.map((login) => (
                                    <tr
                                        key={login.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                            title={formatDateTime(
                                                login.created_at,
                                            )}
                                        >
                                            {formatRelative(login.created_at)}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {login.username ? (
                                                <span className="font-semibold">
                                                    {login.username}
                                                </span>
                                            ) : login.attempted ? (
                                                <>
                                                    <span className="font-mono text-xs break-all">
                                                        {login.attempted}
                                                    </span>
                                                    <p className="text-xs text-muted-foreground">
                                                        tidak terdaftar
                                                    </p>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Akun sudah dihapus
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={
                                                    login.succeeded
                                                        ? 'active'
                                                        : 'failed'
                                                }
                                                label={
                                                    login.succeeded
                                                        ? 'Berhasil'
                                                        : 'Gagal'
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            {[login.browser, login.os]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs`}
                                        >
                                            {login.ip_address ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {logins.last_page > 1 && (
                    <div className="border-t px-6 py-4">
                        <Pagination page={logins} />
                    </div>
                )}
            </Panel>
        </>
    );
}
