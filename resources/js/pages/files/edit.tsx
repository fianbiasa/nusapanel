import { Head, Link, router } from '@inertiajs/react';
import { LanguageDescription } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { Compartment, EditorState } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';
import { EditorView, keymap } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { ArrowLeft, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/hooks/use-appearance';
import { baseName, parentPath, sendJson } from '@/lib/file-manager';
import { formatBytes } from '@/lib/format';
import { index, save } from '@/routes/files';

type Props = {
    path: string;
    content: string;
    version: string;
    size: number;
};

const editorTheme = EditorView.theme({
    '&': { height: '100%', fontSize: '13px' },
    '.cm-scroller': {
        fontFamily:
            'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
    },
});

export default function FilesEdit({ path, content, version, size }: Props) {
    const { resolvedAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const container = useRef<HTMLDivElement>(null);
    const view = useRef<EditorView | null>(null);
    const theme = useRef(new Compartment());
    const language = useRef(new Compartment());
    const currentVersion = useRef(version);
    const saveRef = useRef<() => void>(() => {});
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [savedSize, setSavedSize] = useState(size);
    const folder = parentPath(path);
    const folderUrl =
        folder === '' ? index().url : index({ query: { path: folder } }).url;

    useEffect(() => {
        if (!container.current) {
            return;
        }

        const editor = new EditorView({
            parent: container.current,
            state: EditorState.create({
                doc: content,
                extensions: [
                    basicSetup,
                    keymap.of([
                        {
                            key: 'Mod-s',
                            preventDefault: true,
                            run: () => {
                                saveRef.current();

                                return true;
                            },
                        },
                    ]),
                    editorTheme,
                    theme.current.of(dark ? oneDark : []),
                    language.current.of([]),
                    EditorView.updateListener.of((update) => {
                        if (update.docChanged) {
                            setDirty(true);
                        }
                    }),
                ],
            }),
        });

        view.current = editor;

        // Highlighting for the file's language, loaded only when needed.
        LanguageDescription.matchFilename(languages, baseName(path))
            ?.load()
            .then((support) =>
                editor.dispatch({
                    effects: language.current.reconfigure(support),
                }),
            )
            .catch(() => undefined);

        return () => {
            editor.destroy();
            view.current = null;
        };
        // The editor is created once per file; later props come from saves.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [path]);

    useEffect(() => {
        view.current?.dispatch({
            effects: theme.current.reconfigure(dark ? oneDark : []),
        });
    }, [dark]);

    // Warn before leaving with unsaved changes, in the panel or the browser.
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', beforeUnload);

        const removeRouterListener = router.on('before', (event) => {
            const visit = (event as CustomEvent).detail?.visit;

            if (
                visit?.method === 'get' &&
                !window.confirm(
                    'Perubahan belum disimpan. Tinggalkan halaman ini?',
                )
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeRouterListener();
        };
    }, [dirty]);

    saveRef.current = () => {
        const editor = view.current;

        if (!editor || saving) {
            return;
        }

        setSaving(true);

        sendJson<{ size: number; version: string }>(save().url, 'PUT', {
            path,
            content: editor.state.doc.toString(),
            version: currentVersion.current,
        })
            .then((saved) => {
                currentVersion.current = saved.version;
                setSavedSize(saved.size);
                setDirty(false);
                toast.success('Tersimpan.');
            })
            .catch((failure: Error) => toast.error(failure.message))
            .finally(() => setSaving(false));
    };

    return (
        <>
            <Head title={`Edit ${baseName(path)}`} />

            <PageHeader
                title={baseName(path)}
                description={
                    <span className="font-mono text-sm break-all">
                        {path} · {formatBytes(savedSize)}
                        {dirty ? ' · belum disimpan' : ''}
                    </span>
                }
                actions={
                    <>
                        <Button variant="secondary" asChild>
                            <Link href={folderUrl}>
                                <ArrowLeft />
                                Kembali
                            </Link>
                        </Button>
                        <Button
                            onClick={() => saveRef.current()}
                            disabled={saving || !dirty}
                        >
                            {saving ? <Spinner /> : <Save />}
                            Simpan
                        </Button>
                    </>
                }
            />

            <div
                ref={container}
                className="h-[calc(100vh-15rem)] min-h-[24rem] overflow-hidden rounded-2xl border bg-card shadow-card"
            />
            <p className="text-xs text-muted-foreground">
                Simpan dengan Ctrl+S (⌘S di Mac). Cari dan ganti dengan Ctrl+F.
            </p>
        </>
    );
}
