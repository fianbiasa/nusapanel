import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useNavigation } from '@/hooks/use-navigation';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

function hrefOf(item: NavItem): string {
    return typeof item.href === 'string' ? item.href : item.href.url;
}

/**
 * The search box of the top bar: jump to any page of the panel by name.
 * Opens with ⌘K / Ctrl+K.
 */
export function CommandSearch() {
    const { allItems } = useNavigation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(0);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const results = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? allItems
            : allItems.filter((item) =>
                  item.title.toLowerCase().includes(needle),
              );
    }, [allItems, query]);

    const go = (item: NavItem | undefined) => {
        if (!item) {
            return;
        }

        setOpen(false);
        router.visit(hrefOf(item));
    };

    const onOpenChange = (value: boolean) => {
        setOpen(value);
        setQuery('');
        setSelected(0);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => onOpenChange(true)}
                className="flex h-11 w-full max-w-xl items-center gap-3 rounded-xl border bg-card px-4 text-left text-sm text-muted-foreground shadow-card transition-colors hover:border-primary/40"
            >
                <Search className="size-[18px]" />
                <span className="flex-1 truncate">
                    Cari halaman atau menu...
                </span>
                <kbd className="hidden rounded-md border bg-muted px-1.5 py-0.5 text-[11px] font-medium sm:inline">
                    ⌘ K
                </kbd>
            </button>

            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="gap-0 overflow-hidden rounded-2xl p-0 sm:max-w-lg">
                    <DialogTitle className="sr-only">Cari</DialogTitle>
                    <DialogDescription className="sr-only">
                        Ketik nama halaman, lalu tekan Enter.
                    </DialogDescription>
                    <div className="flex items-center gap-3 border-b px-4">
                        <Search className="size-[18px] text-muted-foreground" />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setSelected(0);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'ArrowDown') {
                                    event.preventDefault();
                                    setSelected((index) =>
                                        Math.min(index + 1, results.length - 1),
                                    );
                                } else if (event.key === 'ArrowUp') {
                                    event.preventDefault();
                                    setSelected((index) =>
                                        Math.max(index - 1, 0),
                                    );
                                } else if (event.key === 'Enter') {
                                    event.preventDefault();
                                    go(results[selected]);
                                }
                            }}
                            placeholder="Cari halaman atau menu..."
                            className="h-14 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <ul className="max-h-80 overflow-y-auto p-2">
                        {results.length === 0 && (
                            <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                Tidak ada yang cocok dengan "{query}".
                            </li>
                        )}
                        {results.map((item, index) => (
                            <li key={item.title}>
                                <button
                                    type="button"
                                    onMouseEnter={() => setSelected(index)}
                                    onClick={() => go(item)}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium',
                                        index === selected &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon className="size-[18px]" />
                                    )}
                                    <span className="flex-1">{item.title}</span>
                                    {index === selected && (
                                        <CornerDownLeft className="size-4 text-muted-foreground" />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
