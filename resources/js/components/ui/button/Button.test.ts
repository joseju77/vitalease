import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Button from './Button.vue';

describe('Button', () => {
    it('renders its slot content without throwing', () => {
        const wrapper = mount(Button, {
            slots: {
                default: 'Click me',
            },
        });

        expect(wrapper.text()).toContain('Click me');
    });
});
