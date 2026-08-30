import '@testing-library/jest-dom';

// Mock Inertia
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { name: 'Test User', timezone: 'UTC' } }, timezone: 'UTC', flash: {} } }),
    router:  { visit: vi.fn(), delete: vi.fn() },
    Head:    ({ title }) => null,
    Link:    ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

// Mock the route() helper (Ziggy)
global.route = (name, params) => `/${name}${params ? '/' + JSON.stringify(params) : ''}`;
