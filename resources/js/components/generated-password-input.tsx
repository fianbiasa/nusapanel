import { Wand2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const lowercase = 'abcdefghijkmnopqrstuvwxyz';
const digits = '23456789';

/** A random index below `count`. */
function randomIndex(count: number): number {
    return crypto.getRandomValues(new Uint32Array(1))[0] % count;
}

/**
 * A random password of 12 characters: upper and lower case letters with
 * three digits, without symbols or look-alikes (0 O, 1 l I). The same rule
 * as the passwords the server generates (App\Support\GeneratedPassword).
 */
export function generatePassword(): string {
    const pick = (alphabet: string) => alphabet[randomIndex(alphabet.length)];
    const characters = [
        pick(uppercase),
        pick(lowercase),
        pick(digits),
        pick(digits),
        pick(digits),
    ];

    while (characters.length < 12) {
        characters.push(pick(uppercase + lowercase));
    }

    for (let i = characters.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [characters[i], characters[j]] = [characters[j], characters[i]];
    }

    return characters.join('');
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
