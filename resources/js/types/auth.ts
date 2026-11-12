export type Role = 'admin' | 'reseller' | 'user';

export type AccountStatus =
    | 'pending'
    | 'active'
    | 'suspended'
    | 'deleting'
    | 'failed';

export type User = {
    id: number;
    username: string;
    name: string;
    email: string;
    role: Role;
    status: AccountStatus;
    owner_id: number | null;
    last_login_at: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Who signed in as this account ("login as"), while they are. */
    impersonator: { username: string; name: string } | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
