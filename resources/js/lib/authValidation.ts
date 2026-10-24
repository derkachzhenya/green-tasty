export const validName = (name: string) =>
    /^[A-Za-z]+(?:['-][A-Za-z]+)*$/.test(name);
export const validEmail = (email: string) =>
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
export function passwordRequirements(password: string) {
    const length = Array.from(password).length;
    return {
        length: length >= 8 && length <= 16,
        characters:
            /\p{Lu}/u.test(password) &&
            /\p{Ll}/u.test(password) &&
            /\p{N}/u.test(password) &&
            /[\p{S}\p{P}]/u.test(password),
    };
}
export function validPassword(password: string) {
    const requirements = passwordRequirements(password);
    return requirements.length && requirements.characters;
}
