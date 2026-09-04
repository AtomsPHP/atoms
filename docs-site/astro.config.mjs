import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
  site: 'https://docs.atomsphp.dev',
  integrations: [
    starlight({
      title: 'Atoms',
      logo: {
        src: './src/assets/atoms-icon.svg',
      },
      favicon: '/favicon-32.png',
      description: 'Persistent PHP objects, deployed to your own Cloudflare account.',
      customCss: ['./src/styles/custom.css'],
      head: [
        { tag: 'link', attrs: { rel: 'icon', href: '/atoms-icon.svg', type: 'image/svg+xml' } },
        { tag: 'link', attrs: { rel: 'icon', href: '/favicon-16.png', sizes: '16x16', type: 'image/png' } },
        { tag: 'link', attrs: { rel: 'apple-touch-icon', href: '/apple-touch-icon-180.png' } },
        { tag: 'link', attrs: { rel: 'preconnect', href: 'https://fonts.googleapis.com' } },
        { tag: 'link', attrs: { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: true } },
        { tag: 'link', attrs: { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Science+Gothic:wght@300..500&family=IBM+Plex+Mono:wght@400;500&display=swap' } },
      ],
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/AtomsPHP/atoms' },
      ],
      editLink: {
        baseUrl: 'https://github.com/AtomsPHP/atoms/edit/main/docs-site/',
      },
      sidebar: [
        { label: 'Start here', items: [
          { label: 'Overview', slug: 'index' },
          { label: 'Install', slug: 'getting-started/install' },
          { label: 'Laravel quickstart', slug: 'getting-started/laravel' },
          { label: 'Symfony quickstart', slug: 'getting-started/symfony' },
          { label: 'Plain PHP quickstart', slug: 'getting-started/plain-php' },
        ]},
        { label: 'Concepts', items: [
          { label: 'The two worlds', slug: 'concepts/two-worlds' },
          { label: 'Lifecycle and persistence', slug: 'concepts/lifecycle' },
          { label: 'Adapter contract', slug: 'concepts/adapters' },
        ]},
        { label: 'Build and operate', items: [
          { label: 'Callbacks', slug: 'guides/callbacks' },
          { label: 'Methods', slug: 'guides/methods' },
          { label: 'Jobs', slug: 'guides/jobs' },
          { label: 'WebSockets and timers', slug: 'guides/websockets-timers' },
          { label: 'Eloquent and the query builder', slug: 'guides/eloquent' },
          { label: 'Deploy', slug: 'guides/deploy' },
          { label: 'Rollback', slug: 'guides/rollback' },
        ]},
        { label: 'Reference', items: [
          { label: 'Compatibility', slug: 'reference/compatibility' },
          { label: 'Limits', slug: 'reference/limits' },
          { label: 'PDO compatibility', slug: 'reference/pdo' },
          { label: 'CLI', slug: 'reference/cli' },
          { label: 'Client calls', slug: 'reference/client' },
          { label: 'Error catalog', slug: 'reference/errors' },
        ]},
      ],
    }),
  ],
});
