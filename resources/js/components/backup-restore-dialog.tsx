import { router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';

/** What a backup holds, as the panel read it, or why it could not. */
export type InspectedBackup =
    | {
          name: string;
          created_at: string | null;
          files: boolean;
          email: boolean;
          databases: string[];
          mailboxes: string[];
          error?: undefined;
      }
    | { name: string; error: string };

/** One labelled checkbox. */
export function BackupChoice({
    id,
    checked,
    onChange,
    label,
    hint,
    disabled = false,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    hint?: string;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <div className="grid gap-0.5">
                <Label htmlFor={id} className="font-medium">
                    {label}
                </Label>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </div>
        </div>
    );
}

/** Choose what of a backup goes back into the account. */
export function BackupRestoreDialog({
    name,
    inspected,
    action,
    onClose,
    children,
}: {
    name: string | null;
    inspected: InspectedBackup | null;
    /** Where the choice is posted. */
    action: string | null;
    onClose: () => void;
    /** Shown above the choices, e.g. which account the backup goes into. */
    children?: ReactNode;
}) {
    const [choice, setChoice] = useState<{
        for: string | null;
        files: boolean;
        email: boolean;
        databases: string[];
    }>({ for: null, files: false, email: false, databases: [] });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const backup = inspected && 'files' in inspected ? inspected : null;

    // A newly read backup starts with all it holds chosen.
    if (backup && choice.for !== backup.name) {
        setChoice({
            for: backup.name,
            files: backup.files,
            email: backup.email,
            databases: backup.databases,
        });
    }

    const submit = () => {
        if (!backup || !action) {
            return;
        }

        setProcessing(true);
        router.post(
            action,
            {
                files: choice.files,
                email: choice.email,
                databases: choice.databases,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={name !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="rounded-2xl sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pulihkan backup</DialogTitle>
                    <DialogDescription className="font-mono text-xs">
                        {name}
                    </DialogDescription>
                </DialogHeader>

                {inspected === null ? (
                    <div className="grid gap-3">
                        <Skeleton className="h-5 w-2/3" />
                        <Skeleton className="h-5 w-1/2" />
                        <Skeleton className="h-5 w-3/5" />
                    </div>
                ) : !backup ? (
                    <p className="text-sm text-destructive">
                        {inspected.error}
                    </p>
                ) : (
                    backup && (
                        <div className="grid gap-4">
                            {children}
                            {backup.created_at && (
                                <p className="text-sm text-muted-foreground">
                                    Dibuat {formatDateTime(backup.created_at)}.
                                </p>
                            )}
                            {backup.files && (
                                <BackupChoice
                                    id="restore-files"
                                    checked={choice.files}
                                    onChange={(files) =>
                                        setChoice({ ...choice, files })
                                    }
                                    label="File website dan home"
                                    hint="File di backup menggantikan versinya yang sekarang; file yang dibuat setelah backup tetap ada."
                                />
                            )}
                            {backup.email && (
                                <BackupChoice
                                    id="restore-email"
                                    checked={choice.email}
                                    onChange={(email) =>
                                        setChoice({ ...choice, email })
                                    }
                                    label="Email"
                                    hint={`${backup.mailboxes.length} akun email. Akun email yang sudah dihapus dibuat lagi dengan password lamanya; email yang ada tetap ada.`}
                                />
                            )}
                            {backup.databases.map((database) => (
                                <BackupChoice
                                    key={database}
                                    id={`restore-${database}`}
                                    checked={choice.databases.includes(
                                        database,
                                    )}
                                    onChange={(checked) =>
                                        setChoice({
                                            ...choice,
                                            databases: checked
                                                ? [
                                                      ...choice.databases,
                                                      database,
                                                  ]
                                                : choice.databases.filter(
                                                      (name) =>
                                                          name !== database,
                                                  ),
                                        })
                                    }
                                    label={`Database ${database}`}
                                    hint="Isinya diganti isi backup. Database dan user-nya yang sudah dihapus dibuat lagi."
                                />
                            ))}
                            <InputError
                                message={
                                    errors.backup ??
                                    errors.items ??
                                    errors.databases ??
                                    errors.name
                                }
                            />
                        </div>
                    )
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={!backup || processing}>
                        {processing ? <Spinner /> : <RotateCcw />}
                        Pulihkan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
