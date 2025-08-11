tailwind.config = {
  theme: {
    extend: {
      colors: {
        canvas: '#ffffff',
        primary: {
          50: '#eff6ff',
          100: '#dbeafe',
          200: '#bfdbfe',
          300: '#93c5fd',
          400: '#60a5fa',
          500: '#3b82f6',
          600: '#2563eb',
          700: '#1d4ed8',
          800: '#1e40af',
          900: '#1e3a8a'
        },
        ink: {
          50: '#f8fafc',
          100: '#f1f5f9',
          200: '#e2e8f0',
          300: '#cbd5e1',
          400: '#94a3b8',
          500: '#64748b',
          600: '#475569',
          700: '#334155',
          800: '#1e293b',
          900: '#0f172a'
        }
      },
      spacing: {
        'g-1': '0.25rem',
        'g-2': '0.5rem',
        'g-3': '0.75rem',
        'g-4': '1rem',
        'g-5': '1.25rem',
        'g-6': '1.5rem',
        'g-8': '2rem',
        'g-12': '3rem',
        'g-16': '4rem'
      },
      fontSize: {
        display: ['3.75rem', { lineHeight: '1' }],
        h1: ['3rem', { lineHeight: '1.1' }],
        h2: ['2.25rem', { lineHeight: '1.2' }],
        h3: ['1.875rem', { lineHeight: '1.3' }],
        body: ['1rem', { lineHeight: '1.5' }],
        fine: ['0.875rem', { lineHeight: '1.4' }],
        micro: ['0.75rem', { lineHeight: '1.4' }]
      },
      borderRadius: {
        pill: '9999px'
      },
      boxShadow: {
        card: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
        elevate: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)'
      },
      transitionDuration: {
        fast: '150ms',
        base: '200ms'
      },
      transitionTimingFunction: {
        standard: 'cubic-bezier(0.4, 0, 0.2, 1)'
      },
      maxWidth: {
        content: '72rem',
        prose: '60ch'
      },
      backgroundImage: {
        'brand-radial': 'radial-gradient(circle at center, var(--tw-gradient-stops))'
      }
    }
  }
};
