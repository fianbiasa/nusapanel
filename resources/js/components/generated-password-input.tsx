import { Wand2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/** A random password of 20 letters and digits, without look-alikes. */
export function generatePassword(): string {
    const alphabet =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const values = crypto.getRandomValues(new Uint32Array(20));

    return Array.from(
        values,
        (value) => alphabet[value % alphabet.length],
    ).join('');
}

/**
 * A password input (field name "password", 12 to 128 characters) with a
 * generator. A generated password is shown, so it can be copied into an
 * application's settings or an FTP program.
 */
export function GeneratedPasswordInput({
    id,
    value,
    onChange,
    required,
    placeholder,
    disabled = false,
    invalid,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    required: boolean;
    placeholder: string;
    disabled?: boolean;
    invalid: boolean;
}) {
    const [isVisible, setIsVisible] = useState(false);

    return (
        <div className="flex gap-2">
            <Input
                id={id}
                name="password"
                type={isVisible ? 'text' : 'password'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                disabled={disabled}
                minLength={12}
                maxLength={128}
                autoComplete="new-password"
                placeholder={placeholder}
                className="font-mono"
                aria-invalid={invalid || undefined}
            />
            <Button
                type="button"
                variant="outline"
                className="h-11 shrink-0"
                disabled={disabled}
                onClick={() => {
                    onChange(generatePassword());
                    setIsVisible(true);
                }}
            >
                <Wand2 /> Buat
            </Button>
        </div>
    );
}
