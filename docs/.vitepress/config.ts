import { defineConfig } from 'vitepress'

const packages = ['core', 'catalog', 'inventory', 'pricing', 'promotions', 'shipping', 'sales', 'testkit', 'full']

export default defineConfig({
  title: 'Commerz',
  description: 'Embeddable commerce engine for Laravel: headless, transport-agnostic, PostgreSQL-backed.',
  srcExclude: ['discovery/**'],
  cleanUrls: true,
  lastUpdated: false,
  themeConfig: {
    search: { provider: 'local' },
    nav: [
      { text: 'Getting started', link: '/getting-started/introduction' },
      { text: 'Concepts', link: '/concepts/stores-and-channels' },
      { text: 'Packages', link: '/packages/core/' },
      { text: 'How-to', link: '/how-to/configure' },
      { text: 'Operations', link: '/operations/install-and-doctor' },
      { text: 'Reference', link: '/reference/api/' },
      { text: 'Architecture', link: '/architecture/README' },
    ],
    sidebar: {
      '/getting-started/': [
        {
          text: 'Getting started',
          items: [
            { text: 'Introduction', link: '/getting-started/introduction' },
            { text: 'Installation', link: '/getting-started/installation' },
            { text: 'Quick start: shop', link: '/getting-started/quick-start-shop' },
            { text: 'Embedding in a CRM', link: '/getting-started/crm-embedding' },
          ],
        },
      ],
      '/concepts/': [
        {
          text: 'Concepts',
          items: [
            { text: 'Stores and channels', link: '/concepts/stores-and-channels' },
            { text: 'Money and measures', link: '/concepts/money-and-measures' },
            { text: 'Sellables', link: '/concepts/sellables' },
            { text: 'The quote pipeline', link: '/concepts/quotes-pipeline' },
            { text: 'Ports and plugins', link: '/concepts/ports-and-plugins' },
            { text: 'Operations and idempotency', link: '/concepts/operations-and-idempotency' },
            { text: 'Events and outbox', link: '/concepts/events-and-outbox' },
            { text: 'Invariants and locking', link: '/concepts/invariants-and-locking' },
          ],
        },
      ],
      '/packages/': [
        {
          text: 'Packages',
          items: packages.map((name) => ({ text: name, link: `/packages/${name}/` })),
        },
      ],
      '/how-to/': [
        {
          text: 'How-to',
          items: [
            { text: 'Configure Commerz', link: '/how-to/configure' },
            { text: 'Write a plugin', link: '/how-to/write-a-plugin' },
            { text: 'A custom sellable provider', link: '/how-to/custom-sellable-provider' },
            { text: 'A payment gateway', link: '/how-to/payment-gateway' },
            { text: 'A carrier', link: '/how-to/carrier' },
            { text: 'A custom quote stage', link: '/how-to/custom-quote-stage' },
            { text: 'Run the workers', link: '/how-to/run-workers' },
            { text: 'Test with the testkit', link: '/how-to/testing-with-testkit' },
          ],
        },
      ],
      '/operations/': [
        {
          text: 'Operations',
          items: [
            { text: 'Install and doctor', link: '/operations/install-and-doctor' },
            { text: 'Commands', link: '/operations/commands' },
            { text: 'Reconciliation', link: '/operations/reconcile' },
            { text: 'Upgrades', link: '/operations/upgrades' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'Reference',
          items: [
            { text: 'API reference', link: '/reference/api/' },
            { text: 'Artisan commands', link: '/reference/artisan-commands' },
            { text: 'Configuration', link: '/reference/configuration' },
            { text: 'Error codes', link: '/reference/error-codes' },
            { text: 'Events', link: '/reference/events' },
            { text: 'Extension kinds', link: '/reference/extension-kinds' },
            { text: 'Data model', link: '/reference/data-model' },
            { text: 'SemVer policy', link: '/reference/semver-policy' },
            { text: 'Compatibility', link: '/reference/compatibility' },
          ],
        },
        {
          text: 'API by package',
          collapsed: true,
          items: packages
            .filter((name) => name !== 'full')
            .map((name) => ({ text: name, link: `/reference/api/${name}` })),
        },
      ],
      '/architecture/': [
        {
          text: 'Architecture',
          items: [
            { text: 'Overview', link: '/architecture/README' },
            { text: 'Packages', link: '/architecture/packages' },
            { text: 'API conventions', link: '/architecture/api' },
            { text: 'Extension model', link: '/architecture/extension' },
            { text: 'Data conventions', link: '/architecture/data' },
            { text: 'Invariants', link: '/architecture/invariants' },
            { text: 'Decisions', link: '/architecture/decisions' },
          ],
        },
      ],
    },
    socialLinks: [{ icon: 'github', link: 'https://github.com/axiomasoft/commerz' }],
    footer: { message: 'Released under the MIT License.' },
  },
})
