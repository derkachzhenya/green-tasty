<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, LockKeyhole, UtensilsCrossed } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AuthField from '@/components/auth/AuthField.vue';
import StaffRegistrationModal from '@/components/auth/StaffRegistrationModal.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { authRequest } from '@/lib/authRequest';
import { validEmail } from '@/lib/authValidation';
import { SessionRequestError } from '@/lib/sessionJson';

defineProps<{
    registration: {
        enabled: boolean;
        restaurant: { name: string; address: string } | null;
    };
}>();
const fields = reactive({ email: '', password: '' });
const errors = reactive<Record<string, string>>({});
const busy = ref(false);
const registrationOpen = ref(false);
const message = ref('');
const retryAt = ref(0);
const clock = ref(Date.now());
const retryAfter = computed(() =>
    Math.max(0, Math.ceil((retryAt.value - clock.value) / 1000)),
);
const canSubmit = computed(
    () =>
        !busy.value &&
        !retryAfter.value &&
        validEmail(fields.email) &&
        fields.password.length > 0,
);
let controller: AbortController | undefined;
let timer: ReturnType<typeof setInterval> | undefined;
let alive = true;

function clearErrors() {
    delete errors.email;
    delete errors.password;
    message.value = '';
}

async function signIn() {
    if (!canSubmit.value) return;
    clearErrors();
    busy.value = true;
    controller = new AbortController();
    try {
        const result = await authRequest('/staff/login', controller.signal, {
            email: fields.email.trim(),
            password: fields.password,
        });
        if (!alive) return;
        if (result.next !== 'authenticated' || !result.redirect)
            throw new Error('Missing staff login destination.');
        fields.password = '';
        router.clearHistory();
        router.visit(result.redirect, { replace: true });
    } catch (exception) {
        if (
            !alive ||
            (exception instanceof DOMException &&
                exception.name === 'AbortError')
        )
            return;
        fields.password = '';
        if (exception instanceof SessionRequestError) {
            for (const [field, values] of Object.entries(exception.errors))
                errors[field] = values[0] ?? '';
            if (exception.status === 429) {
                retryAt.value =
                    Date.now() + Math.max(exception.retryAfter, 1) * 1000;
                message.value =
                    'Too many sign-in attempts. Please wait before trying again.';
            } else if (exception.status === 419 || exception.status === 401) {
                message.value =
                    'Your session expired. Refresh this page and try again.';
            } else if (!Object.keys(exception.errors).length) {
                message.value =
                    'Unable to sign in right now. Please try again.';
            }
        } else message.value = 'Unable to sign in right now. Please try again.';
    } finally {
        if (alive) busy.value = false;
    }
}

function hidePassword() {
    fields.password = '';
    controller?.abort();
}
function openRegistration() {
    hidePassword();
    clearErrors();
    registrationOpen.value = true;
}
function restorePage(event: PageTransitionEvent) {
    if (event.persisted) window.location.reload();
}
onMounted(() => {
    timer = setInterval(() => {
        clock.value = Date.now();
    }, 1000);
    window.addEventListener('pagehide', hidePassword);
    window.addEventListener('pageshow', restorePage);
});
onBeforeUnmount(() => {
    alive = false;
    hidePassword();
    if (timer) clearInterval(timer);
    window.removeEventListener('pagehide', hidePassword);
    window.removeEventListener('pageshow', restorePage);
});
</script>

<template>
    <Head title="Staff sign in"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <section class="bg-accent">
        <SiteContainer class="py-8 sm:py-12">
            <div
                class="mb-4 flex items-center gap-2 text-sm font-medium text-primary"
            >
                <UtensilsCrossed class="size-5" aria-hidden="true" />Green Tasty
                team
            </div>
            <h1 class="text-3xl font-medium sm:text-4xl">Staff sign in</h1>
            <p
                class="mt-3 text-sm leading-6 text-muted-foreground sm:text-base"
            >
                A separate workspace for restaurant staff.
            </p>
        </SiteContainer>
    </section>
    <SiteContainer
        class="grid items-start gap-8 py-8 lg:grid-cols-2 lg:gap-12 lg:py-12"
    >
        <section
            aria-labelledby="staff-login-heading"
            class="w-full max-w-xl rounded-3xl bg-white p-6 shadow-sm sm:p-8"
        >
            <h2 id="staff-login-heading" class="text-2xl font-medium">
                Welcome back
            </h2>
            <p class="mt-3 text-sm leading-6 text-muted-foreground">
                Enter your staff email and password to open reservations and
                orders for your restaurant.
            </p>
            <form
                class="mt-8 space-y-6"
                novalidate
                :aria-busy="busy"
                @submit.prevent="signIn"
            >
                <p
                    v-if="message"
                    role="alert"
                    class="rounded-lg bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ message }}
                </p>
                <AuthField
                    id="staff-email"
                    v-model="fields.email"
                    label="Staff email"
                    type="email"
                    autocomplete="username"
                    placeholder="Your staff email"
                    maxlength="255"
                    :error="errors.email"
                    :disabled="busy"
                    @change="clearErrors"
                />
                <AuthField
                    id="staff-password"
                    v-model="fields.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    :error="errors.password"
                    :disabled="busy"
                    @change="clearErrors"
                />
                <Button
                    type="submit"
                    class="min-h-12 w-full"
                    :disabled="!canSubmit"
                    >{{ busy ? 'Signing in…' : 'Sign in as staff' }}</Button
                >
                <p
                    v-if="retryAfter"
                    role="timer"
                    aria-live="off"
                    class="text-sm text-muted-foreground"
                >
                    Try again in {{ retryAfter }} seconds.
                </p>
            </form>
            <p class="mt-5 text-sm text-muted-foreground">
                New to the team?
                <button
                    type="button"
                    class="text-primary underline underline-offset-4"
                    :disabled="busy"
                    @click="openRegistration"
                >
                    Create a staff account
                </button>
            </p>
            <p class="mt-5 text-sm leading-6 text-muted-foreground">
                Need access or forgot your password? Contact your restaurant
                administrator.
            </p>
        </section>
        <aside
            class="max-w-xl rounded-3xl border border-primary/10 bg-white/60 p-6 sm:p-8"
        >
            <LockKeyhole class="mb-4 size-7 text-primary" aria-hidden="true" />
            <h2 class="text-xl font-medium">An account for each role</h2>
            <p class="mt-3 text-sm leading-6 text-muted-foreground">
                Create a separate staff account for your restaurant. Customer
                accounts are used to book tables and place pre-orders.
            </p>
            <div class="mt-6 border-t pt-6">
                <h3 class="font-medium">Visiting as a guest?</h3>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    Use the visitor sign-in to manage your own reservations.
                </p>
                <Button as-child variant="outline" class="mt-4 min-h-12"
                    ><Link href="/login">Visitor sign in</Link></Button
                >
            </div>
        </aside>
        <Link
            href="/"
            class="inline-flex min-h-11 w-fit items-center gap-2 text-sm text-primary underline underline-offset-4"
            ><ArrowLeft class="size-4" aria-hidden="true" />Back to main
            page</Link
        >
    </SiteContainer>
    <StaffRegistrationModal
        v-if="registrationOpen"
        :enabled="registration.enabled"
        :restaurant="registration.restaurant"
        @close="registrationOpen = false"
    />
</template>
