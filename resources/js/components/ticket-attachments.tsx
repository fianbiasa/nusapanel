import { FileText, Paperclip } from 'lucide-react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/format';

/** How many and what kind of files a ticket message may carry. */
export type AttachmentLimits = {
    count: number;
    size_kb: number;
    types: string[];
};

export type TicketAttachment = {
    id: number;
    name: string;
    size: number;
    image: boolean;
    url: string;
};

/**
 * The file field of a ticket message ("attachments[]"), with the first
 * error of the field or of any one file.
 */
export function TicketAttachmentsInput({
    id,
    limits,
    errors,
}: {
    id: string;
    limits: AttachmentLimits;
    errors: Record<string, string>;
}) {
    const error =
        errors.attachments ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('attachments.'),
        )?.[1];

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Lampiran</Label>
            <Input
                id={id}
                name="attachments[]"
                type="file"
                multiple
                accept={limits.types.map((type) => `.${type}`).join(',')}
                aria-invalid={error ? true : undefined}
            />
            <p className="text-xs text-muted-foreground">
                Opsional. Paling banyak {limits.count} file, masing-masing{' '}
                {formatBytes(limits.size_kb * 1024)}: gambar, PDF, teks, CSV,
                atau ZIP.
            </p>
            <InputError message={error} />
        </div>
    );
}

/** The files of a message: pictures as thumbnails, others as links. */
export function TicketAttachmentList({
    attachments,
}: {
    attachments: TicketAttachment[];
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul className="mt-3 flex flex-wrap gap-2">
            {attachments.map((attachment) => (
                <li key={attachment.id}>
                    <a
                        href={attachment.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex max-w-64 items-center gap-2 rounded-xl border bg-background px-2.5 py-2 text-xs hover:bg-muted"
                    >
                        {attachment.image ? (
                            <img
                                src={attachment.url}
                                alt=""
                                loading="lazy"
                                className="size-10 shrink-0 rounded-md object-cover"
                            />
                        ) : (
                            <FileText className="size-5 shrink-0 text-muted-foreground" />
                        )}
                        <span className="min-w-0">
                            <span className="block truncate font-medium">
                                {attachment.name}
                            </span>
                            <span className="flex items-center gap-1 text-muted-foreground">
                                <Paperclip className="size-3" />
                                {formatBytes(attachment.size)}
                            </span>
                        </span>
                    </a>
                </li>
            ))}
        </ul>
    );
}
