import { describe, expect, it } from 'vite-plus/test';
import {
    cents,
    decimal,
    selectionKey,
    submittedDishes,
} from './guestSelection';

describe('guest decimal display calculations', () => {
    it('combines repeated dishes only when their saved unit prices match', () => {
        const item = {
            menu_item_id: 1,
            name: 'Salad',
            quantity: 1,
            unit_price: '17.00',
            subtotal: '17.00',
        };
        const rows = [
            item,
            { ...item },
            { ...item, unit_price: '19.00', subtotal: '19.00' },
        ];
        expect(submittedDishes(rows)).toEqual([
            { ...item, quantity: 2, subtotal: '34.00' },
            { ...item, unit_price: '19.00', subtotal: '19.00' },
        ]);
        expect(rows[0].quantity).toBe(1);
    });
    it('calculates 0.10 × 3 + 0.20 × 2 without floating-point rounding', () => {
        expect(decimal(cents('0.10') * 3n + cents('0.20') * 2n)).toBe('0.70');
        expect(decimal(0n)).toBe('0.00');
        expect(decimal(cents('8.50') * 2n)).toBe('17.00');
    });
    it('supports totals larger than JavaScript safe integers', () => {
        expect(decimal(cents('9999999999999999.99') * 99n)).toBe(
            '989999999999999999.01',
        );
    });
    it.each(['NaN', '1e3', '-1.00', '1.001', '1', '1.0'])(
        'rejects malformed decimal %s',
        (value) => {
            expect(() => cents(value)).toThrow('Invalid decimal price.');
        },
    );
    it('compares selections regardless of input order', () => {
        const input = [
            { menu_item_id: 2, quantity: 1 },
            { menu_item_id: 1, quantity: 3 },
        ];
        expect(selectionKey(input)).toBe(selectionKey([...input].reverse()));
    });
});
