<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { authRequest } from '@/lib/authRequest';
import { validEmail, validName, validPassword } from '@/lib/authValidation';
import { SessionRequestError } from '@/lib/sessionJson';
import type { AuthStep, CodeContext } from '@/types/auth-flow';
import AuthField from './AuthField.vue';
import AuthNotification from './AuthNotification.vue';
import PasswordGuidance from './PasswordGuidance.vue';

const props = defineProps<{
    initialStep: AuthStep;
    challenge: CodeContext | null;
    verificationRequired?: boolean;
}>();
const step = ref<AuthStep>(props.initialStep);
const challenge = ref<CodeContext | null>(props.challenge);
const verificationRequired = ref(props.verificationRequired ?? false);
const pendingRegistration = ref(props.challenge?.purpose === 'registration');
const fields = reactive({
    first_name: '',
    last_name: '',
    email: props.challenge?.email ?? '',
    password: '',
    password_confirmation: '',
    code: '',
});
const errors = reactive<Record<string, string>>({});
const busy = ref(false);
const error = ref('');
const success = ref('');
const clock = ref(Date.now());
const resendAt = ref(Date.now() + (props.challenge?.resend_after ?? 0) * 1000);
const unlockAt = ref(0);
const resendAfter = computed(() =>
    Math.max(0, Math.ceil((resendAt.value - clock.value) / 1000)),
);
const lockedAfter = computed(() =>
    Math.max(0, Math.ceil((unlockAt.value - clock.value) / 1000)),
);
let controller: AbortController | null = null;
let alive = true;
let timer: ReturnType<typeof setInterval> | undefined;
const title = computed(
    () =>
        ({
            login: 'Sign In to Your Account',
            register: 'Create an Account',
            forgot: 'Reset Password',
            verify: 'Enter Verification Code',
            reset: 'Reset Password',
        })[step.value],
);
const canSubmit = computed(() => {
    if (busy.value || (step.value === 'login' && lockedAfter.value))
        return false;
    if (
        step.value === 'register' &&
        pendingRegistration.value &&
        resendAfter.value
    )
        return false;
    if (step.value === 'verify')
        return verificationRequired.value || fields.code.trim().length > 0;
    if (step.value === 'reset')
        return (
            validPassword(fields.password) &&
            fields.password === fields.password_confirmation
        );
    if (!validEmail(fields.email)) return false;
    if (step.value === 'forgot') return true;
    if (step.value === 'login') return fields.password.length > 0;
    return (
        validName(fields.first_name) &&
        validName(fields.last_name) &&
        validPassword(fields.password) &&
        fields.password === fields.password_confirmation
    );
});

function wipeSecrets() {
    fields.password = '';
    fields.password_confirmation = '';
    fields.code = '';
}
function clear(field: string) {
    delete errors[field];
    error.value = '';
    if (field === 'email' || field === 'password') {
        delete errors.email;
        delete errors.password;
    }
}
function validate(field: keyof typeof fields) {
    const value = fields[field];
    if (!value)
        errors[field] =
            field === 'email'
                ? 'Email address is required. Please enter your email to continue.'
                : field === 'password'
                  ? 'Password is required. Please enter your password to continue.'
                  : `${field === 'password_confirmation' ? 'Confirm password' : field.replaceAll('_', ' ')} is required.`;
    else if (
        (field === 'first_name' || field === 'last_name') &&
        !validName(value)
    )
        errors[field] = "Use letters only (A–Z), - or '.";
    else if (field === 'email' && !validEmail(value))
        errors.email =
            'Invalid email address. Please use the format: username@domain.com';
    else if (field === 'password_confirmation' && value !== fields.password)
        errors[field] = 'Confirm password must match new password.';
}
async function focusStep() {
    await nextTick();
    const field =
        step.value === 'register'
            ? 'first_name'
            : verificationRequired.value
              ? 'request-code'
              : step.value === 'verify'
                ? 'code'
                : step.value === 'reset'
                  ? 'password'
                  : 'email';
    document.getElementById(`auth-${field}`)?.focus();
}
function changeStep(next: AuthStep) {
    if (busy.value) return;
    wipeSecrets();
    Object.keys(errors).forEach((key) => delete errors[key]);
    error.value = '';
    success.value = '';
    step.value = next;
    void focusStep();
}
function fail(exception: unknown) {
    if (exception instanceof SessionRequestError) {
        if (exception.status === 422) {
            for (const [field, messages] of Object.entries(exception.errors))
                errors[field] = messages[0] ?? 'Please check this field.';
            if (
                step.value === 'login' &&
                errors.email?.includes('credentials')
            ) {
                delete errors.email;
                errors.password =
                    'Incorrect email or password. Try again or create an account.';
            }
            if (errors.password?.includes('confirmation')) {
                errors.password_confirmation =
                    'Confirm password must match new password.';
                delete errors.password;
            }
            const visible =
                step.value === 'verify'
                    ? ['code']
                    : step.value === 'reset'
                      ? ['password', 'password_confirmation']
                      : step.value === 'forgot'
                        ? ['email']
                        : step.value === 'login'
                          ? ['email', 'password']
                          : [
                                'first_name',
                                'last_name',
                                'email',
                                'password',
                                'password_confirmation',
                            ];
            const hidden = Object.entries(errors).find(
                ([field]) => !visible.includes(field),
            );
            if (hidden) error.value = hidden[1];
        } else if (exception.status === 429) {
            const seconds =
                exception.retryAfter || (step.value === 'login' ? 300 : 60);
            clock.value = Date.now();
            if (step.value === 'login') {
                unlockAt.value = Date.now() + seconds * 1000;
                error.value =
                    exception.reason === 'login-lockout'
                        ? 'Your account is temporarily locked due to multiple failed login attempts. Please try again in 5 minutes.'
                        : `Please wait ${seconds} seconds before trying again.`;
            } else {
                resendAt.value = Date.now() + seconds * 1000;
                error.value = 'Please wait before trying again.';
            }
        } else if ([401, 403, 419].includes(exception.status))
            error.value =
                'Your session has changed or expired. Refresh this page before trying again.';
        else
            error.value =
                'We couldn’t complete your request. Please try again.';
    } else
        error.value =
            'We couldn’t connect. Check your connection and try again.';
}
async function send(resend = false) {
    if (busy.value || (resend ? resendAfter.value > 0 : !canSubmit.value))
        return;
    busy.value = true;
    error.value = '';
    success.value = '';
    controller = new AbortController();
    const current = step.value;
    let url = '';
    let body: Record<string, string> = {};
    if (resend) {
        url = '/auth/code/resend';
        body = { purpose: challenge.value?.purpose ?? 'registration' };
    } else if (current === 'login') {
        url = '/login';
        body = { email: fields.email, password: fields.password };
    } else if (current === 'register') {
        url = pendingRegistration.value ? '/auth/registration' : '/register';
        body = {
            first_name: fields.first_name,
            last_name: fields.last_name,
            email: fields.email,
            password: fields.password,
            password_confirmation: fields.password_confirmation,
        };
    } else if (current === 'forgot') {
        url = '/forgot-password';
        body = { email: fields.email };
    } else if (current === 'verify') {
        url = verificationRequired.value
            ? '/email/verification-notification'
            : '/auth/code/verify';
        body = verificationRequired.value
            ? {}
            : {
                  purpose: challenge.value?.purpose ?? 'registration',
                  code: fields.code,
              };
    } else {
        url = '/reset-password';
        body = {
            password: fields.password,
            password_confirmation: fields.password_confirmation,
        };
    }
    try {
        const result = await authRequest(
            url,
            controller.signal,
            body,
            url === '/auth/registration' ? 'patch' : 'post',
        );
        if (!alive) return;
        if (result.challenge) {
            verificationRequired.value = false;
            challenge.value = result.challenge;
            pendingRegistration.value =
                result.challenge.purpose === 'registration';
            clock.value = Date.now();
            resendAt.value = Date.now() + result.challenge.resend_after * 1000;
        }
        if (result.next === 'authenticated') {
            wipeSecrets();
            router.clearHistory();
            router.visit(result.redirect ?? '/dashboard', { replace: true });
            return;
        }
        busy.value = false;
        if (result.next) changeStep(result.next);
        if (resend || result.next === 'verify')
            success.value =
                current === 'forgot' || challenge.value?.purpose === 'recovery'
                    ? 'Request received. If an account exists for this email, check your inbox for a verification code.'
                    : 'Verification code has been sent to your email.';
        if (current === 'verify' && result.next === 'login') {
            pendingRegistration.value = false;
            challenge.value = null;
            success.value =
                'Your account was created successfully. Please sign in with your details.';
        }
        if (current === 'reset' && result.next === 'login') {
            challenge.value = null;
            success.value = 'Your password was changed successfully.';
        }
    } catch (exception) {
        if (
            alive &&
            !(
                exception instanceof DOMException &&
                exception.name === 'AbortError'
            )
        )
            fail(exception);
    } finally {
        if (alive) busy.value = false;
    }
}
function close(open: boolean) {
    if (open || busy.value) return;
    wipeSecrets();
    router.visit('/', {
        replace: true,
        onFinish: () =>
            document
                .querySelector<HTMLAnchorElement>('a[href="/login"]')
                ?.focus(),
    });
}
function hide() {
    controller?.abort();
    wipeSecrets();
}
function show(event: PageTransitionEvent) {
    if (event.persisted) window.location.reload();
}
onMounted(() => {
    timer = setInterval(() => {
        clock.value = Date.now();
    }, 250);
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', show);
});
onBeforeUnmount(() => {
    alive = false;
    hide();
    if (timer) clearInterval(timer);
    window.removeEventListener('pagehide', hide);
    window.removeEventListener('pageshow', show);
});
</script>

<template>
    <Dialog :open="true" @update:open="close">
        <DialogContent
            class="green-tasty auth-dialog font-[Poppins,sans-serif] text-foreground"
            :show-close-button="false"
            @open-auto-focus.prevent="focusStep"
            @escape-key-down.prevent="close(false)"
            @keydown.esc.stop.prevent="close(false)"
            @interact-outside.prevent
        >
            <AuthNotification
                v-if="success"
                class="auth-toast hidden sm:flex"
                :message="success"
                @dismiss="success = ''"
            />
            <section class="auth-panel relative space-y-8">
                <button
                    type="button"
                    aria-label="Close authentication"
                    class="auth-close"
                    :disabled="busy"
                    @click="close(false)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription class="sr-only"
                    >Secure Green Tasty account access. Close to return to the
                    main page.</DialogDescription
                >
                <AuthNotification
                    v-if="success"
                    class="sm:hidden"
                    :message="success"
                    @dismiss="success = ''"
                />
                <button
                    v-if="step === 'reset' && errors.code"
                    type="button"
                    class="auth-link min-h-11"
                    :disabled="busy"
                    @click="changeStep('forgot')"
                >
                    Request a new verification code
                </button>
                <AuthNotification
                    v-if="error"
                    :message="error"
                    error
                    @dismiss="error = ''"
                />
                <form
                    novalidate
                    class="space-y-8"
                    :aria-busy="busy"
                    @submit.prevent="send()"
                >
                    <div class="space-y-6">
                        <div
                            v-if="step === 'register'"
                            class="grid gap-4 min-[390px]:grid-cols-2"
                        >
                            <AuthField
                                id="auth-first_name"
                                v-model="fields.first_name"
                                label="First Name"
                                autocomplete="given-name"
                                placeholder="e.g. Jonson"
                                :disabled="busy"
                                :error="errors.first_name"
                                maxlength="100"
                                @blur="validate('first_name')"
                                @change="clear('first_name')"
                            />
                            <AuthField
                                id="auth-last_name"
                                v-model="fields.last_name"
                                label="Last Name"
                                autocomplete="family-name"
                                placeholder="e.g. Doe"
                                :disabled="busy"
                                :error="errors.last_name"
                                maxlength="100"
                                @blur="validate('last_name')"
                                @change="clear('last_name')"
                            />
                        </div>
                        <p v-if="step === 'forgot'" class="text-sm leading-6">
                            Enter your email so we can send you a code to verify
                            your account.
                        </p>
                        <template v-if="step === 'verify'">
                            <p class="text-sm leading-6 wrap-anywhere">
                                {{
                                    verificationRequired
                                        ? 'Verify your email to continue. Request a verification code for '
                                        : challenge?.purpose === 'recovery'
                                          ? 'If an account exists, check the inbox for '
                                          : 'The verification code has been sent to your email '
                                }}<strong class="font-semibold">{{
                                    challenge?.email
                                }}</strong
                                >.<span v-if="!verificationRequired">
                                    Please enter the code from the email.</span
                                >
                            </p>
                            <div v-if="!verificationRequired">
                                <AuthField
                                    id="auth-code"
                                    v-model="fields.code"
                                    label="Verification Code"
                                    autocomplete="one-time-code"
                                    autocapitalize="characters"
                                    spellcheck="false"
                                    :disabled="busy"
                                    :error="errors.code"
                                    maxlength="128"
                                    @blur="validate('code')"
                                    @change="clear('code')"
                                />
                                <p class="mt-1 text-right text-xs leading-6">
                                    Didn’t receive yet?
                                    <span
                                        v-if="resendAfter"
                                        role="timer"
                                        aria-live="off"
                                        >Resend in
                                        {{ resendAfter }} seconds</span
                                    ><button
                                        v-else
                                        type="button"
                                        class="auth-link min-h-6"
                                        :disabled="busy"
                                        @click="send(true)"
                                    >
                                        Resend Code
                                    </button>
                                </p>
                            </div>
                        </template>
                        <AuthField
                            v-if="
                                ['login', 'register', 'forgot'].includes(step)
                            "
                            id="auth-email"
                            v-model="fields.email"
                            label="Email"
                            type="email"
                            autocomplete="email"
                            placeholder="e.g. username@domain.com"
                            :disabled="busy"
                            :error="errors.email"
                            maxlength="255"
                            @blur="validate('email')"
                            @change="clear('email')"
                        />
                        <div
                            v-if="['login', 'register', 'reset'].includes(step)"
                        >
                            <AuthField
                                id="auth-password"
                                :key="step + '-password'"
                                v-model="fields.password"
                                :label="
                                    step === 'reset'
                                        ? 'New Password'
                                        : 'Password'
                                "
                                type="password"
                                :strength="
                                    step === 'register' && fields.password
                                        ? validPassword(fields.password)
                                            ? 'Strong'
                                            : 'Weak'
                                        : undefined
                                "
                                :autocomplete="
                                    step === 'login'
                                        ? 'current-password'
                                        : 'new-password'
                                "
                                :disabled="busy"
                                :error="errors.password"
                                :described-by="
                                    step === 'login'
                                        ? undefined
                                        : 'auth-password-rules'
                                "
                                @blur="validate('password')"
                                @change="clear('password')"
                            />
                            <PasswordGuidance
                                v-if="step !== 'login'"
                                id="auth-password-rules"
                                :password="fields.password"
                            />
                            <div v-else class="mt-1 text-right">
                                <button
                                    type="button"
                                    class="auth-link min-h-6 text-sm"
                                    :disabled="busy"
                                    @click="changeStep('forgot')"
                                >
                                    Forgot password?
                                </button>
                            </div>
                        </div>
                        <AuthField
                            v-if="step === 'register' || step === 'reset'"
                            id="auth-password_confirmation"
                            :key="step + '-confirmation'"
                            v-model="fields.password_confirmation"
                            :label="
                                step === 'register'
                                    ? 'Confirm New Password'
                                    : 'Confirm Password'
                            "
                            type="password"
                            autocomplete="new-password"
                            :disabled="busy"
                            :error="errors.password_confirmation"
                            @blur="validate('password_confirmation')"
                            @change="clear('password_confirmation')"
                        />
                    </div>
                    <div class="space-y-4">
                        <p
                            v-if="step === 'register' && pendingRegistration"
                            class="text-sm"
                            role="timer"
                            aria-live="off"
                        >
                            Update your pending account details and request a
                            new code<span v-if="resendAfter">
                                in {{ resendAfter }} seconds</span
                            >. Re-enter your password to continue.
                        </p>
                        <div
                            :class="
                                step === 'forgot' ||
                                (step === 'verify' && !verificationRequired)
                                    ? 'grid grid-cols-2 gap-4'
                                    : ''
                            "
                        >
                            <Button
                                v-if="
                                    step === 'forgot' ||
                                    (step === 'verify' && !verificationRequired)
                                "
                                type="button"
                                variant="outline"
                                class="auth-button"
                                :disabled="busy"
                                @click="
                                    changeStep(
                                        step === 'forgot'
                                            ? 'login'
                                            : challenge?.purpose ===
                                                'registration'
                                              ? 'register'
                                              : 'forgot',
                                    )
                                "
                                >Back</Button
                            >
                            <Button
                                type="submit"
                                :id="
                                    verificationRequired
                                        ? 'auth-request-code'
                                        : undefined
                                "
                                class="auth-button w-full"
                                :disabled="!canSubmit"
                                >{{
                                    busy
                                        ? 'Please wait…'
                                        : step === 'login'
                                          ? 'Sign In'
                                          : step === 'register'
                                            ? 'Create an Account'
                                            : step === 'forgot'
                                              ? 'Request Code'
                                              : step === 'verify'
                                                ? verificationRequired
                                                    ? 'Request Code'
                                                    : 'Confirm'
                                                : 'Reset Password'
                                }}</Button
                            >
                        </div>
                        <p
                            v-if="lockedAfter && step === 'login'"
                            class="text-sm"
                            role="timer"
                            aria-live="off"
                        >
                            Try again in {{ lockedAfter }} seconds.
                        </p>
                        <p v-if="step === 'login'" class="text-xs leading-6">
                            Don’t have an account?
                            <button
                                type="button"
                                class="auth-link"
                                :disabled="busy"
                                @click="changeStep('register')"
                            >
                                Create an Account
                            </button>
                        </p>
                        <p
                            v-else-if="step === 'register'"
                            class="text-xs leading-6"
                        >
                            Already have an account?
                            <button
                                type="button"
                                class="auth-link"
                                :disabled="busy"
                                @click="changeStep('login')"
                            >
                                Sign In
                            </button>
                            instead
                        </p>
                    </div>
                </form>
            </section>
        </DialogContent>
    </Dialog>
</template>
