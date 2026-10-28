import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import BookingDateInput from './BookingDateInput.vue';

let mounted: ReturnType<typeof mountComponent>;
afterEach(() => mounted?.unmount());

function setup(value = '') {
    const update = vi.fn((date: string) =>
        mounted.setProps({ modelValue: date }),
    );
    mounted = mountComponent(BookingDateInput, {
        id: 'date',
        modelValue: value,
        min: '2026-10-27',
        max: '2027-01-25',
        'onUpdate:modelValue': update,
    });
    return {
        update,
        text: findAll(mounted.root, (node) => node.props.type === 'text')[0],
        calendar: findAll(
            mounted.root,
            (node) => node.props.type === 'date',
        )[0],
    };
}

describe('booking date entry independent of browser locale', () => {
    it('shows a fixed English format and sends ISO dates to the booking form', async () => {
        const { text, update } = setup('2026-11-12');
        expect(text.props.value).toBe('12.11.2026');
        expect(text.props.placeholder).toBe('dd.mm.yyyy');
        await fire(text, 'onInput', { target: { value: '20.11.2026' } });
        expect(update).toHaveBeenLastCalledWith('2026-11-20');
        expect(text.props.value).toBe('20.11.2026');
        mounted.setProps({ modelValue: '2026-12-01' });
        await flush();
        expect(text.props.value).toBe('01.12.2026');
    });

    it.each(['31.04.2027', '29.02.2027', '12.11.20'])(
        'clears the previous search date for invalid or partial entry %s',
        async (value) => {
            const { text, update } = setup('2026-11-12');
            await fire(text, 'onInput', { target: { value } });
            await fire(text, 'onBlur');
            expect(update).toHaveBeenLastCalledWith('');
            expect(text.props.value).toBe(value);
            expect(text.props['aria-invalid']).toBe(true);
            expect(textContent(mounted.root)).toContain('dd.mm.yyyy format');
        },
    );

    it('accepts a real leap day without converting the date to the browser timezone', async () => {
        const { text, update } = setup();
        await fire(text, 'onInput', { target: { value: '29.02.2028' } });
        expect(update).toHaveBeenLastCalledWith('2028-02-29');
    });

    it('keeps calendar limits and synchronizes a calendar choice with the visible text', async () => {
        const { text, calendar, update } = setup();
        expect(calendar.props.min).toBe('2026-10-27');
        expect(calendar.props.max).toBe('2027-01-25');
        await fire(calendar, 'onInput', { target: { value: '2026-11-12' } });
        expect(text.props.value).toBe('12.11.2026');
        expect(update).toHaveBeenLastCalledWith('2026-11-12');
    });
});
