import { Form } from '@inertiajs/react';
import { ArrowRight, Globe, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store as storeDomain } from '@/routes/domains';
import { store as storePointer } from '@/routes/pointers';
import { store as storeSubdomain } from '@/routes/subdomains';
import type { AccountLimits } from '@/types';

type WebsiteType = 'domain' | 'subdomain' | 'pointer';

type PointerMode = 'alias' | 'redirect';

/** A domain of the account that subdomains and pointers can belong to. */
export type ParentDomain = {
    id: number;
    name: string;
    display_name: string;
};

const tabs: { value: WebsiteType; label: string }[] = [
    { value: 'domain', label: 'Domain' },
    { value: 'subdomain', label: 'Subdomain' },
    { value: 'pointer', label: 'Pointer' },
];

const quotaKeys = {
    domain: 'domains',
    subdomain: 'subdomains',
    pointer: 'pointers',
} as const;

const unitLabels: Record<WebsiteType, string> = {
    domain: 'domain',
    subdomain: 'subdomain',
    pointer: 'pointer',
};

/**
 * Adds a website to the account: a domain, a subdomain of one of its
 * domains, or a pointer to one of them, each within its package limit.
 */
export function AddWebsitePanel({
    parents,
    limits,
}: {
    parents: ParentDomain[];
    limits: AccountLimits;
}) {
    const [type, setType] = useState<WebsiteType>('domain');

    const quota = limits.quotas[quotaKeys[type]];
    const used = quota.used ?? 0;
    const isAtLimit = quota.limit !== null && used >= quota.limit;
    const needsParent = type !== 'domain' && parents.length === 0;

    return (
        <Panel
            title={`Tambah ${unitLabels[type]}`}
            description={<TypeDescription type={type} />}
            action={
                quota.limit !== null && (
                    <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                        {used} dari {quota.limit} {unitLabels[type]}
                    </span>
                )
            }
        >
            <div className="grid max-w-2xl gap-4">
                <div
                    role="tablist"
                    aria-label="Jenis website"
                    className="inline-flex w-fit rounded-xl bg-muted p-1"
                >
                    {tabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            role="tab"
                            aria-selected={type === tab.value}
                            onClick={() => setType(tab.value)}
                            className={cn(
                                'rounded-lg px-4 py-1.5 text-sm font-semibold transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                type === tab.value
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {needsParent ? (
                    <p className="text-sm text-muted-foreground">
                        Tambahkan domain dulu, lalu {unitLabels[type]}-nya bisa
                        ditambahkan di sini.
                    </p>
                ) : (
                    <>
                        {type === 'domain' && (
                            <DomainForm disabled={isAtLimit} />
                        )}
                        {type === 'subdomain' && (
                            <SubdomainForm
                                parents={parents}
                                disabled={isAtLimit}
                            />
                        )}
                        {type === 'pointer' && (
                            <PointerForm
                                parents={parents}
                                disabled={isAtLimit}
                            />
                        )}
                        {isAtLimit && (
                            <p
                                id="website-limit"
                                className="text-sm text-muted-foreground"
                            >
                                {quota.limit === 0
                                    ? `Paket kamu tidak termasuk ${unitLabels[type]}.`
                                    : `Paket kamu sudah mencapai batas ${quota.limit} ${unitLabels[type]}.`}{' '}
                                Hapus yang tidak dipakai, atau minta paket yang
                                lebih besar ke penyedia hosting kamu.
                            </p>
                        )}
                    </>
                )}
            </div>
        </Panel>
    );
}

function TypeDescription({ type }: { type: WebsiteType }) {
    switch (type) {
        case 'domain':
            return (
                <>
                    File website diletakkan di{' '}
                    <Code>domains/&lt;domain&gt;/public_html</Code> di folder
                    home kamu.
                </>
            );
        case 'subdomain':
            return (
                <>
                    Subdomain punya folder sendiri,{' '}
                    <Code>domains/&lt;subdomain&gt;/public_html</Code>, dan bisa
                    memasang SSL sendiri.
                </>
            );
        case 'pointer':
            return 'Domain lain yang menampilkan website salah satu domain kamu, atau mengalihkan pengunjungnya ke sana.';
    }
}

function Code({ children }: { children: ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
            {children}
        </code>
    );
}

function DomainForm({ disabled }: { disabled: boolean }) {
    return (
        <Form
            {...storeDomain.form()}
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <label htmlFor="domain" className="sr-only">
                        Nama domain
                    </label>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <div className="relative flex-1">
                            <Globe className="pointer-events-none absolute top-1/2 left-3.5 size-[18px] -translate-y-1/2 text-muted-foreground" />
                            <NameInput
                                id="domain"
                                name="domain"
                                disabled={disabled}
                                placeholder="contoh.com"
                                invalid={Boolean(errors.domain)}
                                className="pl-10"
                            />
                        </div>
                        <SubmitButton
                            processing={processing}
                            disabled={disabled}
                        >
                            Tambah domain
                        </SubmitButton>
                    </div>
                    <InputError message={errors.domain} />
                </>
            )}
        </Form>
    );
}

function SubdomainForm({
    parents,
    disabled,
}: {
    parents: ParentDomain[];
    disabled: boolean;
}) {
    const [parentId, setParentId] = useState(String(parents[0].id));
    const [label, setLabel] = useState('');
    const parent =
        parents.find((option) => String(option.id) === parentId) ?? parents[0];

    return (
        <Form
            {...storeSubdomain.form()}
            options={{ preserveScroll: true }}
            onSuccess={() => setLabel('')}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label htmlFor="subdomain" className="sr-only">
                            Nama subdomain
                        </label>
                        <NameInput
                            id="subdomain"
                            name="name"
                            value={label}
                            onChange={setLabel}
                            disabled={disabled}
                            placeholder="blog"
                            maxLength={63}
                            invalid={Boolean(errors.name)}
                            className="sm:max-w-48"
                        />
                        <span
                            aria-hidden
                            className="hidden text-lg font-bold text-muted-foreground sm:block"
                        >
                            .
                        </span>
                        <ParentSelect
                            id="subdomain-parent"
                            label="Domain induk"
                            parents={parents}
                            value={parentId}
                            onChange={setParentId}
                            invalid={Boolean(errors.domain_id)}
                        />
                        <SubmitButton
                            processing={processing}
                            disabled={disabled}
                        >
                            Tambah subdomain
                        </SubmitButton>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Folder:{' '}
                        <code className="font-mono">
                            domains/{label.trim() || 'blog'}.{parent.name}
                            /public_html
                        </code>
                    </p>
                    <InputError message={errors.name ?? errors.domain_id} />
                </>
            )}
        </Form>
    );
}

const pointerModes: {
    value: PointerMode;
    label: string;
    description: string;
}[] = [
    {
        value: 'alias',
        label: 'Alias',
        description: 'Menampilkan website domain induk dengan nama pointer.',
    },
    {
        value: 'redirect',
        label: 'Alihkan',
        description: 'Mengirim setiap pengunjung ke domain induk (301).',
    },
];

function PointerForm({
    parents,
    disabled,
}: {
    parents: ParentDomain[];
    disabled: boolean;
}) {
    const [parentId, setParentId] = useState(String(parents[0].id));
    const [mode, setMode] = useState<PointerMode>('alias');

    return (
        <Form
            {...storePointer.form()}
            resetOnSuccess={['name']}
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label htmlFor="pointer" className="sr-only">
                            Nama domain pointer
                        </label>
                        <div className="relative flex-1">
                            <Globe className="pointer-events-none absolute top-1/2 left-3.5 size-[18px] -translate-y-1/2 text-muted-foreground" />
                            <NameInput
                                id="pointer"
                                name="name"
                                disabled={disabled}
                                placeholder="domain-lain.com"
                                invalid={Boolean(errors.name)}
                                className="pl-10"
                            />
                        </div>
                        <ArrowRight
                            aria-hidden
                            className="hidden size-5 shrink-0 text-muted-foreground sm:block"
                        />
                        <ParentSelect
                            id="pointer-parent"
                            label="Domain tujuan"
                            parents={parents}
                            value={parentId}
                            onChange={setParentId}
                            invalid={Boolean(errors.domain_id)}
                        />
                    </div>

                    <input type="hidden" name="mode" value={mode} />
                    <div
                        role="radiogroup"
                        aria-label="Cara kerja pointer"
                        className="grid gap-2 sm:grid-cols-2"
                    >
                        {pointerModes.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={mode === option.value}
                                onClick={() => setMode(option.value)}
                                className={cn(
                                    'rounded-xl border bg-card p-3 text-left transition-[border-color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    mode === option.value
                                        ? 'border-primary ring-[3px] ring-primary/15'
                                        : 'hover:border-primary/40',
                                )}
                            >
                                <span className="block text-sm font-semibold">
                                    {option.label}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {option.description}
                                </span>
                            </button>
                        ))}
                    </div>

                    <div>
                        <SubmitButton
                            processing={processing}
                            disabled={disabled}
                        >
                            Tambah pointer
                        </SubmitButton>
                    </div>
                    <InputError
                        message={errors.name ?? errors.domain_id ?? errors.mode}
                    />
                </>
            )}
        </Form>
    );
}

function NameInput({
    id,
    name,
    value,
    onChange,
    disabled,
    placeholder,
    maxLength = 253,
    invalid,
    className,
}: {
    id: string;
    name: string;
    value?: string;
    onChange?: (value: string) => void;
    disabled: boolean;
    placeholder: string;
    maxLength?: number;
    invalid: boolean;
    className?: string;
}) {
    return (
        <Input
            id={id}
            name={name}
            required
            disabled={disabled}
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={maxLength}
            placeholder={placeholder}
            className={className}
            aria-invalid={invalid || undefined}
            aria-describedby={disabled ? 'website-limit' : undefined}
            {...(onChange
                ? {
                      value,
                      onChange: (event) => onChange(event.target.value),
                  }
                : {})}
        />
    );
}

/**
 * Picks the domain a subdomain or pointer belongs to, sent as `domain_id`.
 */
function ParentSelect({
    id,
    label,
    parents,
    value,
    onChange,
    invalid,
}: {
    id: string;
    label: string;
    parents: ParentDomain[];
    value: string;
    onChange: (value: string) => void;
    invalid: boolean;
}) {
    return (
        <>
            <input type="hidden" name="domain_id" value={value} />
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    aria-label={label}
                    aria-invalid={invalid || undefined}
                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 sm:w-auto sm:min-w-48"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="rounded-xl">
                    {parents.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.display_name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}

function SubmitButton({
    processing,
    disabled,
    children,
}: {
    processing: boolean;
    disabled: boolean;
    children: ReactNode;
}) {
    return (
        <Button type="submit" size="lg" disabled={processing || disabled}>
            {processing ? <Spinner /> : <Plus />}
            {children}
        </Button>
    );
}
