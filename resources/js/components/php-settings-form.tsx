import { Form } from '@inertiajs/react';
import { RotateCcw, SlidersHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatMegabytes } from '@/lib/format';
import { update } from '@/routes/domains/php-settings';

/** What the page offers for PHP settings, and their defaults. */
export type PhpSettingOptions = {
    defaults: Record<string, string>;
    memory_limits: string[];
    sizes: string[];
    timezones: string[];
};

/** The select's value for "the server's time zone"; Radix items cannot be empty. */
const serverTimezone = 'server';

/** Time zones of Indonesia first, then the rest. */
export const indonesianTimezones = [
    'Asia/Jakarta',
    'Asia/Makassar',
    'Asia/Jayapura',
];

function sizeLabel(size: string): string {
    return formatMegabytes(parseInt(size, 10));
}

/**
 * The php.ini settings of one website's PHP-FPM pool. Every field is sent;
 * the panel keeps only what differs from the defaults.
 */
export function PhpSettingsForm({
    domain,
    settings,
    options,
    onSuccess,
}: {
    domain: { id: number; display_name: string; php_version: string };
    settings: Record<string, string>;
    options: PhpSettingOptions;
    onSuccess: () => void;
}) {
    const [values, setValues] = useState<Record<string, string>>(() => ({
        ...settings,
        'date.timezone': settings['date.timezone'] ?? '',
    }));

    const set = (key: string, value: string) =>
        setValues((current) => ({ ...current, [key]: value }));

    const restoreDefaults = () =>
        setValues({ ...options.defaults, 'date.timezone': '' });

    const timezones = [
        ...indonesianTimezones,
        ...options.timezones.filter(
            (zone) => !indonesianTimezones.includes(zone),
        ),
    ];

    return (
        <Form
            {...update.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Pengaturan PHP {domain.display_name}
                        </DialogTitle>
                        <DialogDescription>
                            Berlaku untuk website ini saja (PHP{' '}
                            {domain.php_version}), juga untuk pointer alias-nya.
                            Script website masih bisa mengubahnya sendiri lewat
                            ini_set atau .user.ini.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <SizeField
                            id="memory_limit"
                            label="Batas memori"
                            value={values.memory_limit}
                            sizes={options.memory_limits}
                            onChange={(value) => set('memory_limit', value)}
                            error={errors.memory_limit}
                        />
                        <SizeField
                            id="upload_max_filesize"
                            label="Batas upload"
                            value={values.upload_max_filesize}
                            sizes={options.sizes}
                            onChange={(value) =>
                                set('upload_max_filesize', value)
                            }
                            error={errors.upload_max_filesize}
                        />
                        <SizeField
                            id="post_max_size"
                            label="Batas POST"
                            value={values.post_max_size}
                            sizes={options.sizes}
                            onChange={(value) => set('post_max_size', value)}
                            error={errors.post_max_size}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <NumberField
                            id="max_execution_time"
                            label="Eksekusi (detik)"
                            value={values.max_execution_time}
                            min={10}
                            max={300}
                            onChange={(value) =>
                                set('max_execution_time', value)
                            }
                            error={errors.max_execution_time}
                        />
                        <NumberField
                            id="max_input_time"
                            label="Input (detik)"
                            value={values.max_input_time}
                            min={10}
                            max={300}
                            onChange={(value) => set('max_input_time', value)}
                            error={errors.max_input_time}
                        />
                        <NumberField
                            id="max_input_vars"
                            label="Jumlah input"
                            value={values.max_input_vars}
                            min={1000}
                            max={100000}
                            onChange={(value) => set('max_input_vars', value)}
                            error={errors.max_input_vars}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="date_timezone"
                            label="Zona waktu"
                            error={errors.date_timezone}
                        >
                            <input
                                type="hidden"
                                name="date_timezone"
                                value={values['date.timezone']}
                            />
                            <Select
                                value={
                                    values['date.timezone'] || serverTimezone
                                }
                                onValueChange={(value) =>
                                    set(
                                        'date.timezone',
                                        value === serverTimezone ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="date_timezone"
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-72 rounded-xl">
                                    <SelectItem value={serverTimezone}>
                                        Bawaan server
                                    </SelectItem>
                                    {timezones.map((zone) => (
                                        <SelectItem key={zone} value={zone}>
                                            {zone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <div className="flex items-start gap-3 sm:pt-7">
                            <input
                                type="hidden"
                                name="display_errors"
                                value={
                                    values.display_errors === 'on' ? '1' : '0'
                                }
                            />
                            <Checkbox
                                id="display_errors"
                                checked={values.display_errors === 'on'}
                                onCheckedChange={(checked) =>
                                    set(
                                        'display_errors',
                                        checked === true ? 'on' : 'off',
                                    )
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="display_errors">
                                    Tampilkan error PHP
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Hanya untuk mencari masalah; pengunjung ikut
                                    melihat errornya.
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={restoreDefaults}
                        >
                            <RotateCcw /> Kembalikan bawaan
                        </Button>
                        <div className="flex gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={processing}>
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <SlidersHorizontal />
                                )}
                                Simpan
                            </Button>
                        </div>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

export function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export function SizeField({
    id,
    label,
    value,
    sizes,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    sizes: string[];
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <input type="hidden" name={id} value={value} />
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    aria-invalid={error ? true : undefined}
                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="rounded-xl">
                    {sizes.map((size) => (
                        <SelectItem key={size} value={size}>
                            {sizeLabel(size)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

function NumberField({
    id,
    label,
    value,
    min,
    max,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    min: number;
    max: number;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <Input
                id={id}
                name={id}
                type="number"
                inputMode="numeric"
                required
                min={min}
                max={max}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
            />
        </Field>
    );
}
