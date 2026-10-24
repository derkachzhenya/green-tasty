export type AuthStep = 'login' | 'register' | 'forgot' | 'verify' | 'reset';
export type CodePurpose = 'registration' | 'recovery';
export type CodeContext = {
    purpose: CodePurpose;
    email: string;
    resend_after: number;
    verified: boolean;
};
