import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index, store } from '@/routes/admin/accounts';

export default function AccountsCreate({
    passwordRules,
}: {
    passwordRules: string;
}) {
    return (
        <>
            <Head title="New account" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="New account"
                    description="Create a hosting account. The server sets it up in the background, and the account can sign in once it is active."
                />

                <Form
                    {...store.form()}
                    resetOnError={['password', 'password_confirmation']}
                    className="max-w-xl space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    name="username"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    maxLength={16}
                                    placeholder="e.g. budi"
                                />
                                <p className="text-xs text-muted-foreground">
                                    3 to 16 lowercase letters and digits,
                                    starting with a letter. Also used as the
                                    Linux user name and cannot be changed later.
                                </p>
                                <InputError message={errors.username} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoComplete="off"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="off"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="domain">
                                    Primary domain (optional)
                                </Label>
                                <Input
                                    id="domain"
                                    name="domain"
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    maxLength={253}
                                    placeholder="example.com"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Set up as the account&apos;s first website
                                    once the account is ready. Leave out www.
                                </p>
                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Create account
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AccountsCreate.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: index(),
        },
        {
            title: 'New account',
            href: create(),
        },
    ],
};
